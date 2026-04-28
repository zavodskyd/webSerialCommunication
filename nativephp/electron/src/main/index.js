import {app, BrowserWindow, dialog, session} from 'electron'
import NativePHP from '#plugin'
import {existsSync, readdirSync, readFileSync, statSync, writeFileSync} from 'fs'
import path from 'path'

// Inherit User's PATH in Process & ChildProcess
import fixPath from 'fix-path';
fixPath();

const buildPath = path.resolve(import.meta.dirname, import.meta.env.MAIN_VITE_NATIVEPHP_BUILD_PATH);
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

    session.defaultSession.on('select-serial-port', (event, portList, webContents, callback) => {
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
        const buttons = sortedPorts.map(formatSerialPortLabel);
        const preferredPortIndex = sortedPorts.findIndex(isVotingUsbAdapter);
        const cancelId = buttons.length;
        const selectionDialogOptions = {
            type: 'question',
            title: 'Vyber USB zariadenie',
            message: 'Vyber serial/USB zariadenie pre hlasovanie',
            detail: 'Ak zariadenie nemá očakávaný názov, vyber ho podľa portu, VID/PID alebo popisu.',
            buttons: [...buttons, 'Zrušiť'],
            defaultId: preferredPortIndex >= 0 ? preferredPortIndex : 0,
            cancelId,
            noLink: true,
        };
        const parentWindow = BrowserWindow.fromWebContents(webContents);
        const selectedButtonIndex = parentWindow
            ? dialog.showMessageBoxSync(parentWindow, selectionDialogOptions)
            : dialog.showMessageBoxSync(selectionDialogOptions);
        const selectedPort = selectedButtonIndex === cancelId
            ? null
            : sortedPorts[selectedButtonIndex];

        console.log('[NativePHP] Selected serial port:', selectedPort?.portName ?? selectedPort?.portId ?? 'none');

        callback(selectedPort?.portId ?? '');
    });
};

app.whenReady().then(configureWebSerial);

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
