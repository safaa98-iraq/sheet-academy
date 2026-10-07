import { getProgress, saveProgress, storageScope } from './progress-store';
import { initPlayers, formatTime } from './player';
import { initViewers } from './viewer';
import { initProtection, setProtection } from './protection';
import { initPwa } from './pwa';
import './admin-ui';
import * as tus from 'tus-js-client';

const qs = (selector, root = document) => root.querySelector(selector);
const qsa = (selector, root = document) => [...root.querySelectorAll(selector)];
const read = (key, fallback) => { try { return JSON.parse(localStorage.getItem(key)) ?? fallback; } catch { return fallback; } };
const write = (key, value) => { try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch { toast('تعذّر حفظ التغييرات على هذا الجهاز.'); return false; } };
let toastTimer;
function toast(message) {
    const element = qs('#toast');
    if (!element) return;
    element.textContent = message;
    element.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => element.classList.remove('show'), 3500);
}
window.academyToast = toast;
document.addEventListener('academy:toast', (event) => toast(event.detail?.message || event.detail));
document.addEventListener('protection:blocked', () => toast('هذا المحتوى مخصص للعرض داخل المنصة.'));
document.addEventListener('academy:storage-unavailable', () => toast('الحفظ المحلي غير متاح. ستبقى تغييراتك خلال هذه الزيارة.'));
document.addEventListener('change', event => {
    const field = event.target instanceof Element ? event.target.closest('[data-submit-on-change]') : null;
    field?.form?.requestSubmit();
});
document.addEventListener('submit', event => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (form?.hasAttribute('data-confirm') && !window.confirm(form.dataset.confirm || 'هل تريد المتابعة؟')) event.preventDefault();
});
document.addEventListener('click', async event => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy-target], [data-print], [data-close-dialog], [data-generate-token]') : null;
    if (!button) return;
    if (button.hasAttribute('data-print')) window.print();
    if (button.hasAttribute('data-close-dialog')) button.closest('dialog')?.close();
    if (button.hasAttribute('data-generate-token')) button.textContent = 'تم إنشاء الطالب · DENT-4K8P-2M9Q';
    const selector = button.dataset.copyTarget;
    if (selector) {
        const text = qs(selector)?.textContent || '';
        try {
            await navigator.clipboard.writeText(text);
            (button.querySelector('[data-copy-label]') || button).textContent = 'تم النسخ';
        } catch {
            toast('تعذّر النسخ. انسخ الرمز يدوياً.');
        }
    }
});

const auditEvent = (event, lessonId = null) => {
    const body = document.body;
    const endpoint = body.dataset.studentActivityUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!endpoint || !csrf || body.dataset.preview === 'true') return Promise.resolve();
    return fetch(endpoint, {
        method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
        body: JSON.stringify({event, lesson_id: lessonId ? Number(lessonId) : null, view: new URL(location.href).searchParams.get('view')}),
    }).then(response => {
        if (response.status === 423) {
            document.dispatchEvent(new CustomEvent('student:session-ended'));
            location.assign('/login');
        }
    }).catch(() => {});
};
window.reportStudentActivity = auditEvent;
document.addEventListener('protection:blocked', event => {
    const action = event.detail?.action;
    if (['copy', 'cut', 'contextmenu', 'shortcut'].includes(action)) auditEvent('suspicious_copy');
    if (action === 'print') auditEvent('suspicious_print');
});
document.addEventListener('protection:devtools', event => { if (event.detail?.suspected) auditEvent('suspicious_devtools'); });
document.addEventListener('protection:watermark-tamper', event => auditEvent('suspicious_watermark', event.detail?.lessonId));

document.addEventListener('click', async event => {
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') return;
    const body = document.body;
    if (body.dataset.preview === 'true' || !body.dataset.viewLinkIssueUrl) return;
    const target = new URL(link.href, location.href);
    if (target.origin !== location.origin) return;
    const lesson = target.pathname.match(/^\/lesson\/(\d+)\/?$/);
    const documentPage = target.pathname.match(/^\/documents\/(\d+)\/?$/);
    if (!lesson && !documentPage) return;
    event.preventDefault();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        const response = await fetch(body.dataset.viewLinkIssueUrl, {
            method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: JSON.stringify({target_type: lesson ? 'lesson' : 'document', target_id: Number((lesson || documentPage)[1])}),
        });
        if (!response.ok) throw new Error('تعذّر إنشاء رابط مشاهدة جديد.');
        location.assign((await response.json()).url);
    } catch (error) {
        toast(error.message || 'تعذّر فتح المحتوى. أعد المحاولة.');
    }
});

