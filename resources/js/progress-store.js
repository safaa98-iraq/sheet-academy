const memoryProgress = new Map();
const pendingSaves = new Map();
const savesInFlight = new Set();

export function storageScope() {
    return document.body?.dataset.storageScope || 'preview';
}

const isPreview = () => document.body?.dataset.preview === 'true';
const progressKey = (lessonId) => `academy:progress:${storageScope()}:${lessonId}`;

function normalizeProgress(progress = {}) {
    const finite = (value) => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
    const duration = finite(progress.duration);
    const position = finite(progress.position);
    const watched = finite(progress.watched);

    return {
        position: duration ? Math.min(position, duration) : position,
        watched: duration ? Math.min(watched, duration) : watched,
        completed: progress.completed === true,
        duration,
        updatedAt: typeof progress.updatedAt === 'string' ? progress.updatedAt : null,
    };
}

function seedServerProgress() {
    try {
        const seed = JSON.parse(document.querySelector('#initial-progress')?.textContent || '{}');
        Object.entries(seed).forEach(([lessonId, progress]) => memoryProgress.set(`${storageScope()}:${lessonId}`, normalizeProgress(progress)));
    } catch {
        // A missing progress snapshot simply means that no lessons have been watched yet.
    }
}

seedServerProgress();

export function getProgress(lessonId) {
    const key = `${storageScope()}:${lessonId}`;
    if (memoryProgress.has(key)) return normalizeProgress(memoryProgress.get(key));
    if (!isPreview()) return normalizeProgress();

    try {
        const stored = JSON.parse(localStorage.getItem(progressKey(lessonId)) || 'null');
        return normalizeProgress(stored && typeof stored === 'object' ? stored : memoryProgress.get(key));
    } catch {
        return normalizeProgress(memoryProgress.get(key));
    }
}

function dispatchProgress(lessonId, progress) {
    document.dispatchEvent(new CustomEvent('academy:progress', { detail: { lessonId: String(lessonId), progress } }));
}

function progressEndpoint(lessonId) {
    const template = document.body?.dataset.progressUrlTemplate;
    return template ? template.replace('LESSON_ID', encodeURIComponent(String(lessonId))) : null;
}

function persistServerProgress(lessonId, progress, options = {}) {
    const endpoint = progressEndpoint(lessonId);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!endpoint || !csrf) return;
    const payload = {
        event: options.event || 'heartbeat',
        position_seconds: Math.floor(progress.position),
        is_playing: options.isPlaying === true,
    };

    if (options.beacon && navigator.sendBeacon) {
        const body = new URLSearchParams({...payload, is_playing: options.isPlaying === true ? '1' : '0', _token: csrf});
        navigator.sendBeacon(endpoint, body);
        return;
    }

    pendingSaves.set(String(lessonId), {endpoint, csrf, payload});
    if (savesInFlight.has(String(lessonId))) return;
    sendNextSave(lessonId);
}

function sendNextSave(lessonId) {
    const key = String(lessonId);
    const pending = pendingSaves.get(key);
    if (!pending) return;
    pendingSaves.delete(key);
    savesInFlight.add(key);
    fetch(pending.endpoint, {
        method: 'POST', credentials: 'same-origin', keepalive: pending.payload.event === 'unloaded',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': pending.csrf},
        body: JSON.stringify(pending.payload),
    }).then(async (response) => {
        if (response.redirected || [401, 403, 404, 419, 423].includes(response.status)) {
            document.dispatchEvent(new CustomEvent('student:session-ended'));
            return;
        }
        if (!response.ok) return;
        const result = await response.json();
        const serverProgress = normalizeProgress({
            position: result.position_seconds,
            watched: result.watched_seconds,
            completed: result.completed,
            duration: result.duration_seconds,
            updatedAt: result.updated_at,
        });
        memoryProgress.set(`${storageScope()}:${lessonId}`, serverProgress);
        dispatchProgress(lessonId, serverProgress);
    }).catch(() => {}).finally(() => {
        savesInFlight.delete(key);
        if (pendingSaves.has(key)) sendNextSave(lessonId);
    });
}

export function saveProgress(lessonId, values = {}, options = {}) {
    const progress = normalizeProgress({...getProgress(lessonId), ...values, updatedAt: new Date().toISOString()});
    const key = `${storageScope()}:${lessonId}`;
    memoryProgress.set(key, progress);

    if (isPreview()) {
        try {
            localStorage.setItem(progressKey(lessonId), JSON.stringify(progress));
        } catch {
            document.dispatchEvent(new CustomEvent('academy:storage-unavailable'));
        }
    } else {
        persistServerProgress(lessonId, progress, options);
    }
    dispatchProgress(lessonId, progress);

    return progress;
}

export function flushProgress(lessonId, values = {}) {
    const progress = normalizeProgress({...getProgress(lessonId), ...values, updatedAt: new Date().toISOString()});
    memoryProgress.set(`${storageScope()}:${lessonId}`, progress);
    if (isPreview()) {
        saveProgress(lessonId, values);
        return;
    }
    persistServerProgress(lessonId, progress, {event: 'unloaded', isPlaying: false, beacon: true});
}
