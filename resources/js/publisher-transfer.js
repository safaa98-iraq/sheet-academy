export const uploadRetryDelays = [1000, 3000, 5000, 10000, 20000, 30000, 60000];

export function uploadPercent(sent, total, complete = false) {
    if (complete) return 100;
    return total > 0 ? Math.min(99, Math.max(0, Math.floor(sent / total * 100))) : 0;
}

export function imageUploadDimensions(width, height) {
    const scale = Math.min(1, 3000 / Math.max(width, height));
    return {width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale))};
}

export async function compressAttachment(file, environment = globalThis) {
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size < 256 * 1024 || !environment.createImageBitmap) return file;
    let bitmap;
    let canvas;
    try {
        bitmap = await environment.createImageBitmap(file);
        if (bitmap.width * bitmap.height > 25_000_000) return file;
        canvas = environment.document.createElement('canvas');
        const dimensions = imageUploadDimensions(bitmap.width, bitmap.height);
        canvas.width = dimensions.width; canvas.height = dimensions.height;
        const context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        // Keep transparent diagrams intact instead of flattening their background.
        if (file.type !== 'image/jpeg') {
            const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data;
            for (let index = 3; index < pixels.length; index += 4) {if (pixels[index] !== 255) return file;}
        }
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.88));
        if (!blob || blob.type !== 'image/jpeg' || blob.size >= file.size * 0.9) return file;
        return new environment.File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', {type: blob.type, lastModified: file.lastModified});
    } catch {
        // Compression is optional; server MIME and dimension validation still applies.
        return file;
    } finally {
        bitmap?.close();
        if (canvas) {canvas.width = 0; canvas.height = 0;}
    }
}

export async function attachmentUploadId(file) {
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()));
    const name = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(file.name)));
    const identity = new Uint8Array(64); identity.set(digest); identity.set(name, 32);
    const bytes = new Uint8Array(await crypto.subtle.digest('SHA-256', identity));
    bytes[6] = (bytes[6] & 15) | 80; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes.slice(0, 16)].map(byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}

export function uploadForm(url, data, {csrf, onProgress = () => {}, xhrFactory = () => new XMLHttpRequest()} = {}) {
    return new Promise((resolve, reject) => {
        const xhr = xhrFactory();
        xhr.upload.onprogress = event => {if (event.lengthComputable) onProgress(event.loaded, event.total);};
        xhr.open('POST', url);
        xhr.withCredentials = true;
        // No overall timeout: a healthy slow upload may take many minutes.
        xhr.timeout = 0;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        const failure = () => reject(Object.assign(new Error('انقطع الاتصال. سنحاول استكمال الرفع تلقائياً.'), {retryable: true}));
        xhr.onerror = failure; xhr.ontimeout = failure;
        xhr.onabort = () => reject(new Error('توقف الرفع. اضغط النشر لاستكماله.'));
        xhr.onload = () => {
            let result = {};
            try {result = JSON.parse(xhr.responseText);} catch {
                reject(new Error('تعذّر قراءة رد السيرفر. تحقق من الجلسة ثم اضغط النشر لإعادة المحاولة.'));
                return;
            }
            if (xhr.status >= 200 && xhr.status < 300) {resolve(result); return;}
            const message = xhr.status === 419 ? 'انتهت جلسة الإدارة. أعد تسجيل الدخول ثم استأنف العمل.'
                : xhr.status === 413 ? 'الملف أكبر من الحجم المسموح للرفع.'
                : Object.values(result.errors || {}).flat()[0] || result.message || 'تعذّر الحفظ. حاول مجدداً.';
            reject(Object.assign(new Error(message), {retryable: xhr.status === 408 || xhr.status === 429 || xhr.status >= 500}));
        };
        xhr.send(data);
    });
}

export async function retryUpload(operation, {onRetry = () => {}, wait = delay => new Promise(resolve => setTimeout(resolve, delay)), delays = uploadRetryDelays} = {}) {
    for (let attempt = 0; ; attempt++) {
        try {return await operation();} catch (error) {
            if (!error.retryable || attempt >= delays.length) throw error;
            onRetry(attempt + 1, delays[attempt]);
            await wait(delays[attempt]);
        }
    }
}