window.addEventListener('pagehide', () => {
    const body = document.body;
    const view = new URL(location.href).searchParams.get('view');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (body.dataset.viewLinkCloseUrl && view && csrf && body.dataset.preview !== 'true') {
        fetch(body.dataset.viewLinkCloseUrl, {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({view}),
        }).catch(() => {});
    }
});

const unreadAuditCount = qs('[data-audit-alert-count]');
if (unreadAuditCount) {
    const checkAuditNotifications = async () => {
        try {
            const response = await fetch('/admin/notifications/unread-count', {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!response.ok) return;
            const {count} = await response.json();
            unreadAuditCount.textContent = count > 99 ? '99+' : String(count);
            unreadAuditCount.hidden = count < 1;
        } catch {}
    };
    checkAuditNotifications();
    window.setInterval(checkAuditNotifications, 15000);
}

const setTheme = (theme) => {
    const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.body.classList.toggle('night', dark);
    qsa('[data-theme], [data-theme-toggle]').forEach(button => { button.setAttribute('aria-pressed', String(dark)); button.setAttribute('aria-label', dark ? 'تفعيل الوضع النهاري' : 'تفعيل الوضع الليلي'); });
    try { if (theme === 'system') localStorage.removeItem('academy:theme'); else localStorage.setItem('academy:theme', theme); } catch {}
};
let theme;
try { theme = localStorage.getItem('academy:theme'); } catch {}
setTheme(theme || 'system');
qsa('[data-theme], [data-theme-toggle]').forEach(button => button.addEventListener('click', () => setTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark')));
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { let saved; try { saved = localStorage.getItem('academy:theme'); } catch {} if (!saved) setTheme('system'); });
document.addEventListener('academy:settings', event => { setTheme(event.detail.theme); setProtection(event.detail.protection); });

qsa('[data-tabs]').forEach(group => {
    const tabs = qsa('[data-tab]', group);
    function select(tab, focus = false) {
        tabs.forEach(button => { const active = button === tab; button.classList.toggle('active', active); button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1; });
        qsa('[data-tab-panel]', group).forEach(panel => { panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab; });
        if (focus) tab.focus();
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowLeft' ? 1 : -1) + tabs.length) % tabs.length;
            select(tabs[next], true);
        });
    });
    if (tabs.length) select(tabs.find(tab => tab.classList.contains('active')) || tabs[0]);
});
qsa('[data-modal]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.modal)?.showModal()));
qsa('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
qsa('dialog.modal').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) { const rect = dialog.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close(); } }));
document.addEventListener('click', event => qsa('.dropdown[open]').forEach(dropdown => { if (!dropdown.contains(event.target)) dropdown.open = false; }));
document.addEventListener('keydown', event => { if (event.key === 'Escape') qsa('.dropdown[open]').forEach(dropdown => { dropdown.open = false; qs('summary', dropdown)?.focus(); }); });
let courses = [];
try { courses = JSON.parse(qs('#learning-data')?.textContent || '[]'); } catch {}
for (const course of courses) {
    for (const lesson of course.lessons) {
        if (!getProgress(lesson.id).updatedAt) saveProgress(lesson.id, { position: lesson.position_seconds || 0, watched: lesson.completed ? lesson.duration : lesson.position_seconds || 0, completed: lesson.completed, duration: lesson.duration });
    }
}
let filter = 'all';
function filterCourses() {
    const search = (qs('[data-course-search-input]')?.value || '').trim().toLocaleLowerCase('ar');
    let count = 0;
    qsa('.course-card').forEach(card => { const visible = (!qs('[data-grade-level-filter]')?.value || card.dataset.gradeLevel === qs('[data-grade-level-filter]').value) && (filter === 'all' || card.dataset.courseState === filter) && card.dataset.courseSearch.toLocaleLowerCase('ar').includes(search); card.hidden = !visible; if (visible) count++; });
    const empty = qs('.student-filter-empty');
    if (empty) empty.hidden = count !== 0;
}
qsa('[data-course-filter]').forEach(button => button.addEventListener('click', () => {
    filter = button.dataset.courseFilter;
    qsa('[data-course-filter]').forEach(tab => { tab.classList.toggle('active', tab === button); tab.setAttribute('aria-pressed', String(tab === button)); });
    filterCourses();
}));
qs('[data-course-search-input]')?.addEventListener('input', filterCourses);
qs('[data-grade-level-filter]')?.addEventListener('change', filterCourses);
qs('[data-search-toggle]')?.addEventListener('click', event => {
    const open = qs('.topbar').classList.toggle('search-is-open');
    event.currentTarget.setAttribute('aria-expanded', String(open));
    if (open) qs('[data-course-search-input]').focus();
});
qs('.search')?.addEventListener('submit', event => { if (qs('[data-course-grid]')) { event.preventDefault(); filterCourses(); } });
qs('[data-course-sort]')?.addEventListener('change', event => {
    const grid = qs('[data-course-grid]');
    if (!grid) return;
    const cards = qsa('.course-card', grid);
    const order = event.target.value;
    cards.sort((a,b) => order === 'title' ? a.dataset.courseSearch.localeCompare(b.dataset.courseSearch, 'ar') : order === 'progress' ? Number(b.dataset.percent) - Number(a.dataset.percent) : courses.findIndex(course => course.id === a.dataset.courseId) - courses.findIndex(course => course.id === b.dataset.courseId));
    cards.forEach(card => grid.append(card));
});
function updateLearning() {
    let totalCompleted = 0, totalWatched = 0, totalCourses = 0;
    let latest = null;
    courses.forEach(course => {
        const states = course.lessons.map(lesson => ({ lesson, state: getProgress(lesson.id) }));
        const completed = states.filter(item => item.state.completed).length;
        const percent = states.length ? Math.round(states.reduce((sum, item) => sum + (item.state.completed ? 100 : item.lesson.duration ? item.state.watched / item.lesson.duration * 100 : 0), 0) / states.length) : 0;
        totalCompleted += completed;
        totalWatched += states.reduce((sum, item) => sum + item.state.watched, 0);
        if (percent === 100) totalCourses++;
        const started = states.some(item => item.state.position > 0 || item.state.completed);
        qsa('[data-course-id]').filter(element => element.dataset.courseId === course.id).forEach(card => {
            card.dataset.courseState = percent === 100 ? 'completed' : started ? 'in-progress' : 'new';
            card.dataset.percent = percent;
            qsa('[data-course-percent]', card).forEach(label => label.textContent = `${percent}٪ مكتمل`);
            const bar = qs('.progress-line', card);
            if (bar) { bar.setAttribute('aria-valuenow', percent); qs('i', bar).style.width = `${percent}%`; }
            const ring = qs('.circular-progress', card);
            if (ring) { ring.style.setProperty('--progress', `${percent}%`); ring.setAttribute('aria-valuenow', percent); qs('span', ring).textContent = `${percent}٪`; }
            const link = qs('[data-course-continue]', card);
            if (link) { const next = states.find(item => !item.state.completed) || states[0]; if (next) link.href = next.lesson.url; }
        });
        states.forEach(({lesson, state}) => {
            if (state.position > 0 && !state.completed && (!latest || state.updatedAt > latest.state.updatedAt)) latest = {course, lesson, state, percent};
            qsa('[data-lesson-complete]').filter(label => label.dataset.lessonComplete === lesson.id).forEach(label => label.textContent = state.completed ? '✓' : '');
        });
    });
    qsa('[data-total-completed]').forEach(element => element.textContent = totalCompleted.toLocaleString('ar-IQ'));
    qsa('[data-total-minutes]').forEach(element => element.textContent = Math.round(totalWatched / 60).toLocaleString('ar-IQ'));
    qsa('[data-total-courses]').forEach(element => element.textContent = totalCourses.toLocaleString('ar-IQ'));
    qsa('[data-chapter-ids]').forEach(chapter => {
        const ids = JSON.parse(chapter.dataset.chapterIds);
        const percent = Math.round(ids.reduce((sum,id) => { const lesson = courses.flatMap(course => course.lessons).find(item => String(item.id) === String(id)); const state = getProgress(id); return sum + (state.completed ? 100 : lesson?.duration ? state.watched / lesson.duration * 100 : 0); },0) / ids.length);
        qs('[data-chapter-percent]', chapter).textContent = `${percent}٪`;
        const bar = qs('.progress-line', chapter); bar.setAttribute('aria-valuenow', percent); qs('i', bar).style.width = `${percent}%`;
    });
    const banner = qs('[data-resume-banner]');
    if (banner && latest) {
        qs('[data-resume-course]', banner).textContent = latest.course.title;
        qs('[data-resume-title]', banner).textContent = latest.lesson.title;
        qs('[data-resume-percent]', banner).textContent = `${latest.percent}٪ من المادة مكتمل`;
        qs('[data-resume-link]', banner).href = latest.lesson.url;
        qs('[data-resume-time]', banner).textContent = `تابع من ${formatTime(latest.state.position)}`;
        qs('.progress-line i', banner).style.width = `${latest.percent}%`;
        qs('.progress-line', banner).setAttribute('aria-valuenow', latest.percent);
    }
    filterCourses();
}
updateLearning();
document.addEventListener('academy:progress', updateLearning);

