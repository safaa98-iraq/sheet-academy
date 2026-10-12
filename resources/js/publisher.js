import * as tus from 'tus-js-client';
import {attachmentUploadId, compressAttachment, retryUpload, uploadForm, uploadPercent, uploadRetryDelays, videoUploadError, videoUploadMetadata} from './publisher-transfer';

const form = document.querySelector('[data-simple-publisher]');
if (form) {
    const button = form.querySelector('[data-publish-submit]');
    const message = form.querySelector('[data-publish-status]');
    const progress = form.querySelector('[data-publish-progress]');
    const pause = form.querySelector('[data-upload-pause]');
    let activeVideoUpload;
    let paused = false;
    pause.addEventListener('click', async () => {
        if (!activeVideoUpload) return;
        pause.disabled = true;
        try {
            if (paused) {
                paused = false; pause.textContent = 'إيقاف الرفع مؤقتاً'; activeVideoUpload.start();
            } else {
                await activeVideoUpload.abort(); paused = true; pause.textContent = 'استئناف الرفع';
                message.textContent = `الرفع متوقف عند ${progress.value}٪. الدفعات محفوظة؛ اضغط استئناف الرفع للمتابعة.`;
            }
        } finally {pause.disabled = false;}
    });
    const video = form.querySelector('[data-publish-video]');
    const attachments = form.querySelector('[data-publish-attachments]');
    const compression = form.querySelector('[data-compress-images]');
    const compressionSummary = form.querySelector('[data-compression-summary]');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const creationKey = crypto.randomUUID();
    const savedAttachments = new Set();
    let preparedAttachments;
    let busy = false;
    let savedVideo = null;
    let urls;
    const showProgress = (sent, total, label, complete = false) => {
        progress.hidden = false;
        progress.value = uploadPercent(sent, total, complete);
        message.textContent = `${label}: ${progress.value}٪`;
    };
    const transfer = (url, data, onProgress) => retryUpload(() => uploadForm(url, data, {csrf, onProgress}), {
        onRetry: (attempt, delay) => {message.textContent = `الرفع ${progress.value}٪ — الاتصال ضعيف؛ إعادة المحاولة ${attempt} بعد ${delay / 1000} ثانية. اترك الصفحة مفتوحة.`;},
    });
    const save = async (data, onProgress) => {
        const result = await transfer(form.action, data, onProgress);
        form.action = result.update_url;
        if (!form.querySelector('[name="_method"]')) {
            const method = document.createElement('input'); method.type = 'hidden'; method.name = '_method'; method.value = 'PUT'; form.append(method);
        }
        history.replaceState(null, '', result.edit_url);
        return result;
    };
    const prepare = () => {
        form.dispatchEvent(new Event('lecture:sync'));
        const data = new FormData(form);
        data.delete('attachments[]');
        data.set('creation_key', creationKey);
        if (!video.files[0] && form.querySelector('[data-video-resolutions]:not([hidden])')) {
            const selected = [...form.querySelectorAll('[data-video-resolution]:checked')];
            if (!selected.length) throw new Error('فعّل دقة واحدة على الأقل للفيديو.');
            selected.forEach(input => data.append('enabled_resolutions[]', input.value));
        }
        return data;
    };
    const cloneData = data => {const clone = new FormData(); for (const [key, value] of data) clone.append(key, value); return clone;};
    video.addEventListener('change', () => {
        savedVideo = null;
        form.querySelector('[data-publish-video-name]').textContent = video.files[0]?.name || 'بدون فيديو جديد';
    });
    const resetAttachments = () => {preparedAttachments = null; compressionSummary.textContent = '';};
    compression.addEventListener('change', resetAttachments);
    attachments.addEventListener('change', () => {
        resetAttachments();
        form.querySelector('[data-publish-attachments-name]').textContent = [...attachments.files].map(file => file.name).join('، ') || 'بدون مرفقات جديدة';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; message.classList.remove('has-error');
        const controls = [...form.querySelectorAll('input, select, textarea, button')].map(control => [control, control.disabled]);
        const editor = form.querySelector('[data-rich-editor]');
        let uploaded = false;
        try {
            const file = video.files[0];
            const selectedAttachments = [...attachments.files];
            if (file?.size > 10 * 1024 ** 3) throw new Error('الحد الأقصى للفيديو 10 جيجابايت.');
            if (selectedAttachments.length > 10 || selectedAttachments.some(file => file.size > 20 * 1024 ** 2)) throw new Error('اختر حتى 10 مرفقات، بحجم 20 ميغابايت لكل ملف.');
            const content = prepare();
            const compressImages = compression.checked;
            controls.forEach(([control]) => {control.disabled = true;});
            if (editor) editor.contentEditable = 'false';
            if (!preparedAttachments) {
                preparedAttachments = [];
                for (const [index, original] of selectedAttachments.entries()) {
                    showProgress(index, selectedAttachments.length, 'تجهيز وضغط الصور');
                    const optimized = compressImages ? await compressAttachment(original) : original;
                    preparedAttachments.push({file: optimized, id: await attachmentUploadId(optimized)});
                }
                const before = selectedAttachments.reduce((sum, item) => sum + item.size, 0);
                const after = preparedAttachments.reduce((sum, item) => sum + item.file.size, 0);
                compressionSummary.textContent = after < before ? `توفير ${Math.round((1 - after / before) * 100)}٪ من حجم المرفقات قبل الرفع. PDF محفوظ بجودته الأصلية.` : 'المرفقات جاهزة؛ يُحفظ الأصل إذا لم يقل حجمه بالضغط.';
            }
            const total = preparedAttachments.reduce((sum, item) => sum + item.file.size, 0) + (file?.size || 0);
            let completed = preparedAttachments.filter(item => savedAttachments.has(item.id)).reduce((sum, item) => sum + item.file.size, 0) + (savedVideo === file ? file?.size || 0 : 0);
            const initial = cloneData(content);
            // Do not publish or notify until all selected media has been persisted.
            if (total) {initial.set('publication_status', 'draft'); initial.delete('scheduled_at');}
            showProgress(completed, total, total ? 'الرفع الإجمالي' : 'حفظ المحاضرة');
            urls = await save(initial, (sent, bytes) => {if (!total) showProgress(sent, bytes, 'حفظ المحاضرة');});
            for (const [index, item] of preparedAttachments.entries()) {
                if (savedAttachments.has(item.id)) continue;
                const data = new FormData(); data.append('attachments[]', item.file); data.append('client_upload_id', item.id);
                await transfer(urls.attachments_url, data, (sent, bytes) => showProgress(completed + item.file.size * sent / bytes, total, `الرفع الإجمالي — المرفق ${index + 1}/${preparedAttachments.length}`));
                savedAttachments.add(item.id); completed += item.file.size;
                showProgress(completed, total, 'الرفع الإجمالي');
            }
            if (file && savedVideo !== file) {
                const edges = new Uint8Array(await file.slice(0, 1048576).arrayBuffer());
                const last = new Uint8Array(await file.slice(Math.max(0, file.size - 1048576)).arrayBuffer());
                const metadata = new TextEncoder().encode(`${file.name}|${file.size}|${file.lastModified}`);
                const source = new Uint8Array(metadata.length + edges.length + last.length); source.set(metadata);source.set(edges, metadata.length);source.set(last, metadata.length+edges.length);
                const fingerprint = [...new Uint8Array(await crypto.subtle.digest('SHA-256', source))].map(byte => byte.toString(16).padStart(2,'0')).join('');
                const session = await retryUpload(() => uploadForm(urls.upload_url, null, {csrf, headers: {
                    'Tus-Resumable': '1.0.0', 'Upload-Length': String(file.size), 'Upload-Metadata': videoUploadMetadata(file, fingerprint),
                }}), {onRetry: () => {message.textContent = `الرفع ${progress.value}٪ — جارٍ استعادة جلسة رفع الفيديو؛ اترك الصفحة مفتوحة.`;}});
                await new Promise((resolve,reject) => {
                    const upload = new tus.Upload(file, {endpoint: urls.upload_url, uploadUrl: session.upload_url, fingerprint: async () => `academy:${urls.upload_url}:${fingerprint}`, removeFingerprintOnSuccess: true,
                        chunkSize: 512 * 1024, retryDelays: [0, ...uploadRetryDelays], metadata: {filename:file.name,fingerprint}, headers: {'X-CSRF-TOKEN':csrf,Accept:'application/json'},
                        onProgress: (sent,bytes) => {if (!paused) showProgress(completed + sent, total, `الرفع الإجمالي — الفيديو ${Math.floor(sent / bytes * 100)}٪ (${Math.floor(sent / 1024 ** 2)} / ${Math.ceil(bytes / 1024 ** 2)} ميغابايت)`);},
                        onShouldRetry: (error, attempt, options) => {
                            const status = error.originalResponse?.getStatus();
                            const retryable = !status || [408,409,423,429].includes(status) || status >= 500;
                            if (retryable && attempt < options.retryDelays.length) message.textContent = `الرفع ${progress.value}٪ — انقطع اتصال الفيديو؛ جارٍ الاستئناف من آخر دفعة محفوظة.`;
                            return retryable;
                        },
                        onError: error => reject(new Error(videoUploadError(error))),
                        onSuccess: resolve});
                    activeVideoUpload = upload; paused = false; pause.hidden = false; pause.disabled = false; pause.textContent = 'إيقاف الرفع مؤقتاً';
                    upload.start();
                });
                activeVideoUpload = null; pause.hidden = true;
                await transfer(session.complete_url, null);
                savedVideo = file; uploaded = true;
            }
            if (total) {
                const final = cloneData(content); final.set('_method', 'PUT');
                if (file) final.set('type', 'video');
                showProgress(total, total, 'اكتمل إرسال الملفات؛ تأكيد الحفظ');
                await save(final);
            }
            showProgress(1, 1, uploaded ? 'حُفظت الملفات؛ الفيديو قيد الضغط والتجهيز للمشاهدة' : 'اكتمل حفظ المحاضرة والمرفقات', true);
            form.querySelector('[data-rich-save-status]').textContent = 'كل التغييرات محفوظة';
            form.dispatchEvent(new Event('lecture:saved'));
            await new Promise(resolve => setTimeout(resolve, 350));
            busy = false;
            location.assign(urls.edit_url);
        } catch (error) {
            message.textContent = `${error.message || 'تعذّر النشر.'} الملفات المكتملة محفوظة. اضغط النشر لإعادة المحاولة.`;
            message.classList.add('has-error');
            // An interrupted preparation must not discard any remaining selected files.
            if (preparedAttachments?.length !== attachments.files.length) preparedAttachments = null;
        } finally {
            activeVideoUpload = null; pause.hidden = true; paused = false;
            busy = false; controls.forEach(([control, disabled]) => {control.disabled = disabled;});
            if (editor) editor.contentEditable = 'true';
            button.disabled = false;
        }
    });
    window.addEventListener('beforeunload', event => {if(busy){event.preventDefault();event.returnValue='';}});
}

document.querySelectorAll('[data-subscription-fields]').forEach(panel=>{
    const select=panel.querySelector('[data-subscription-duration]');const date=panel.querySelector('[data-subscription-date]');
    const update=()=>{date.hidden=select.value!=='custom';date.querySelector('input').disabled=date.hidden;date.querySelector('input').required=!date.hidden;};select.addEventListener('change',update);update();
});
