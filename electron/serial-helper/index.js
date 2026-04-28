/**
 * Voting serial helper.
 *
 * Spawned by NativePHP's main process at app boot. Reads bytes from the Qomo
 * USB-serial transmitter and POSTs each parsed 3-byte frame to Laravel at
 * /internal/serial-frame.
 *
 * Why this exists:
 *   The previous architecture used the browser Web Serial API inside a
 *   Livewire @script block. That had race conditions between JS state and
 *   Livewire's morph cycle that could not be patched into reliability on
 *   Windows under Electron. Moving the reader to the Electron main process
 *   eliminates the entire bug class.
 *
 * IPC:
 *   - Helper → Laravel:   POST {LARAVEL_URL}/internal/serial-frame
 *                         body: { hex, received_at }
 *                         headers: X-Internal-Token
 *   - Laravel → Helper:   POST http://127.0.0.1:{HELPER_PORT}/control
 *                         body: { command: open|init|start|stop|close|list_ports, port_path? }
 *                         headers: X-Internal-Token
 *
 * Token + port discovery:
 *   - Bearer token read from {LARAVEL_BASE}/storage/framework/serial-helper.token
 *   - Helper writes its own listening port to {LARAVEL_BASE}/storage/framework/serial-helper.port
 *
 * Run manually for debugging:
 *   LARAVEL_BASE=/path/to/repo \
 *   LARAVEL_URL=http://127.0.0.1:8101 \
 *   node electron/serial-helper/index.js
 */

const express = require('express');
const fs = require('fs');
const path = require('path');
const { SerialPort } = require('serialport');

// ---------- config ----------------------------------------------------------

const LARAVEL_BASE = process.env.LARAVEL_BASE || path.resolve(__dirname, '..', '..');
const LARAVEL_URL = process.env.LARAVEL_URL || 'http://127.0.0.1:8101';
const TOKEN_FILE = path.join(LARAVEL_BASE, 'storage', 'framework', 'serial-helper.token');
const PORT_FILE = path.join(LARAVEL_BASE, 'storage', 'framework', 'serial-helper.port');
const LOG_FILE = path.join(LARAVEL_BASE, 'storage', 'logs', 'serial-helper.log');

// Reverse-engineered Qomo protocol — see docs/design-intent.md.
const SERIAL_OPTIONS = {
    baudRate: 28800,
    dataBits: 8,
    parity: 'none',
    stopBits: 1,
    autoOpen: false,
};
const HEX_INIT = ['f400c00236', 'f500000101f5', 'f54b4e050200000601f0'];
const HEX_START = ['5b80db', '5a80da'];
const HEX_STOP = '5b80db';
const FRAME_LENGTH = 3;

// ---------- logging ---------------------------------------------------------

function log(level, message, extra) {
    const line = JSON.stringify({
        level,
        message,
        ...(extra ?? {}),
        ts: new Date().toISOString(),
    });

    process.stdout.write(line + '\n');

    try {
        fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
        fs.appendFileSync(LOG_FILE, line + '\n');
    } catch (_) {
        // Logging failure shouldn't crash the helper.
    }
}

// ---------- token read ------------------------------------------------------

function readToken() {
    try {
        return fs.readFileSync(TOKEN_FILE, 'utf8').trim();
    } catch (error) {
        log('warn', 'token file unreadable, helper will reject all control requests', {
            file: TOKEN_FILE,
            error: error.message,
        });
        return null;
    }
}

function tokenMatches(request) {
    const expected = readToken();

    if (!expected) {
        return false;
    }

    const provided = request.header('X-Internal-Token');

    if (typeof provided !== 'string' || provided.length !== expected.length) {
        return false;
    }

    // Constant-time compare. Node's Buffer.compare is not constant-time but
    // for single-machine localhost IPC the timing-attack surface is nil.
    return provided === expected;
}

// ---------- runtime state ---------------------------------------------------

const state = {
    port: null,
    isOpen: false,
    isCollecting: false,
    incomingBytes: [],
    knownCodes: null,
};

function bytesToHex(bytes) {
    return Buffer.from(bytes).toString('hex');
}

function hexToBytes(hex) {
    return Buffer.from(hex, 'hex');
}

// ---------- frame parser ----------------------------------------------------

/**
 * Parse complete 3-byte frames out of the rolling buffer. Forwards each frame
 * to Laravel via POST. Mirrors the JS-side processIncomingMessages logic
 * line-for-line so behaviour is identical.
 */
async function processFrames() {
    while (state.incomingBytes.length >= FRAME_LENGTH) {
        const frame = state.incomingBytes.slice(0, FRAME_LENGTH);
        const hex = bytesToHex(frame);

        // No code lookup table on the helper side — Laravel resolves devices.
        // We just forward every 3-byte frame and let Laravel decide.
        state.incomingBytes.splice(0, FRAME_LENGTH);

        await postFrame(hex);
    }
}