const playlistContainer = qs('[data-playlist-list]');
if (playlistContainer) {
    const key = `academy:playlist:${storageScope()}`;
    let playlist = read(key, null);
    if (!Array.isArray(playlist)) {
        playlist = document.body.dataset.preview === 'true' && courses.length ? courses.slice(0,2).map(course => ({...course.lessons[2], course_title:course.title, course_id:course.id, course_url:course.url})) : [];
        write(key, playlist);
    }
    const validUrl = (url) => { try { const parsed = new URL(url, location.href); return parsed.origin === location.origin && ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '#'; } catch { return '#'; } };
    function renderPlaylist() {
        playlistContainer.replaceChildren();
        qs('[data-playlist-empty]').hidden = playlist.length > 0;
        qs('[data-playlist-count]').textContent = playlist.length;
        qs('[data-playlist-duration]').textContent = `${Math.ceil(playlist.reduce((sum, lesson) => sum + (Number(lesson.duration) || 0), 0) / 60)} دقيقة`;
        playlist.forEach((lesson,index) => {
            const row = document.createElement('article'); row.className = 'playlist-row'; row.dataset.id = lesson.id;
            row.innerHTML = '<button class="drag-handle" type="button" aria-label="اسحب لترتيب الدرس">⠿</button><a class="playlist-thumb" aria-label="فتح الدرس"><svg viewBox="0 0 100 70" fill="none" stroke="currentColor"><circle cx="50" cy="35" r="24"/><path d="m44 24 19 11-19 11V24"/></svg></a><div class="playlist-row-copy"><h3><a></a></h3><p></p><div class="progress-line" role="progressbar" aria-label="تقدم الدرس" aria-valuemin="0" aria-valuemax="100"><i></i></div></div><div class="playlist-reorder"><button type="button" data-up aria-label="نقل للأعلى">↑</button><button type="button" data-down aria-label="نقل للأسفل">↓</button></div><button type="button" class="icon-btn" data-remove aria-label="حذف من القائمة">×</button>';
            qsa('a',row).forEach(link => link.href = validUrl(lesson.url));
            qs('h3 a',row).textContent = lesson.title;
            qs('p',row).textContent = `${lesson.course_title || ''} · ${formatTime(Number(lesson.duration) || 0)}`;
            const progress = getProgress(lesson.id); const percent = progress.completed ? 100 : Math.round(progress.position / Math.max(1,Number(lesson.duration)) * 100);
            qs('.progress-line',row).setAttribute('aria-valuenow',percent); qs('.progress-line i',row).style.width = `${percent}%`;
            qs('[data-remove]',row).addEventListener('click',() => { playlist.splice(index,1); write(key,playlist); renderPlaylist(); toast('أُزيل الدرس من قائمة التشغيل'); });
            qs('[data-up]',row).disabled = index === 0; qs('[data-down]',row).disabled = index === playlist.length - 1;
            for(const [selector,offset] of [['[data-up]',-1],['[data-down]',1]]) qs(selector,row).addEventListener('click',() => { [playlist[index],playlist[index+offset]]=[playlist[index+offset],playlist[index]]; write(key,playlist); renderPlaylist(); });
            playlistContainer.append(row);
        });
    }
    renderPlaylist();
    if(window.Sortable) new window.Sortable(playlistContainer,{animation:150,handle:'.drag-handle',dataIdAttr:'data-id',onEnd:()=>{const ids=qsa('.playlist-row',playlistContainer).map(row=>row.dataset.id); playlist.sort((a,b)=>ids.indexOf(String(a.id))-ids.indexOf(String(b.id)));write(key,playlist);renderPlaylist();}});
}

