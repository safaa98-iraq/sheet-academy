import {test} from 'node:test';
import assert from 'node:assert/strict';
import {attachmentUploadId, compressAttachment, imageUploadDimensions, retryUpload, uploadForm, uploadPercent, videoUploadError, videoUploadMetadata} from '../../resources/js/publisher-transfer.js';

test('upload percentage follows bytes and reserves 100 until server confirms the save', () => {
    assert.equal(uploadPercent(0, 2000), 0);
    assert.equal(uploadPercent(860, 2000), 43);
    assert.equal(uploadPercent(2000, 2000), 99);
    assert.equal(uploadPercent(2000, 2000, true), 100);
    assert.equal(uploadPercent(0, 0), 0);
});

test('large images keep their aspect ratio and small images are never enlarged', () => {
    assert.deepEqual(imageUploadDimensions(4000, 2000), {width: 3000, height: 1500});
    assert.deepEqual(imageUploadDimensions(1000, 4000), {width: 750, height: 3000});
    assert.deepEqual(imageUploadDimensions(600, 800), {width: 600, height: 800});
});

const originalImage = () => new File([new Uint8Array(400000)], 'slide.png', {type: 'image/png', lastModified: 123});
const imageEnvironment = ({size = 40000, type = 'image/jpeg', fail = false} = {}) => {
    const canvas = {width: 0, height: 0, getContext: () => ({drawImage() {}, getImageData: () => ({data: new Uint8Array([0, 0, 0, 255])})}), toBlob: callback => callback(new Blob([new Uint8Array(size)], {type}))};
    const bitmap = {width: 4000, height: 2000, closed: false, close() {this.closed = true;}};
    return {canvas, bitmap, File, document: {createElement: () => canvas}, createImageBitmap: async () => {if (fail) throw Error('cannot decode'); return bitmap;}};
};
test('compression returns a smaller JPEG with correct filename and frees pixel buffers', async () => {
    const environment = imageEnvironment();
    const compressed = await compressAttachment(originalImage(), environment);
    assert.equal(compressed.size, 40000);
    assert.equal(compressed.type, 'image/jpeg');
    assert.equal(compressed.name, 'slide.jpg');
    assert.equal(compressed.lastModified, 123);
    assert.equal(environment.bitmap.closed, true);
    assert.equal(environment.canvas.width, 0);
});
test('PDF, unsupported encoding, larger output and failed compression retain original bytes', async () => {
    const pdf = new File([new Uint8Array(400000)], 'lecture.pdf', {type: 'application/pdf'});
    assert.equal(await compressAttachment(pdf, imageEnvironment()), pdf);
    const image = originalImage();
    assert.equal(await compressAttachment(image, imageEnvironment({size: 390000})), image);
    assert.equal(await compressAttachment(image, imageEnvironment({type: 'image/png'})), image);
    assert.equal(await compressAttachment(image, imageEnvironment({fail: true})), image);
    assert.equal(await compressAttachment(image, {}), image);
});
test('transparent diagrams keep their original alpha channel', async () => {
    const image = originalImage(); const environment = imageEnvironment();
    environment.canvas.getContext = () => ({drawImage() {}, getImageData: () => ({data: new Uint8Array([0, 0, 0, 0])})});
    assert.equal(await compressAttachment(image, environment), image);
    assert.equal(environment.bitmap.closed, true);
});
test('attachment identity survives reselection and changes for different bytes or names', async () => {
    const file = new File(['abc'], 'lecture.pdf', {type: 'application/pdf'});
    const id = await attachmentUploadId(file);
    assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.equal(await attachmentUploadId(new File(['abc'], 'lecture.pdf')), id);
    assert.notEqual(await attachmentUploadId(new File(['abcd'], 'lecture.pdf')), id);
    assert.notEqual(await attachmentUploadId(new File(['abc'], 'other.pdf')), id);
});
const xhrFixture = ({status = 200, response = '{"message":"saved"}', networkError = false} = {}) => {
    const xhr = {upload: {}, headers: {}, status, responseText: response,
        open(method, url) {this.method = method; this.url = url;},
        setRequestHeader(key, value) {this.headers[key] = value;},
        send(data) {this.data = data; this.upload.onprogress({lengthComputable: true, loaded: 500, total: 1000}); this.upload.onprogress({lengthComputable: false}); networkError ? this.onerror() : this.onload();},
    };
    return xhr;
};
test('form upload reports real progress and preserves CSRF, credentials and slow-connection duration', async () => {
    const xhr = xhrFixture(); const seen = []; const data = new FormData();
    const response = await uploadForm('/admin/lessons', data, {csrf: 'csrf-example', xhrFactory: () => xhr, onProgress: (...values) => seen.push(values)});
    assert.deepEqual(seen, [[500, 1000]]);
    assert.equal(response.message, 'saved');
    assert.equal(xhr.timeout, 0);
    assert.equal(xhr.withCredentials, true);
    assert.equal(xhr.headers['X-CSRF-TOKEN'], 'csrf-example');
    assert.equal(xhr.headers['Content-Type'], undefined);
    assert.equal(xhr.data, data);
});
test('lost connections and server overload retry but validation and expired sessions do not', async () => {
    for (const settings of [{networkError: true}, {status: 429}, {status: 503}]) {
        await assert.rejects(uploadForm('/upload', new FormData(), {xhrFactory: () => xhrFixture(settings)}), error => error.retryable === true);
    }
    await assert.rejects(uploadForm('/upload', new FormData(), {xhrFactory: () => xhrFixture({status: 422, response: '{"errors":{"attachments":["bad MIME"]}}'})}), error => error.message === 'bad MIME' && !error.retryable);
    await assert.rejects(uploadForm('/upload', new FormData(), {xhrFactory: () => xhrFixture({status: 419})}), error => error.message.includes('انتهت جلسة الإدارة') && !error.retryable);
    await assert.rejects(uploadForm('/upload', new FormData(), {xhrFactory: () => xhrFixture({response: '<html>login</html>'})}), /رد السيرفر/);
});
test('bounded backoff reuses the operation and stops immediately on permission failures', async () => {
    let calls = 0; const waits = [];
    const result = await retryUpload(async () => {if (++calls < 3) throw Object.assign(Error('offline'), {retryable: true}); return 'saved';}, {delays: [10, 20], wait: async delay => waits.push(delay)});
    assert.equal(result, 'saved'); assert.equal(calls, 3); assert.deepEqual(waits, [10, 20]);
    calls = 0;
    await assert.rejects(retryUpload(async () => {calls++; throw Error('forbidden');}, {wait: async () => assert.fail('must not retry')}), /forbidden/);
    assert.equal(calls, 1);
    calls = 0;
    await assert.rejects(retryUpload(async () => {calls++; throw Object.assign(Error('offline'), {retryable: true});}, {delays: [10, 20], wait: async () => {}}), /offline/);
    assert.equal(calls, 3);
});

