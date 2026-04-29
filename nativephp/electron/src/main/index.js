import {app, BrowserWindow, ipcMain, session, utilityProcess} from 'electron'
import NativePHP from '#plugin'
import {appendFileSync, existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync} from 'fs'
import path from 'path'
import crypto from 'crypto'

// Inherit User's PATH in Process & ChildProcess
import fixPath from 'fix-path';
fixPath();

const nativePhpBuildPath = import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH ?? '../../../build';
const buildPath = path.resolve(import.meta.dirname, nativePhpBuildPath);
const defaultIcon = path.join(buildPath, 'icon.png')
const certificate = path.join(buildPath, 'cacert.pem')

const executable = process.platform === 'win32' ? 'php.exe' : 'php';
const phpBinary = path.join(buildPath,'php', executable);
const appPath = path.join(buildPath, 'app')

app.commandLine.appendSwitch('disable-serial-blocklist');
app.commandLine.appendSwitch('disable-features', 'WebBluetooth');

const bluetoothUsageDescription = 'Aplikacia potrebuje pristup k Bluetooth a serial zariadeniam pre USB hlasovacie ovladanie.';

const patchBluetoothUsageDescriptions = (plistPath) => {
    if (!existsSync(plistPath)) {
        return;
    }

    let plist = readFileSync(plistPath, 'utf8');
    let changed = false;

    for (const key of ['NSBluetoothAlwaysUsageDescription', 'NSBluetoothPeripheralUsageDescription']) {
        if (plist.includes(`<key>${key}</key>`)) {
            continue;
        }

        plist = plist.replace(
            '</dict>',
            `\t<key>${key}</key>\n\t<string>${bluetoothUsageDescription}</string>\n</dict>`
        );
        changed = true;
    }

    if (changed) {
        writeFileSync(plistPath, plist);
        console.log(`[NativePHP] Patched Bluetooth usage descriptions in ${plistPath}`);
    }
};

const patchRunningElectronBundle = () => {
    if (process.platform !== 'darwin') {
        return;
    }

    const contentsPath = path.resolve(path.dirname(process.execPath), '..');

    if (!contentsPath.endsWith('Contents') || !existsSync(contentsPath)) {
        return;
    }

    const patchInfoPlistsRecursively = (directory) => {
        for (const file of readdirSync(directory)) {
            const filePath = path.join(directory, file);
            const stats = statSync(filePath);

            if (stats.isDirectory()) {
                patchInfoPlistsRecursively(filePath);

                continue;
            }

            if (file === 'Info.plist') {
                patchBluetoothUsageDescriptions(filePath);
            }
        }
    };

    patchInfoPlistsRecursively(contentsPath);
};

patchRunningElectronBundle();

const isLocalNativePhpOrigin = (origin) => {
    if (!origin) {
        return false;
    }

    try {
        const url = new URL(origin);

        return ['127.0.0.1', 'localhost'].includes(url.hostname);
    } catch {
        return false;
    }
};