const curriculumEditor = qs('[data-curriculum-editor]');
if (curriculumEditor && window.Sortable) {
    const saveListOrder = async (list) => {
        const nodeIds = [...list.children].filter(node => node.matches('[data-curriculum-node]')).map(node => Number(node.dataset.nodeId));
        if (!nodeIds.length) return;
        const response = await fetch(curriculumEditor.dataset.sortUrl, {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': curriculumEditor.dataset.csrf},
            body: JSON.stringify({parent_id: Number(list.dataset.parentId), node_ids: nodeIds}),
        });
        if (!response.ok) throw new Error('curriculum-sort-failed');
    };
    qsa('[data-curriculum-list]', curriculumEditor).forEach(list => new window.Sortable(list, {
        animation: 160,
        handle: '.drag-handle',
        draggable: '[data-curriculum-node]',
        group: {name: 'curriculum-levels', pull: true, put: true},
        filter: 'input,textarea,button:not(.drag-handle),a,select,summary,[contenteditable="true"]',
        preventOnFilter: false,
        onEnd: async event => {
            try {
                await Promise.all([...new Set([event.from, event.to])].map(saveListOrder));
                toast('تم حفظ ترتيب المنهج.');
            } catch {
                toast('تعذر حفظ الترتيب. ستُعاد الصفحة للتحقق من ترتيب المنهج.');
                setTimeout(() => location.reload(), 800);
            }
        },
    }));
}