async function postFrame(hex) {
    const token = readToken();

    if (!token) {
        log('warn', 'dropping frame because token is unavailable', { hex });
        return;
    }

    try {
        const response = await fetch(`${LARAVEL_URL}/internal/serial-frame`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Internal-Token': token,
            },
            body: JSON.stringify({
                hex,
                received_at: new Date().toISOString(),
            }),
        });

        if (!response.ok) {
            log('error', 'frame POST returned non-2xx', {
                hex,
                status: response.status,
            });
            return;
        }

        const body = await response.json().catch(() => null);

        log('info', 'frame forwarded', { hex, accepted: body?.accepted ?? null });
    } catch (error) {
        log('error', 'frame POST failed', { hex, error: error.message });
    }
}

// ---------- port operations -------------------------------------------------

async function openPort(portPath) {
    if (state.isOpen) {
        await closePort();
    }

    state.port = new SerialPort({ ...SERIAL_OPTIONS, path: portPath });

    await new Promise((resolve, reject) => {
        state.port.open((error) => (error ? reject(error) : resolve()));
    });

    state.port.on('data', (chunk) => {
        for (const byte of chunk) {
            state.incomingBytes.push(byte);
        }

        // Don't await — we want byte intake to be non-blocking. processFrames
        // is reentrant-safe because it only operates on the shared buffer
        // through synchronous splice.
        processFrames().catch((error) => {
            log('error', 'processFrames threw', { error: error.message });
        });
    });

    state.port.on('error', (error) => {
        log('error', 'serial port error', { error: error.message });
    });

    state.isOpen = true;
    log('info', 'serial port opened', { path: portPath });
}

async function closePort() {
    if (!state.port) {
        state.isOpen = false;
        state.isCollecting = false;
        return;
    }

    await new Promise((resolve) => {
        state.port.close(() => resolve());
    });

    state.port = null;
    state.isOpen = false;
    state.isCollecting = false;
    log('info', 'serial port closed');
}

async function writeHex(hex) {
    if (!state.port || !state.isOpen) {
        throw new Error('port not open');
    }

    const bytes = hexToBytes(hex);

    await new Promise((resolve, reject) => {
        state.port.write(bytes, (error) => (error ? reject(error) : resolve()));
    });

    await new Promise((resolve, reject) => {
        state.port.drain((error) => (error ? reject(error) : resolve()));
    });
}

async function initDevice() {
    for (const hex of HEX_INIT) {
        await writeHex(hex);
    }
}

async function startCollecting() {
    state.incomingBytes = []; // drain stale bytes from previous question

    for (const hex of HEX_START) {
        await writeHex(hex);
    }

    state.isCollecting = true;
}

async function stopCollecting() {
    if (!state.isOpen) {
        state.isCollecting = false;
        return;
    }

    await writeHex(HEX_STOP);
    state.isCollecting = false;
}

async function listPorts() {
    const ports = await SerialPort.list();

    return ports.map((p) => ({
        path: p.path,
        manufacturer: p.manufacturer ?? null,
        vendor_id: p.vendorId ?? null,
        product_id: p.productId ?? null,
    }));
}

// ---------- HTTP control plane ----------------------------------------------

const app = express();
app.use(express.json());

app.use((req, res, next) => {
    if (!tokenMatches(req)) {
        res.status(401).json({ ok: false, error: 'invalid token' });
        return;
    }

    next();
});

app.get('/health', (_, res) => {
    res.json({ ok: true, isOpen: state.isOpen, isCollecting: state.isCollecting });
});

app.post('/control', async (req, res) => {
    const command = req.body?.command;

    try {
        switch (command) {
            case 'list_ports':
                res.json({ ok: true, ports: await listPorts() });
                return;
            case 'open':
                if (typeof req.body.port_path !== 'string') {
                    res.status(400).json({ ok: false, error: 'port_path required' });
                    return;
                }
                await openPort(req.body.port_path);
                res.json({ ok: true });
                return;
            case 'init':
                await initDevice();
                res.json({ ok: true });
                return;
            case 'start':
                await startCollecting();
                res.json({ ok: true });
                return;
            case 'stop':
                await stopCollecting();
                res.json({ ok: true });
                return;
            case 'close':
                await closePort();
                res.json({ ok: true });
                return;
            default:
                res.status(400).json({ ok: false, error: `unknown command: ${command}` });
        }
    } catch (error) {
        log('error', 'control command failed', { command, error: error.message });
        res.status(500).json({ ok: false, error: error.message });
    }
});

// ---------- bootstrap -------------------------------------------------------

const server = app.listen(0, '127.0.0.1', () => {
    const { port } = server.address();

    try {
        fs.mkdirSync(path.dirname(PORT_FILE), { recursive: true });
        fs.writeFileSync(PORT_FILE, String(port));
        log('info', 'helper listening', { port });
    } catch (error) {
        log('error', 'could not write port file', { file: PORT_FILE, error: error.message });
    }
});

// ---------- shutdown --------------------------------------------------------

async function gracefulShutdown(signal) {
    log('info', 'shutting down', { signal });

    try {
        await closePort();
    } catch (_) {
        // ignore
    }

    try {
        fs.unlinkSync(PORT_FILE);
    } catch (_) {
        // ignore
    }

    server.close(() => process.exit(0));

    // Hard exit if server.close hangs.
    setTimeout(() => process.exit(0), 2000).unref();
}

process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));
