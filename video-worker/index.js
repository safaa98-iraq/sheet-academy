import {createServer} from 'node:http';
import {timingSafeEqual} from 'node:crypto';
import {Queue, Worker} from 'bullmq';
import IORedis from 'ioredis';
import {processVideo, reportStatus} from './processor.js';
import {promises as fs} from 'node:fs';
import path from 'node:path';

const port = Number(process.env.PORT || 3300);
const serviceToken = process.env.VIDEO_WORKER_TOKEN || '';
const redis = new IORedis(process.env.REDIS_URL || 'redis://127.0.0.1:6379', {maxRetriesPerRequest: null});
const queueName = 'video-processing';
const queue = new Queue(queueName, {connection: redis});
const privateRoot = path.resolve(process.env.PRIVATE_STORAGE_ROOT || '../storage/app/private');

function isAuthorized(request) {
    const provided = Buffer.from(request.headers.authorization?.replace(/^Bearer\s+/i, '') || '');
    const expected = Buffer.from(serviceToken);
    return expected.length > 0 && provided.length === expected.length && timingSafeEqual(provided, expected);
}

async function readJson(request) {
    let body = '';
    for await (const part of request) {
        body += part;
        if (body.length > 32 * 1024) throw new Error('request-too-large');
    }
    return JSON.parse(body || '{}');
}

const server = createServer(async (request, response) => {
    const deleteRendition = request.method === 'DELETE' && request.url?.match(/^\/internal\/videos\/(\d+)\/renditions\/(\d+)$/);
    if (request.method !== 'POST' && !deleteRendition) {
        response.writeHead(404).end();
        return;
    }
    if (!isAuthorized(request)) {
        response.writeHead(401).end();
        return;
    }
    try {
        const job = await readJson(request);
        if (deleteRendition) {
            const videoId = Number(deleteRendition[1]);
            const height = Number(deleteRendition[2]);
            if (!Number.isInteger(videoId) || !Number.isInteger(height) || height < 1 || height > 1080
                || !/^hls\/[0-9]+$/.test(job.output_path || '')) {
                response.writeHead(422, {'content-type': 'application/json'}).end(JSON.stringify({message: 'invalid-rendition'}));
                return;
            }
            const renditionPath = path.resolve(privateRoot, job.output_path, `v${height}`);
            if (!renditionPath.startsWith(`${privateRoot}${path.sep}`)) {
                response.writeHead(422).end();
                return;
            }
            await fs.rm(renditionPath, {recursive: true, force: true});
            response.writeHead(200, {'content-type': 'application/json'}).end(JSON.stringify({video_id: videoId, removed_resolution: height}));
            return;
        }
        if (request.url !== '/internal/jobs') {
            response.writeHead(404).end();
            return;
        }
        if (!/^[a-f0-9-]{36}$/i.test(job.job_id || '') || !Number.isInteger(job.video_id) || !Number.isInteger(job.lesson_id)
            || !/^video-uploads\/[a-f0-9-]{36}\.part$/i.test(job.source_path || '')
            || !/^hls\/[0-9]+$/.test(job.output_path || '')
            || !/^video-keys\/[0-9]+\.key$/.test(job.key_path || '')
            || !Array.isArray(job.enabled_resolutions) || !job.enabled_resolutions.every(height => [240, 360, 480, 720, 1080].includes(height))) {
            response.writeHead(422, {'content-type': 'application/json'}).end(JSON.stringify({message: 'invalid-job'}));
            return;
        }
        await queue.add('transcode', {...job, callback_url: process.env.INTERNAL_CALLBACK_URL}, {
            jobId: job.job_id, attempts: 3, backoff: {type: 'exponential', delay: 5000},
            removeOnComplete: {age: 86400, count: 1000}, removeOnFail: {age: 604800, count: 1000},
        });
        response.writeHead(202, {'content-type': 'application/json'}).end(JSON.stringify({job_id: job.job_id, status: 'queued'}));
    } catch (error) {
        response.writeHead(400, {'content-type': 'application/json'}).end(JSON.stringify({message: error.message}));
    }
});

server.listen(port, '127.0.0.1', () => process.stdout.write(`video worker API listening on 127.0.0.1:${port}\n`));

const worker = new Worker(queueName, async job => processVideo(job.data), {connection: redis, concurrency: Number(process.env.WORKER_CONCURRENCY || 1)});
worker.on('active', job => process.stdout.write(`processing video ${job.data.video_id}\n`));
worker.on('failed', async (job, error) => {
    if (job && job.attemptsMade >= (job.opts.attempts || 1)) {
        await reportStatus(job.data, 'failed', {error: 'تعذّرت معالجة الفيديو بعد المحاولات المتاحة.'}).catch(callbackError => {
            process.stderr.write(`video failure callback failed: ${callbackError.message}\n`);
        });
    }
    process.stderr.write(`video job failed: ${error.message}\n`);
});
worker.on('error', error => process.stderr.write(`video worker error: ${error.message}\n`));

async function shutdown() {
    await worker.close();
    await queue.close();
    await redis.quit();
    server.close(() => process.exit(0));
}
process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