qsa('[data-video-upload]').forEach(panel => {
    const fileInput = qs('[data-video-file]', panel);
    const progress = qs('[data-video-progress]', panel);
    const message = qs('[data-video-message]', panel);
    const statusText = qs('[data-video-status-text]', panel);
    const resolutionBox = qs('[data-video-resolutions]', panel);
    const retryButton = qs('[data-video-retry]', panel);
    const request = async (url, method = 'GET', body = null, headers = {}) => fetch(url, {
        method, credentials: 'same-origin', body,
        headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': panel.dataset.csrf, ...headers},
    });
    const refreshVideoStatus = async () => {
        const response = await request(panel.dataset.statusUrl);
        if (!response.ok) throw new Error('video-status-failed');
        const status = await response.json();
        statusText.dataset.status = status.status;
        statusText.textContent = ({not_uploaded: 'لم يُرفع', queued: 'بانتظار المعالجة', processing: 'قيد المعالجة', ready: 'جاهز', failed: 'فشل'})[status.status] || status.status;
        resolutionBox.hidden = status.status !== 'ready';
        retryButton.hidden = status.status !== 'failed';
        if (status.error) message.textContent = status.error;
        return status;
    };
    const fingerprintFile = async file => {
        const edgeBytes = 1024 * 1024;
        const first = new Uint8Array(await file.slice(0, edgeBytes).arrayBuffer());
        const last = new Uint8Array(await file.slice(Math.max(0, file.size - edgeBytes)).arrayBuffer());
        const metadata = new TextEncoder().encode(`${file.name}|${file.size}|${file.lastModified}|`);
        const source = new Uint8Array(metadata.length + first.length + last.length);
        source.set(metadata);
        source.set(first, metadata.length);
        source.set(last, metadata.length + first.length);
        const bytes = await crypto.subtle.digest('SHA-256', source);
        return [...new Uint8Array(bytes)].map(byte => byte.toString(16).padStart(2, '0')).join('');
    };
    qs('[data-video-start]', panel).addEventListener('click', async event => {
        const file = fileInput.files[0];
        if (!file) { message.textContent = 'اختر ملف الفيديو أولاً.'; return; }
        if (file.size > 10 * 1024 ** 3) { message.textContent = 'الحد الأقصى لحجم الفيديو 10 جيجابايت.'; return; }
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const fingerprint = await fingerprintFile(file);
            message.textContent = 'جارٍ تجهيز الرفع القابل للاستئناف…';
            await new Promise((resolve, reject) => {
                const upload = new tus.Upload(file, {
                    endpoint: panel.dataset.startUrl,
                    chunkSize: 4 * 1024 * 1024,
                    retryDelays: [0, 1000, 3000, 5000, 10000],
                    metadata: {filename: file.name, fingerprint},
                    headers: {'X-CSRF-TOKEN': panel.dataset.csrf, 'Accept': 'application/json'},
                    onProgress: (bytesUploaded, bytesTotal) => {
                        const percent = Math.floor(bytesUploaded / bytesTotal * 100);
                        progress.value = percent;
                        message.textContent = `رُفع ${percent}٪`;
                    },
                    onError: reject,
                    onSuccess: resolve,
                });
                upload.findPreviousUploads().then(previousUploads => {
                    if (previousUploads.length) upload.resumeFromPreviousUpload(previousUploads[0]);
                    upload.start();
                }).catch(reject);
            });
            message.textContent = 'اكتمل الرفع. يجري إدراج الفيديو في قائمة المعالجة.';
            let status;
            for (let count = 0; count < 120; count++) {
                await new Promise(resolve => setTimeout(resolve, 5000));
                status = await refreshVideoStatus();
                if (['ready', 'failed'].includes(status.status)) break;
            }
            if (status?.status === 'ready') message.textContent = `الفيديو جاهز. الدقات: ${status.resolutions.join('، ')}p`;
            else if (status?.status === 'failed') message.textContent = status.error || 'فشلت معالجة الفيديو. يمكنك إعادة المحاولة.';
        } catch (error) {
            message.textContent = error.message || 'تعذّر رفع الفيديو. أعد المحاولة مع اختيار الملف نفسه لاستكمال الرفع.';
            refreshVideoStatus().catch(() => {});
        } finally {
            button.disabled = false;
        }
    });
    retryButton.addEventListener('click', async () => {
        retryButton.disabled = true;
        try {
            const response = await request(panel.dataset.retryUrl, 'POST');
            if (!response.ok) throw new Error('تعذّرت إعادة المعالجة.');
            message.textContent = 'أُرسلت محاولة المعالجة إلى قائمة الانتظار.';
            await refreshVideoStatus();
        } catch (error) { message.textContent = error.message; }
        finally { retryButton.disabled = false; }
    });
    qs('[data-save-video-resolutions]', panel).addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const resolutions = qsa('[data-video-resolution]:checked', panel).map(input => Number(input.value));
            if (resolutions.length === 0) throw new Error('فعّل دقة واحدة على الأقل للفيديو.');
            const response = await request(panel.dataset.resolutionUrl, 'PUT', JSON.stringify({resolutions}), {'Content-Type': 'application/json'});
            if (!response.ok) throw new Error((await response.json()).message || 'تعذّر حفظ الدقات.');
            message.textContent = 'حُفظت دقات الفيديو المفعّلة.';
        } catch (error) { message.textContent = error.message; }
        finally { button.disabled = false; }
    });
    qsa('[data-delete-video-resolution]', panel).forEach(button => button.addEventListener('click', async () => {
        const height = Number(button.dataset.height);
        if (!window.confirm(`سيُحذف ملف ${height}p من مساحة الخادم نهائياً. لاستعادته يجب إعادة معالجة الفيديو. هل تريد المتابعة؟`)) return;
        button.disabled = true;
        const url = panel.dataset.deleteResolutionUrl.replace(/\/0(?:\?|$)/, `/${height}`);
        try {
            const response = await request(url, 'DELETE');
            if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'تعذّر حذف ملفات الدقة.');
            const data = await response.json();
            button.closest('[data-video-resolution-row]').remove();
            qsa('[data-video-resolution]', panel).forEach(input => { input.checked = data.enabled_resolutions.includes(Number(input.value)); });
            message.textContent = `حُذفت ملفات دقة ${height}p من مساحة الخادم.`;
        } catch (error) {
            message.textContent = error.message;
            button.disabled = false;
        }
    }));
    refreshVideoStatus().catch(() => {});
    window.setInterval(() => {
        if (!['queued', 'processing'].includes(statusText.dataset.status)) return;
        refreshVideoStatus().catch(() => {});
    }, 5000);
});

