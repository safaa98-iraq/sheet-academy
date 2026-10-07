const { app, BrowserWindow, session } = require('electron');
const { autoUpdater } = require('electron-updater');

let bundledOrigin = null;
try { bundledOrigin = require('./academy-origin.cjs'); } catch {}
const configuredOrigin = app.isPackaged ? bundledOrigin : process.env.ACADEMY_ORIGIN || bundledOrigin;
if (!configuredOrigin) throw new Error('Set ACADEMY_ORIGIN and build the desktop app with prepare-build.cjs.');
const academyUrl = new URL(configuredOrigin);

if (academyUrl.protocol !== 'https:' || academyUrl.username || academyUrl.password || academyUrl.pathname !== '/') {
    throw new Error('ACADEMY_ORIGIN must be a trusted HTTPS origin without credentials or a path.');
}

const academyOrigin = academyUrl.origin;

function isAllowedUrl(value) {
    try {
        const candidate = new URL(value);
        return candidate.protocol === 'https:' && candidate.origin === academyOrigin;
    } catch {
        return false;
    }
}

function createWindow() {
    const window = new BrowserWindow({
        width: 1440,
        height: 960,
        minWidth: 900,
        minHeight: 640,
        show: false,
        autoHideMenuBar: true,
        webPreferences: {
            devTools: false,
            nodeIntegration: false,
            contextIsolation: true,
            sandbox: true,
            webSecurity: true,
            allowRunningInsecureContent: false,
            webviewTag: false,
        },
    });

    window.setContentProtection(true);
    window.webContents.setWindowOpenHandler(({ url }) => {
        if (isAllowedUrl(url)) window.loadURL(url).catch(() => {});
        return { action: 'deny' };
    });
    window.webContents.on('will-navigate', (event, url) => {
        if (!isAllowedUrl(url)) event.preventDefault();
    });
    window.webContents.on('will-redirect', (event, url) => {
        if (!isAllowedUrl(url)) event.preventDefault();
    });
    window.webContents.on('before-input-event', (event, input) => {
        if ((input.control || input.meta) && input.shift && ['I', 'J', 'C'].includes(input.key.toUpperCase())) event.preventDefault();
        if (input.key === 'F12') event.preventDefault();
    });
    window.webContents.on('devtools-opened', () => window.webContents.closeDevTools());
    window.once('ready-to-show', () => window.show());
    window.loadURL(academyOrigin);

    return window;
}

app.whenReady().then(() => {
    session.defaultSession.setPermissionRequestHandler((_webContents, _permission, callback) => callback(false));
    session.defaultSession.setPermissionCheckHandler(() => false);
    createWindow();

    if (app.isPackaged) {
        autoUpdater.checkForUpdatesAndNotify().catch(() => {});
    }

    app.on('activate', () => {
        if (BrowserWindow.getAllWindows().length === 0) createWindow();
    });
});

app.on('web-contents-created', (_event, contents) => {
    contents.on('will-attach-webview', event => event.preventDefault());
    contents.on('new-window', event => event.preventDefault());
    contents.on('will-navigate', (event, url) => {
        if (!isAllowedUrl(url)) event.preventDefault();
    });
});

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
});

module.exports = { isAllowedUrl };
