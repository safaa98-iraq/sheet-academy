const feedValue = process.env.UPDATE_FEED_URL || '';
let feed;
try {
    const url = new URL(feedValue);
    if (url.protocol !== 'https:' || url.username || url.password || url.hostname === 'localhost') throw new Error();
    feed = url.href;
} catch {
    throw new Error('Set UPDATE_FEED_URL to the real HTTPS update feed before packaging.');
}

module.exports = {
    appId: 'com.sheetacademy.desktop',
    productName: 'Sheet Academy',
    asar: true,
    directories: {output: 'dist'},
    files: ['main.cjs', 'academy-origin.cjs', 'package.json', 'node_modules/electron-updater/**/*'],
    publish: [{provider: 'generic', url: feed}],
    win: {target: ['nsis'], signAndEditExecutable: true},
    nsis: {oneClick: false, perMachine: false, allowToChangeInstallationDirectory: true},
    mac: {target: ['dmg', 'zip'], hardenedRuntime: true, gatekeeperAssess: false},
    linux: {target: ['AppImage', 'deb']},
};