qsa('[data-rich-editor]').forEach(surface => {
    const form = surface.closest('form');
    const field = qs('[data-rich-value]', form);
    const toolbar = surface.parentElement.querySelector('.rich-text-toolbar');
    toolbar?.querySelectorAll('[data-rich-command]').forEach(button => button.addEventListener('click', () => {
        surface.focus();
        document.execCommand(button.dataset.richCommand, false);
    }));
    form?.addEventListener('submit', () => { if (field) field.value = surface.innerHTML; });
});

const courseOrder = qs('[data-course-order]');
if (courseOrder && window.Sortable) {
    new window.Sortable(courseOrder, {
        animation: 150,
        handle: '.drag-handle',
        draggable: '[data-course-id]',
        onEnd: async () => {
            const response = await fetch(courseOrder.dataset.sortUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': courseOrder.dataset.csrf},
                body: JSON.stringify({course_ids: [...courseOrder.children].filter(row => row.matches('[data-course-id]')).map(row => Number(row.dataset.courseId))}),
            });
            if (!response.ok) location.reload();
        },
    });
}

window.ACADEMY_SETTINGS = document.body.dataset.preview === 'true' ? read('sheet:instructor:v1:settings', {}) : {};
initPlayers();
initViewers();
initProtection();
initPwa();

