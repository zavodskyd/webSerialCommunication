import {app, BrowserWindow, dialog, ipcMain} from 'electron'
import NativePHP from '#plugin'
import {existsSync, readdirSync, readFileSync, statSync, writeFileSync} from 'fs'
import path from 'path'

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

const nativeStartupStatePath = () => path.join(
    app.getPath('userData'),
    'storage',
    'framework',
    'native-startup-state.json',
);

const nativeStartupLogPath = () => path.join(
    app.getPath('userData'),
    'storage',
    'logs',
    'laravel.log',
);

const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const startupStepLabels = {
    'starting-nativephp': 'Spúšťam aplikáciu',
    'resolve-build-version': 'Kontrolujem verziu',
    'load-startup-state': 'Načítavam stav štartu',
    'ensure-database-present': 'Kontrolujem databázu',
    'maybe-run-migrations': 'Aktualizujem databázu',
    'maybe-seed-initial-data': 'Pripravujem úvodné dáta',
    'start-rust-agent': 'Spúšťam serial-agent',
    'start-laravel-serial-bridge': 'Spúšťam serial bridge',
    'mark-startup-ready': 'Dokončujem štart',
};

let startupSplashWindow = null;
let startupSplashPoller = null;

const readNativeStartupState = () => {
    const fallback = {
        current_step: 'starting-nativephp',
        current_status: 'running',
        current_detail: 'Čakám na Laravel bootstrap.',
        log_path: nativeStartupLogPath(),
    };

    try {
        const statePath = nativeStartupStatePath();

        if (!existsSync(statePath)) {
            return fallback;
        }

        const state = JSON.parse(readFileSync(statePath, 'utf8'));

        return {
            ...fallback,
            ...state,
            log_path: nativeStartupLogPath(),
        };
    } catch (error) {
        return {
            ...fallback,
            current_status: 'failed',
            current_detail: `Nepodarilo sa načítať startup state: ${error.message}`,
        };
    }
};

const sendStartupSplashState = () => {
    if (!startupSplashWindow || startupSplashWindow.isDestroyed()) {
        return;
    }

    const state = readNativeStartupState();
    startupSplashWindow.webContents.send('native-startup-state', {
        ...state,
        label: startupStepLabels[state.current_step] ?? state.current_step ?? 'Spúšťam aplikáciu',
    });
};

const closeStartupSplash = () => {
    if (startupSplashPoller) {
        clearInterval(startupSplashPoller);
        startupSplashPoller = null;
    }

    if (startupSplashWindow && !startupSplashWindow.isDestroyed()) {
        startupSplashWindow.close();
    }

    startupSplashWindow = null;
};

const createStartupSplashHtml = () => `<!doctype html>
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
        main {
            display: flex;
            flex-direction: column;
            gap: 18px;
            min-height: 100vh;
            padding: 30px;
        }
        header { display: flex; align-items: center; gap: 12px; }
        .mark {
            align-items: center;
            background: #0f172a;
            border-radius: 8px;
            color: #fff;
            display: flex;
            font-size: 15px;
            font-weight: 700;
            height: 42px;
            justify-content: center;
            width: 42px;
        }
        h1 { font-size: 20px; line-height: 1.2; margin: 0; }
        .status { color: #475569; font-size: 13px; margin-top: 4px; }
        .progress {
            background: #e2e8f0;
            border-radius: 999px;
            height: 8px;
            overflow: hidden;
            width: 100%;
        }
        .bar {
            animation: pulse 1.2s ease-in-out infinite;
            background: #2563eb;
            border-radius: inherit;
            height: 100%;
            width: 46%;
        }
        .detail {
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            color: #334155;
            font-size: 13px;
            line-height: 1.45;
            min-height: 74px;
            overflow-wrap: anywhere;
            padding: 14px;
        }
        footer {
            align-items: center;
            display: none;
            gap: 10px;
            margin-top: auto;
        }
        button {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #0f172a;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            padding: 9px 13px;
        }
        button.primary {
            background: #0f172a;
            border-color: #0f172a;
            color: #fff;
        }
        body.failed .bar { animation: none; background: #dc2626; width: 100%; }
        body.failed footer { display: flex; }
        body.failed .status { color: #991b1b; }
        @keyframes pulse {
            0% { transform: translateX(-55%); }
            50% { transform: translateX(70%); }
            100% { transform: translateX(160%); }
        }
    </style>
</head>
<body>
    <main>
        <header>
            <div class="mark">SC</div>
            <div>
                <h1 id="title">Spúšťam aplikáciu</h1>
                <div id="status" class="status">Pripravujem NativePHP runtime.</div>
            </div>
        </header>
        <div class="progress"><div class="bar"></div></div>
        <section id="detail" class="detail">Čakám na Laravel bootstrap.</section>
        <footer>
            <button class="primary" id="retry">Skúsiť znova</button>
            <button id="close">Zavrieť</button>
        </footer>
    </main>
    <script>
        const { ipcRenderer } = require('electron');
        const title = document.getElementById('title');
        const status = document.getElementById('status');
        const detail = document.getElementById('detail');

        ipcRenderer.on('native-startup-state', (_event, state) => {
            const failed = state.current_status === 'failed';
            document.body.classList.toggle('failed', failed);
            title.textContent = failed ? 'Startup zlyhal' : state.label;
            status.textContent = failed
                ? (state.last_failed_step || state.current_step || 'Neznámy krok')
                : (state.current_status || 'running');
            detail.textContent = failed
                ? [
                    state.last_failed_message || state.current_detail || 'Neznáma chyba.',
                    state.log_path ? 'Log: ' + state.log_path : null,
                ].filter(Boolean).join('\\n')
                : (state.current_detail || state.label || 'Pracujem...');
        });

        document.getElementById('retry').addEventListener('click', () => ipcRenderer.send('native-startup-retry'));
        document.getElementById('close').addEventListener('click', () => ipcRenderer.send('native-startup-close'));
    </script>
</body>
</html>`;

