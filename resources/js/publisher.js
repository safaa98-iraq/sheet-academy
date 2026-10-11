import * as tus from 'tus-js-client';

const form = document.querySelector('[data-simple-publisher]');
if (form) {
    const button = form.querySelector('[data-publish-submit]');
    const message = form.querySelector('[data-publish-status]');
    const progress = form.querySelector('[data-publish-progress]');
    const video = form.querySelector('[data-publish-video]');
    const attachments = form.querySelector('[data-publish-attachments]');
    let busy = false;
    let filesSaved = false;
    let videoSaved = false;
    let savedVideo = null;
    let urls;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const save = async (data) => {
        const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(response.status === 419 ? 'انتهت جلسة الإدارة. أعد تسجيل الدخول ثم استأنف العمل.' : Object.values(result.errors || {}).flat()[0] || result.message || 'تعذّر الحفظ. حاول مجدداً.');
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
        if (!video.files[0] && form.querySelector('[data-video-resolutions]:not([hidden])')) {
            const selected = [...form.querySelectorAll('[data-video-resolution]:checked')];
            if (!selected.length) throw new Error('فعّل دقة واحدة على الأقل للفيديو.');
            selected.forEach(input => data.append('enabled_resolutions[]', input.value));
        }
        if (!filesSaved) [...attachments.files].forEach(file => data.append('attachments[]', file));
        return data;
    };
    video.addEventListener('change', () => {
        savedVideo = null; videoSaved = false;
        form.querySelector('[data-publish-video-name]').textContent = video.files[0]?.name || 'بدون فيديو جديد';
    });
    attachments.addEventListener('change', () => {
        filesSaved = false;
        form.querySelector('[data-publish-attachments-name]').textContent = [...attachments.files].map(file => file.name).join('، ') || 'بدون مرفقات جديدة';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; button.disabled = true; message.classList.remove('has-error');
        let uploaded = false;
        try {
            const file = video.files[0];
            if (file?.size > 10 * 1024 ** 3) throw new Error('الحد الأقصى للفيديو 10 جيجابايت.');
            if (attachments.files.length > 10 || [...attachments.files].some(file => file.size > 20 * 1024 ** 2)) throw new Error('اختر حتى 10 مرفقات، بحجم 20 ميغابايت لكل ملف.');
            const data = prepare();
            const desiredStatus = data.get('publication_status');
            const scheduledAt = data.get('scheduled_at');
            // Keep a new/replaced video private until every selected byte has arrived.
            if (file && !videoSaved) { data.set('publication_status', 'draft'); data.delete('scheduled_at'); }
            message.textContent = 'جارٍ حفظ المحاضرة والمرفقات…';
            urls = await save(data); filesSaved = true;
            if (file && (!videoSaved || savedVideo !== file)) {
                progress.hidden = false;
                video.disabled = true; attachments.disabled = true;
                message.textContent = 'جارٍ رفع الفيديو؛ اترك الصفحة مفتوحة.';
                const edges = new Uint8Array(await file.slice(0, 1048576).arrayBuffer());
                const last = new Uint8Array(await file.slice(Math.max(0, file.size - 1048576)).arrayBuffer());
                const metadata = new TextEncoder().encode(`${file.name}|${file.size}|${file.lastModified}`);
                const source = new Uint8Array(metadata.length + edges.length + last.length); source.set(metadata);source.set(edges, metadata.length);source.set(last, metadata.length+edges.length);
                const fingerprint = [...new Uint8Array(await crypto.subtle.digest('SHA-256', source))].map(byte => byte.toString(16).padStart(2,'0')).join('');
                await new Promise((resolve,reject) => {
                    const upload = new tus.Upload(file, {endpoint: urls.upload_url, fingerprint: async () => `academy:${urls.upload_url}:${fingerprint}`, removeFingerprintOnSuccess: true,
                        chunkSize: 4 * 1024 * 1024, retryDelays: [0,1000,3000,5000,10000],metadata: {filename:file.name,fingerprint},headers: {'X-CSRF-TOKEN':csrf,Accept:'application/json'},
                        onProgress: (sent,total) => { progress.value = Math.floor(sent/total*100); message.textContent = `رفع الفيديو: ${progress.value}٪ — تم حفظ المرفقات`; },
                        onError: () => reject(new Error('تعذّر إكمال رفع الفيديو. اختر الملف نفسه واضغط النشر لاستئنافه؛ المحاضرة والمرفقات محفوظة كمسودة.')),
                        onSuccess: resolve});
                    upload.findPreviousUploads().then(previous => {if(previous.length) upload.resumeFromPreviousUpload(previous[0]);upload.start();}).catch(reject);
                });
                videoSaved = true; savedVideo = file; uploaded = true;
                const final = prepare(); final.delete('attachments[]'); final.set('type','video'); final.set('publication_status',desiredStatus);
                if (scheduledAt) final.set('scheduled_at',scheduledAt);
                message.textContent = 'اكتمل الرفع. جارٍ تأكيد النشر…';
                await save(final);
            }
            // File lectures need no separate upload/save button.
            message.textContent = uploaded ? 'حُفظت المحاضرة والمرفقات. الفيديو قيد التجهيز؛ يظهر للطلاب ويصل التنبيه عند جاهزيته.' : 'تم حفظ المحاضرة والمرفقات بنجاح.';
            form.querySelector('[data-rich-save-status]').textContent = 'كل التغييرات محفوظة';
            form.dispatchEvent(new Event('lecture:saved'));
            busy = false;
            location.assign(urls.edit_url);
            return;
        } catch (error) { message.textContent = error.message || 'تعذّر النشر. المحاضرة المحفوظة متاحة من قائمة المحاضرات.';message.classList.add('has-error'); }
        finally { busy = false;button.disabled = false;video.disabled = false;attachments.disabled = false; }
    });
    window.addEventListener('beforeunload', event => {if(busy){event.preventDefault();event.returnValue='';}});
}

document.querySelectorAll('[data-subscription-fields]').forEach(panel=>{
    const select=panel.querySelector('[data-subscription-duration]');const date=panel.querySelector('[data-subscription-date]');
    const update=()=>{date.hidden=select.value!=='custom';date.querySelector('input').disabled=date.hidden;date.querySelector('input').required=!date.hidden;};select.addEventListener('change',update);update();
});