const tokenDialog = qs('[data-student-token-dialog]');
let tokenRevealRequest;
tokenDialog?.addEventListener('close', () => {
    tokenRevealRequest?.abort();
    qs('#student-visible-token', tokenDialog).textContent = '';
    qs('#student-visible-token', tokenDialog).hidden = true;
    qs('[data-token-copy]', tokenDialog).hidden = true;
});
qs('[data-hide-student-token]')?.addEventListener('click', () => tokenDialog.close());
qsa('[data-reveal-student-token]').forEach(button => button.addEventListener('click', async () => {
    tokenRevealRequest?.abort();
    tokenRevealRequest = new AbortController();
    const message = qs('[data-token-message]', tokenDialog);
    const value = qs('#student-visible-token', tokenDialog);
    const copy = qs('[data-token-copy]', tokenDialog);
    value.textContent = ''; value.hidden = true; copy.hidden = true; (copy.querySelector('[data-copy-label]') || copy).textContent = 'نسخ التوكن';
    qs('[data-token-owner]', tokenDialog).textContent = button.dataset.studentName;
    message.textContent = 'جارٍ تحميل التوكن…';
    tokenDialog.showModal();
    try {
        const response = await fetch(button.dataset.revealStudentToken, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': qs('meta[name="csrf-token"]').content}, cache: 'no-store', signal: tokenRevealRequest.signal});
        const data = await response.json();
        if (!response.ok) { message.textContent = data.message || 'تعذّر عرض التوكن.'; return; }
        value.textContent = data.token; value.hidden = false; copy.hidden = false;
        message.textContent = 'شارك الرمز مع الطالب المعني فقط.';
    } catch (error) {
        if (error.name !== 'AbortError') message.textContent = 'تعذّر تحميل التوكن. أعد المحاولة.';
    }
}));

qsa('[data-student-access]').forEach(panel => {
    const updateAccessFields = () => {
        const grade = panel.querySelector('[name="access_type"]:checked')?.value === 'grade';
        panel.querySelector('[data-access-grade]').hidden = !grade;
        panel.querySelector('[data-access-courses]').hidden = grade;
        const select = panel.querySelector('[name="grade_level_id"]');
        select.disabled = !grade; select.required = grade;
        panel.querySelectorAll('[name="course_ids[]"]').forEach(input => input.disabled = grade);
    };
    panel.addEventListener('change', updateAccessFields);
    updateAccessFields();
});
