import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, writeFile, readFile, readdir, stat, rm} from 'node:fs/promises';
import {execFile as execFileCallback} from 'node:child_process';
import {promisify} from 'node:util';
import {createDecipheriv} from 'node:crypto';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {createServer} from 'node:http';

const root = await mkdtemp(path.join(tmpdir(), 'academy-worker-audit-'));
process.env.PRIVATE_STORAGE_ROOT = root;
process.env.VIDEO_WORKER_TOKEN = 'isolated-worker-test-token';
const {processVideo, SupersededVideoJobError} = await import('../../video-worker/processor.js');

test('FFprobe refuses network media references and storage traversal', async () => {
    let networkReads = 0;
    const server = createServer((req,res) => {
        if(req.url === '/callback') {req.resume();res.writeHead(200).end('{}');}
        else {networkReads++;res.writeHead(200).end('network media must not be read');}
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const base = `http://127.0.0.1:${server.address().port}`;
    const job = {video_id:1,lesson_id:1,source_path:'video-uploads/input.part',output_path:'hls/1',key_path:'video-keys/1.key',enabled_resolutions:[360],callback_url:base+'/callback'};
    try {
        await mkdir(path.join(root,'video-uploads'));
        await writeFile(path.join(root,job.source_path),`#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:4\n#EXTINF:4,\n${base}/secret.ts\n#EXT-X-ENDLIST\n`);
        await assert.rejects(processVideo(job),/protocol|Invalid data|Invalid or unsupported/i);
        assert.equal(networkReads,0);
        await assert.rejects(processVideo({...job,source_path:'../../outside.mp4'}),/escapes private storage/);
    } finally {await new Promise(resolve=>server.close(resolve));await rm(root,{recursive:true,force:true});}
});

test('lecture compression reduces bytes while all HLS segments remain AES-128 encrypted', async () => {
    const callbacks = [];
    const server = createServer((request, response) => {
        let body = ''; request.on('data', chunk => {body += chunk;});
        request.on('end', () => {callbacks.push(JSON.parse(body)); response.writeHead(200).end('{}');});
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const job = {video_id: 2, lesson_id: 2, source_path: 'video-uploads/source.mkv', output_path: 'hls/2', key_path: 'video-keys/2.key', enabled_resolutions: [360], callback_url: `http://127.0.0.1:${server.address().port}/callback`};
    try {
        await mkdir(path.join(root, 'video-uploads'), {recursive: true});
        await promisify(execFileCallback)('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=15', '-t', '8', '-c:v', 'ffv1', path.join(root, job.source_path)], {timeout: 20000});
        await processVideo(job);
        const key = await readFile(path.join(root, job.key_path));
        assert.equal(key.length, 16);
        const playlist = await readFile(path.join(root, 'hls/2/v360/index.m3u8'), 'utf8');
        assert.match(playlist, /METHOD=AES-128/);
        assert.deepEqual(callbacks.map(callback => callback.status), ['processing', 'processing', 'ready']);
        const iv = Buffer.from(playlist.match(/IV=0x([a-f0-9]{32})/i)[1], 'hex');
        let outputSize = 0;
        for (const name of (await readdir(path.join(root, 'hls/2/v360'))).filter(name => name.endsWith('.ts'))) {
            const segment = await readFile(path.join(root, 'hls/2/v360', name));
            outputSize += segment.length;
            const decipher = createDecipheriv('aes-128-cbc', key, iv);
            const decoded = Buffer.concat([decipher.update(segment), decipher.final()]);
            assert.equal(decoded[0], 0x47);
            assert.equal(decoded.length % 188, 0);
        }
        assert(outputSize < (await stat(path.join(root, job.source_path))).size, 'compressed HLS must be smaller than the lossless source');
    } finally {await new Promise(resolve => server.close(resolve)); await rm(root, {recursive: true, force: true});}
});

test('a replaced processing request stops before probing or publishing video', async () => {
    const server = createServer((request, response) => {request.resume();response.writeHead(409).end('{}');});
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const job = {video_id: 3, lesson_id: 3, source_path: 'video-uploads/replaced.part', output_path: 'hls/3', key_path: 'video-keys/3.key', enabled_resolutions: [360], callback_url: `http://127.0.0.1:${server.address().port}/callback`};
    try {
        await mkdir(path.join(root, 'video-uploads'), {recursive: true});
        await writeFile(path.join(root, job.source_path), 'preserved original source');
        await assert.rejects(processVideo(job), SupersededVideoJobError);
        assert.equal(await readFile(path.join(root, job.source_path), 'utf8'), 'preserved original source');
        await assert.rejects(stat(path.join(root, job.output_path)), {code: 'ENOENT'});
    } finally {await new Promise(resolve => server.close(resolve));await rm(root, {recursive: true, force: true});}
});

test('cancelling an old upload stops its active encoder and preserves the source', async () => {
    const controller = new AbortController();
    const server = createServer((request, response) => {request.resume();response.writeHead(200).end('{}');});
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const job = {video_id: 4, lesson_id: 4, source_path: 'video-uploads/cancelled.mkv', output_path: 'hls/4', key_path: 'video-keys/4.key', enabled_resolutions: [360], callback_url: `http://127.0.0.1:${server.address().port}/callback`};
    try {
        await mkdir(path.join(root, 'video-uploads'), {recursive: true});
        await promisify(execFileCallback)('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=15', '-t', '30', '-c:v', 'ffv1', path.join(root, job.source_path)], {timeout: 20000});
        const pending = processVideo(job, {signal: controller.signal});
        const assertion = assert.rejects(pending, SupersededVideoJobError);
        let temporary;
        for (let i = 0; i < 200; i++) {
            const dirs = await readdir(path.join(root, 'hls')).catch(() => []);
            temporary = dirs.find(name => name.startsWith('4.tmp-'));
            if (temporary && (await readdir(path.join(root, 'hls', temporary))).includes('v360')) break;
            await new Promise(resolve => setTimeout(resolve, 10));
        }
        assert(temporary, 'the encoder must have started');
        controller.abort(new SupersededVideoJobError());
        await assertion;
        assert((await stat(path.join(root, job.source_path))).size > 0);
        assert.deepEqual(await readdir(path.join(root, 'hls')), []);
        assert.deepEqual(await readdir(path.join(root, 'video-keys')), []);
    } finally {await new Promise(resolve => server.close(resolve));await rm(root, {recursive: true, force: true});}
});


test('an upload replaced during encoding cannot overwrite the published output or key', async () => {
    let callbacks = 0;
    const server = createServer((request, response) => {request.resume();response.writeHead(++callbacks === 1 ? 200 : 409).end('{}');});
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const job = {video_id: 5, lesson_id: 5, source_path: 'video-uploads/stale.mkv', output_path: 'hls/5', key_path: 'video-keys/5.key', enabled_resolutions: [360], callback_url: `http://127.0.0.1:${server.address().port}/callback`};
    try {
        await mkdir(path.join(root, 'video-uploads'), {recursive: true});
        await mkdir(path.join(root, job.output_path), {recursive: true});
        await mkdir(path.dirname(path.join(root, job.key_path)), {recursive: true});
        await writeFile(path.join(root, job.output_path, 'master.m3u8'), 'current published playlist');
        await writeFile(path.join(root, job.key_path), 'current protected key');
        await promisify(execFileCallback)('ffmpeg', ['-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=15', '-t', '1', '-c:v', 'ffv1', path.join(root, job.source_path)], {timeout: 20000});
        await assert.rejects(processVideo(job), SupersededVideoJobError);
        assert.equal(await readFile(path.join(root, job.output_path, 'master.m3u8'), 'utf8'), 'current published playlist');
        assert.equal(await readFile(path.join(root, job.key_path), 'utf8'), 'current protected key');
        assert.deepEqual(await readdir(path.join(root, 'hls')), ['5']);
        assert.deepEqual(await readdir(path.join(root, 'video-keys')), ['5.key']);
    } finally {await new Promise(resolve => server.close(resolve));await rm(root, {recursive: true, force: true});}
});