const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const showSerialPortPicker = (parentWindow, ports, preferredPortIndex) => new Promise((resolve) => {
    const channel = `nativephp-select-serial-port-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const pickerWindow = new BrowserWindow({
        width: 560,
        height: Math.min(180 + (ports.length * 72), 680),
        parent: parentWindow ?? undefined,
        modal: Boolean(parentWindow),
        show: false,
        resizable: false,
        minimizable: false,
        maximizable: false,
        fullscreenable: false,
        title: 'Vyber USB zariadenie',
        backgroundColor: '#f8fafc',
        webPreferences: {
            contextIsolation: false,
            nodeIntegration: true,
        },
    });
    let selectionHandled = false;
    const finishSelection = (selectedIndex) => {
        if (selectionHandled) {
            return;
        }

        selectionHandled = true;
        ipcMain.removeAllListeners(channel);

        if (!pickerWindow.isDestroyed()) {
            pickerWindow.close();
        }

        resolve(selectedIndex);
    };
    const portButtons = ports.map((port, index) => `
        <button class="port ${index === preferredPortIndex ? 'preferred' : ''}" data-port-index="${index}"${index === preferredPortIndex ? ' autofocus' : ''}>
            <span>${escapeHtml(port.label)}</span>
            ${index === preferredPortIndex ? '<strong>Odporúčané</strong>' : ''}
        </button>
    `).join('');
    const html = `<!doctype html>
<html lang="sk">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f8fafc;
            color: #0f172a;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main { padding: 22px; }
        h1 { margin: 0 0 8px; font-size: 18px; line-height: 1.25; }
        p { margin: 0 0 16px; color: #475569; font-size: 13px; line-height: 1.45; }
        .ports { display: flex; flex-direction: column; gap: 8px; }
        .port, .cancel {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            color: #0f172a;
            cursor: pointer;
            font: inherit;
            padding: 11px 12px;
            text-align: left;
        }
        .port {
            align-items: center;
            display: flex;
            gap: 10px;
            justify-content: space-between;
            min-height: 48px;
        }
        .port:hover, .port:focus { border-color: #2563eb; outline: none; }
        .port span { overflow-wrap: anywhere; }
        .port strong {
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            flex: 0 0 auto;
            font-size: 11px;
            padding: 3px 8px;
        }
        .cancel {
            margin-top: 14px;
            text-align: center;
        }
        .cancel:hover, .cancel:focus { border-color: #64748b; outline: none; }
    </style>
</head>
<body>
    <main>
        <h1>Vyber USB zariadenie</h1>
        <p>Vyber serial/USB zariadenie pre hlasovanie. Ak zariadenie nemá očakávaný názov, použi port, VID/PID alebo popis.</p>
        <section class="ports">${portButtons}</section>
        <button class="cancel" data-cancel>Zrušiť</button>
    </main>
    <script>
        const { ipcRenderer } = require('electron');

        document.addEventListener('click', (event) => {
            const portButton = event.target.closest('[data-port-index]');

            if (portButton) {
                ipcRenderer.send('${channel}', Number(portButton.dataset.portIndex));
            }

            if (event.target.closest('[data-cancel]')) {
                ipcRenderer.send('${channel}', -1);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                ipcRenderer.send('${channel}', -1);
            }
        });
    </script>
</body>
</html>`;

    ipcMain.once(channel, (_event, selectedIndex) => finishSelection(selectedIndex));
    pickerWindow.on('closed', () => finishSelection(-1));
    pickerWindow.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
    pickerWindow.once('ready-to-show', () => {
        pickerWindow.show();
    });
});

const configureWebSerial = () => {
    console.log('[NativePHP] Configuring Web Serial handlers');

    const normalizeDeviceId = (value) => {
        if (typeof value === 'number') {
            return value;
        }

        if (typeof value === 'string' && value.trim() !== '') {
            return Number.parseInt(value, value.toLowerCase().startsWith('0x') ? 16 : 10);
        }

        return null;
    };

    const isVotingUsbAdapter = (port) => {
        const vendorId = normalizeDeviceId(port.vendorId);
        const productId = normalizeDeviceId(port.productId);
        const portName = `${port.portName ?? ''} ${port.displayName ?? ''}`.toLowerCase();

        return (
            vendorId === 0x10C4 && productId === 0xEA60
        ) || (
            vendorId === 0x10C4
        ) || portName.includes('usbserial');
    };

    const formatDeviceId = (value, label) => {
        const normalizedValue = normalizeDeviceId(value);

        if (normalizedValue === null || Number.isNaN(normalizedValue)) {
            return null;
        }

        return `${label} 0x${normalizedValue.toString(16).toUpperCase().padStart(4, '0')}`;
    };

    const formatSerialPortLabel = (port) => {
        const identifiers = [
            formatDeviceId(port.vendorId, 'VID'),
            formatDeviceId(port.productId, 'PID'),
        ].filter(Boolean);
        const names = [
            port.displayName,
            port.portName,
            port.portId,
        ].filter((value, index, values) => value && values.indexOf(value) === index);
        const label = names.length > 0 ? names.join(' | ') : 'Neznáme serial zariadenie';

        return identifiers.length > 0
            ? `${label} (${identifiers.join(', ')})`
            : label;
    };

    session.defaultSession.setPermissionCheckHandler((_webContents, permission, requestingOrigin, details) => {
        if (permission !== 'serial') {
            return false;
        }

        return isLocalNativePhpOrigin(details?.securityOrigin ?? requestingOrigin);
    });

    session.defaultSession.setPermissionRequestHandler((_webContents, permission, callback, details) => {
        if (permission !== 'serial') {
            callback(false);

            return;
        }

        const origin = details?.securityOrigin ?? details?.requestingUrl;
        const allowed = isLocalNativePhpOrigin(origin);

        console.log(`[NativePHP] Serial permission request from ${origin}: ${allowed ? 'allowed' : 'denied'}`);
        callback(allowed);
    });

    session.defaultSession.setDevicePermissionHandler((details) => (
        details.deviceType === 'serial' && isLocalNativePhpOrigin(details.origin)
    ));

    session.defaultSession.on('select-serial-port', async (event, portList, webContents, callback) => {
        event.preventDefault();

        console.log('[NativePHP] Serial ports:', portList.map((port) => ({
            portId: port.portId,
            portName: port.portName,
            displayName: port.displayName,
            vendorId: port.vendorId,
            productId: port.productId,
        })));

        if (portList.length === 0) {
            console.log('[NativePHP] Selected serial port: none');
            callback('');

            return;
        }

        const sortedPorts = [...portList].sort((left, right) => (
            Number(isVotingUsbAdapter(right)) - Number(isVotingUsbAdapter(left))
        ));
        const pickerPorts = sortedPorts.map((port) => ({
            label: formatSerialPortLabel(port),
        }));
        const preferredPortIndex = sortedPorts.findIndex(isVotingUsbAdapter);
        const parentWindow = BrowserWindow.fromWebContents(webContents);
        const selectedPortIndex = await showSerialPortPicker(
            parentWindow,
            pickerPorts,
            preferredPortIndex >= 0 ? preferredPortIndex : 0,
        );
        const selectedPort = selectedPortIndex < 0
            ? null
            : sortedPorts[selectedPortIndex];

        console.log('[NativePHP] Selected serial port:', selectedPort?.portName ?? selectedPort?.portId ?? 'none');

        callback(selectedPort?.portId ?? '');
    });
};

// Spawn the Node serial-helper from Electron main, NOT from PHP. PHP's
// base_path() resolves to resources/build/app/ (Laravel) which doesn't
// contain serial-helper.js — the script lives next to package.json under
// Electron resources (resources/app/). Only Electron knows that path
// reliably (via app.getAppPath()), and only Electron's utilityProcess
// fork has access to the Electron-bundled node_modules where serialport's
// native binary lives. Doing this from PHP cannot work in the packaged
// .exe, regardless of how clever the PHP-side path resolution is.
const spawnSerialHelper = () => {
    // CRITICAL: in packaged NativePHP, Laravel's storage_path() resolves to
    // app.getPath('userData')/storage (NOT base_path/storage). The install
    // dir under Programs/laravel/resources/build/app/ is read-only on Win.
    // Helper must write its token/port/log files into the same userData
    // tree Laravel reads from — otherwise PHP sees an empty storage and
    // helperPort() always returns null.
    const userDataStoragePath = path.join(app.getPath('userData'), 'storage');
    const laravelBase = appPath; // base_path() — read-only install dir
    const debugLogPath = path.join(userDataStoragePath, 'logs', 'serial-helper-spawn.log');

    const debugLog = (msg, extra) => {
        const line = `${new Date().toISOString()} ${msg}` + (extra !== undefined ? ' ' + JSON.stringify(extra) : '') + '\n';
        try {
            mkdirSync(path.dirname(debugLogPath), {recursive: true});
            appendFileSync(debugLogPath, line);
        } catch {
            // ignore — fallback to console
        }
        console.log('[serial-helper]', msg, extra ?? '');
    };

    debugLog('spawnSerialHelper invoked', {
        appPath: app.getAppPath(),
        laravelBase,
        userDataStoragePath,
        userData: app.getPath('userData'),
        execPath: process.execPath,
        resourcesPath: process.resourcesPath,
        platform: process.platform,
        arch: process.arch,
    });

    // .cjs extension forces CommonJS even though package.json has "type": "module".
    // Without this, Node treats the file as ESM and `require()` throws on first line.
    const helperScript = path.join(app.getAppPath(), 'serial-helper.cjs');

    if (!existsSync(helperScript)) {
        debugLog('helper script not found at', {helperScript});
        // Try alternative: outside app.asar (asarUnpack location).
        const unpackedScript = helperScript.replace('app.asar', 'app.asar.unpacked');
        if (existsSync(unpackedScript)) {
            debugLog('found in app.asar.unpacked instead', {unpackedScript});
        } else {
            debugLog('also not found in unpacked location', {unpackedScript});
            return;
        }
    } else {
        debugLog('helper script exists', {helperScript});
    }

    const tokenPath = path.join(userDataStoragePath, 'framework', 'serial-helper.token');
    let token;

    if (existsSync(tokenPath)) {
        token = readFileSync(tokenPath, 'utf8').trim();
        debugLog('reusing existing token file');
    } else {
        token = crypto.randomBytes(32).toString('hex');
        try {
            mkdirSync(path.dirname(tokenPath), {recursive: true});
            writeFileSync(tokenPath, token);
            debugLog('wrote new token file');
        } catch (e) {
            debugLog('failed to write token file', {error: e.message});
        }
    }

    let proc;
    try {
        proc = utilityProcess.fork(helperScript, [], {
            stdio: 'pipe',
            env: {
                ...process.env,
                STORAGE_PATH: userDataStoragePath,
                LARAVEL_BASE: laravelBase,
                LARAVEL_URL: 'http://127.0.0.1:8101',
                SERIAL_HELPER_TOKEN: token,
            },
        });
        debugLog('utilityProcess.fork returned', {pid: proc.pid ?? null});
    } catch (e) {
        debugLog('utilityProcess.fork threw', {error: e.message, stack: e.stack});
        return;
    }

    proc.stdout?.on('data', (chunk) => {
        debugLog('helper stdout', {data: chunk.toString().trim()});
    });
    proc.stderr?.on('data', (chunk) => {
        debugLog('helper stderr', {data: chunk.toString().trim()});
    });
    proc.on('spawn', () => {
        debugLog('helper spawn event', {pid: proc.pid});
    });
    proc.on('exit', (code) => {
        debugLog('helper exited', {code});
    });
    proc.on('error', (err) => {
        debugLog('helper error', {error: err.message, stack: err.stack});
    });

    app.once('before-quit', () => {
        try {
            proc.kill();
        } catch {
            // ignore
        }
    });
};

app.whenReady().then(configureWebSerial);

// spawnSerialHelper() disabled. Reasons (from Dušan's 2026-04-28 testing):
//   1. serialport native binary has Win32 ABI mismatch ("not a valid Win32
//      application"). electron-builder didn't rebuild it for Win during
//      cross-build from Mac. Fixing it requires either a real Win build
//      machine or a serious electron-builder/CI redesign.
//   2. Even when the helper crash-loops it keeps the diagnostic banner
//      flashing in the operator console and adds load on top of an already
//      sluggish packaged app (page-link unresponsive, force-reload required).
//
// Web-serial path (SERIAL_DRIVER=web-serial, default) is the production
// flow and works. The helper architecture stays in the codebase as a
// future option but is not auto-spawned. To re-enable, uncomment the
// next line and ensure the Win serialport prebuild is correctly bundled.
// app.whenReady().then(spawnSerialHelper);

app.on('window-all-closed', () => {
    app.quit();
});

app.once('quit', () => {
    process.exit(0);
});

/**
 * Turn on the lights for the NativePHP app.
 */
NativePHP.bootstrap(
    app,
    defaultIcon,
    phpBinary,
    certificate,
    appPath
);