const showNativeStartupSplash = () => {
    if (startupSplashWindow && !startupSplashWindow.isDestroyed()) {
        return;
    }

    startupSplashWindow = new BrowserWindow({
        width: 480,
        height: 290,
        show: false,
        resizable: false,
        minimizable: false,
        maximizable: false,
        fullscreenable: false,
        title: 'Hlasovanie – štart',
        backgroundColor: '#f8fafc',
        webPreferences: {
            contextIsolation: false,
            nodeIntegration: true,
        },
    });

    startupSplashWindow.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(createStartupSplashHtml())}`);
    startupSplashWindow.once('ready-to-show', () => {
        startupSplashWindow.show();
        sendStartupSplashState();
    });

    startupSplashWindow.on('closed', () => {
        if (startupSplashPoller) {
            clearInterval(startupSplashPoller);
            startupSplashPoller = null;
        }

        startupSplashWindow = null;
    });

    startupSplashPoller = setInterval(sendStartupSplashState, 500);
};

ipcMain.on('native-startup-close', () => app.quit());
ipcMain.on('native-startup-retry', () => {
    app.relaunch();
    app.quit();
});

app.on('browser-window-created', (_event, window) => {
    const closeWhenNativePhpWindowLoads = () => {
        if (
            startupSplashWindow
            && window !== startupSplashWindow
            && isLocalNativePhpOrigin(window.webContents.getURL())
        ) {
            closeStartupSplash();
        }
    };

    window.webContents.once('did-finish-load', closeWhenNativePhpWindowLoads);
    window.once('ready-to-show', closeWhenNativePhpWindowLoads);
});

app.on('before-quit', () => {
    if (startupSplashWindow) {
        closeStartupSplash();
    }
});

app.whenReady().then(showNativeStartupSplash);

ipcMain.handle('print-to-pdf', async (event, options = {}) => {
    const sourceWindow = BrowserWindow.fromWebContents(event.sender);

    if (!sourceWindow) {
        throw new Error('Nepodarilo sa nájsť aktuálne okno pre PDF export.');
    }

    const safeFilename = String(options.filename || 'Hlasovanie.pdf')
        .replace(/[\\/:*?"<>|]+/g, '-')
        .replace(/\s+/g, ' ')
        .trim();

    const {canceled, filePath} = await dialog.showSaveDialog(sourceWindow, {
        title: 'Uložiť PDF',
        defaultPath: safeFilename.endsWith('.pdf') ? safeFilename : `${safeFilename}.pdf`,
        filters: [
            {name: 'PDF', extensions: ['pdf']},
        ],
    });

    if (canceled || !filePath) {
        return null;
    }

    const pdf = await sourceWindow.webContents.printToPDF({
        landscape: options.landscape ?? true,
        pageSize: options.pageSize || 'A4',
        printBackground: options.printBackground ?? true,
        margins: {
            marginType: 'default',
        },
    });

    const fs = await import('node:fs/promises');
    await fs.writeFile(filePath, pdf);

    return filePath;
});

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
