const fs = require('node:fs');
const { URL } = require('node:url');

const value = process.env.ACADEMY_ORIGIN || '';
let origin;
try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password || url.pathname !== '/' || url.search || url.hash || ['example.com', 'example.edu', 'example.invalid', 'localhost'].includes(url.hostname) || url.hostname.endsWith('.example.com') || url.hostname.endsWith('.example.edu')) {
        throw new Error();
    }
    origin = url.origin;
} catch {
    process.stderr.write('ACADEMY_ORIGIN must be the real HTTPS origin, for example https://academy.example.edu\n');
    process.exit(1);
}

fs.writeFileSync('academy-origin.cjs', `module.exports = ${JSON.stringify(origin)};\n`, {mode: 0o600});