test('TUS session negotiation sends resumable headers with CSRF and no multipart body', async () => {
    const xhr = xhrFixture({response: '{"upload_url":"/admin/video-uploads/id","offset":524288}'});
    const response = await uploadForm('/admin/lessons/1/video-uploads', null, {csrf: 'csrf-example', headers: {'Tus-Resumable': '1.0.0', 'Upload-Length': '30000000'}, xhrFactory: () => xhr});
    assert.equal(response.offset, 524288);
    assert.equal(xhr.headers['Tus-Resumable'], '1.0.0');
    assert.equal(xhr.headers['Upload-Length'], '30000000');
    assert.equal(xhr.headers['X-CSRF-TOKEN'], 'csrf-example');
    assert.equal(xhr.data, null);
});


test('video metadata preserves Arabic filenames as UTF-8 base64', () => {
    const metadata = videoUploadMetadata({name: 'تشريح الأسنان.mp4'}, 'a'.repeat(64));
    const fields = Object.fromEntries(metadata.split(',').map(value => value.split(' ')));
    assert.equal(Buffer.from(fields.filename, 'base64').toString('utf8'), 'تشريح الأسنان.mp4');
    assert.equal(Buffer.from(fields.fingerprint, 'base64').toString('utf8'), 'a'.repeat(64));
});

test('video failure shows the server validation reason and hides HTML and internal failures', () => {
    const response = (status, body) => ({originalResponse: {getStatus: () => status, getBody: () => body}});
    assert.equal(videoUploadError(response(422, '{"errors":{"video":["ملف الفيديو غير صالح."]}}')), 'ملف الفيديو غير صالح.');
    assert.match(videoUploadError(response(419, '{}')), /تسجيل الدخول/);
    assert.match(videoUploadError(response(413, '{}')), /حجم الدفعة/);
    assert.doesNotMatch(videoUploadError(response(500, '{"message":"SQLSTATE: secret"}')), /SQLSTATE|secret/);
    assert.match(videoUploadError(response(502, '<html>Bad gateway</html>')), /آخر دفعة محفوظة/);
});
