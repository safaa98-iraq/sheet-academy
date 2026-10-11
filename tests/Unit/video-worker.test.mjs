import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp, mkdir, writeFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {createServer} from 'node:http';

const root = await mkdtemp(path.join(tmpdir(), 'academy-worker-audit-'));
process.env.PRIVATE_STORAGE_ROOT = root;
process.env.VIDEO_WORKER_TOKEN = 'isolated-worker-test-token';
const {processVideo} = await import('../../video-worker/processor.js');

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
