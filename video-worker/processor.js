import {execFile as execFileCallback} from 'node:child_process';
import {spawn} from 'node:child_process';
import {promises as fs} from 'node:fs';
import path from 'node:path';
import {promisify} from 'node:util';
import {createHash, randomBytes, randomUUID} from 'node:crypto';

const execFile = promisify(execFileCallback);
const root = path.resolve(process.env.PRIVATE_STORAGE_ROOT || '../storage/app/private');
const heights = [1080, 720, 480, 360, 240];
const maxBitrates = {1080: ['5000k', '7500k'], 720: ['2800k', '4200k'], 480: ['1400k', '2100k'], 360: ['800k', '1200k'], 240: ['400k', '600k']};

function safePath(relativePath) {
    const fullPath = path.resolve(root, relativePath);
    if (!fullPath.startsWith(`${root}${path.sep}`)) throw new Error('Path escapes private storage root');
    return fullPath;
}

export async function reportStatus(job, status, details = {}) {
    const token = process.env.VIDEO_WORKER_TOKEN || '';
    const callbackUrl = job.callback_url || process.env.INTERNAL_CALLBACK_URL;
    if (!callbackUrl || !token) throw new Error('Video callback is not configured');
    const response = await fetch(callbackUrl, {
        method: 'POST', headers: {'content-type': 'application/json', authorization: `Bearer ${token}`},
        body: JSON.stringify({video_id: job.video_id, lesson_id: job.lesson_id, job_id: job.job_id, status, ...details}),
        signal: AbortSignal.timeout(8000),
    });
    if (!response.ok) throw new Error(`Callback returned ${response.status}`);
}

async function probe(sourcePath) {
    const {stdout} = await execFile(process.env.FFPROBE_PATH || 'ffprobe', [
        '-v', 'error', '-show_entries', 'stream=codec_type,width,height:format=duration', '-of', 'json', sourcePath,
    ], {maxBuffer: 1024 * 1024});
    const data = JSON.parse(stdout);
    const videoStream = data.streams?.find(stream => stream.codec_type === 'video');
    const width = Number(videoStream?.width);
    const height = Number(videoStream?.height);
    const durationSeconds = Math.floor(Number(data.format?.duration));
    if (!width || !height || !Number.isFinite(durationSeconds) || durationSeconds < 1 || durationSeconds > 86400) {
        throw new Error('Invalid or unsupported video stream');
    }
    return {width, height, durationSeconds, hasAudio: data.streams?.some(stream => stream.codec_type === 'audio') || false};
}

function runFfmpeg(args) {
    return new Promise((resolve, reject) => {
        const child = spawn(process.env.FFMPEG_PATH || 'ffmpeg', args, {stdio: ['ignore', 'ignore', 'pipe']});
        let recentErrorOutput = '';
        child.stderr?.on('data', chunk => {
            recentErrorOutput = `${recentErrorOutput}${chunk.toString()}`.slice(-8192);
        });
        child.on('error', () => reject(new Error('FFmpeg could not be started')));
        child.on('close', code => {
            if (code === 0) resolve();
            else {
                process.stderr.write(`ffmpeg failed: ${recentErrorOutput.slice(-1000)}\n`);
                reject(new Error('FFmpeg transcoding failed'));
            }
        });
    });
}

export async function processVideo(job) {
    const sourcePath = safePath(job.source_path);
    const outputPath = safePath(job.output_path);
    const keyPath = safePath(job.key_path);
    await fs.access(sourcePath);
    await reportStatus(job, 'processing');
    const metadata = await probe(sourcePath);
    const selectedHeights = heights.filter(height => height <= metadata.height && job.enabled_resolutions.includes(height));
    if (selectedHeights.length === 0) selectedHeights.push(Math.max(2, Math.floor(metadata.height / 2) * 2));

    const temporaryOutput = `${outputPath}.tmp-${randomUUID()}`;
    const temporaryKey = `${keyPath}.tmp-${randomUUID()}`;
    const keyInfoPath = safePath(`video-keys/${job.video_id}-${randomUUID()}.keyinfo`);
    await fs.mkdir(path.dirname(temporaryOutput), {recursive: true, mode: 0o700});
    await fs.mkdir(path.dirname(temporaryKey), {recursive: true, mode: 0o700});
    await fs.mkdir(path.dirname(keyInfoPath), {recursive: true, mode: 0o700});
    await fs.mkdir(temporaryOutput, {recursive: true, mode: 0o700});
    const key = randomBytes(16);
    await fs.writeFile(temporaryKey, key, {mode: 0o600});
    await fs.writeFile(keyInfoPath, `key\n${temporaryKey}\n`, {mode: 0o600});

    try {
        for (const height of selectedHeights) {
            const renditionPath = path.join(temporaryOutput, `v${height}`);
            await fs.mkdir(renditionPath, {recursive: true, mode: 0o700});
            const [maxrate, bufsize] = maxBitrates[height] || ['400k', '600k'];
            const width = Math.max(2, Math.floor((metadata.width * height / metadata.height) / 2) * 2);
            await runFfmpeg([
                '-nostdin', '-y', '-i', sourcePath, '-map', '0:v:0', '-map', '0:a:0?', '-vf', `scale=${width}:${height}:flags=lanczos`,
                '-c:v', 'libx264', '-preset', process.env.FFMPEG_PRESET || 'medium', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
                '-b:v', maxrate, '-maxrate', maxrate, '-bufsize', bufsize, '-force_key_frames', 'expr:gte(t,n_forced*4)',
                '-c:a', 'aac', '-b:a', '128k', '-ac', '2', '-f', 'hls', '-hls_time', '4', '-hls_playlist_type', 'vod',
                '-hls_flags', 'independent_segments', '-hls_key_info_file', keyInfoPath,
                '-hls_segment_filename', path.join(renditionPath, 'segment_%05d.ts'), path.join(renditionPath, 'index.m3u8'),
            ]);
        }

        const master = ['#EXTM3U', '#EXT-X-VERSION:3'];
        for (const height of selectedHeights) {
            const width = Math.max(2, Math.floor((metadata.width * height / metadata.height) / 2) * 2);
            const bandwidth = Number((maxBitrates[height]?.[0] || '400k').replace('k', '')) * 1000 + 128000;
            const codecs = metadata.hasAudio ? 'avc1.64001f,mp4a.40.2' : 'avc1.64001f';
            master.push(`#EXT-X-STREAM-INF:BANDWIDTH=${bandwidth},AVERAGE-BANDWIDTH=${Math.floor(bandwidth * 0.8)},RESOLUTION=${width}x${height},CODECS="${codecs}"`);
            master.push(`v${height}/index.m3u8`);
        }
        await fs.writeFile(path.join(temporaryOutput, 'master.m3u8'), `${master.join('\n')}\n`, {mode: 0o600});
        await fs.rm(outputPath, {recursive: true, force: true});
        await fs.rename(temporaryOutput, outputPath);
        await fs.rename(temporaryKey, keyPath);
        const keyFingerprint = createHash('sha256').update(key).digest('hex');
        await fs.writeFile(path.join(path.dirname(keyPath), `${job.video_id}.key.sha256`), keyFingerprint, {mode: 0o600});
        await reportStatus(job, 'ready', {width: metadata.width, height: metadata.height, duration_seconds: metadata.durationSeconds, resolutions: selectedHeights, key_fingerprint: keyFingerprint});
    } catch (error) {
        await fs.rm(temporaryOutput, {recursive: true, force: true});
        await fs.rm(temporaryKey, {force: true});
        throw error;
    } finally {
        await fs.rm(keyInfoPath, {force: true});
    }
}
