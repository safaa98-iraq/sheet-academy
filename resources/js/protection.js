const PREFERENCE_KEY = 'dental-content-protection';
const EDITABLE_SELECTOR = 'input, textarea, select, [contenteditable]:not([contenteditable="false"])';
let initialized = false;
let enabled = true;
const lastNoticeAt = new Map();
const mandatory = () => document.body.dataset.preview !== 'true' && Boolean(document.body.dataset.studentActivityUrl);

const readPreference = () => {
    try {
        const value = JSON.parse(localStorage.getItem(PREFERENCE_KEY));
        return typeof value === 'boolean' ? value : true;
    } catch {
        return true;
    }
};

const isActive = () => mandatory() || (enabled && document.body.dataset.protected === 'true');

const isExempt = (target) => target instanceof Element
    && (target.closest(EDITABLE_SELECTOR) || target.isContentEditable);

const notifyBlocked = (action) => {
    const now = Date.now();
    if (now - (lastNoticeAt.get(action) || 0) < 3500) {
        return;
    }
    lastNoticeAt.set(action, now);
    document.dispatchEvent(new CustomEvent('protection:blocked', { detail: { action } }));
};

const updateControls = () => {
    document.body.classList.toggle('content-protected', isActive());
    document.querySelectorAll('[data-protection-toggle]').forEach((control) => {
        if (control instanceof HTMLInputElement && control.type === 'checkbox') {
            control.checked = enabled;
        } else {
            control.setAttribute('aria-pressed', String(enabled));
        }
    });
    document.querySelectorAll('[data-protection-state]').forEach((label) => {
        label.textContent = enabled ? 'الحماية مفعّلة' : 'الحماية متوقفة';
    });
};

export const setProtection = (value) => {
    enabled = mandatory() || Boolean(value);
    try {
        localStorage.setItem(PREFERENCE_KEY, JSON.stringify(enabled));
    } catch {
        // The preference still applies to this page when browser storage is unavailable.
    }
    updateControls();
    document.dispatchEvent(new CustomEvent('protection:change', { detail: { enabled } }));
};

export const initProtection = () => {
    if (initialized) {
        return;
    }
    initialized = true;
    enabled = mandatory() || readPreference();

    const styles = document.createElement('style');
    styles.dataset.protectionStyles = '';
    styles.textContent = `
        body.content-protected { -webkit-user-select: none; user-select: none; }
        body.content-protected input, body.content-protected textarea,
        body.content-protected select, body.content-protected [contenteditable]:not([contenteditable="false"]) {
            -webkit-user-select: text; user-select: text;
        }
        body.content-protected img { -webkit-user-drag: none; }
        @media print { body.content-protected { display: none !important; } }
    `;
    document.head.append(styles);
    updateControls();

    ['selectstart', 'copy', 'cut', 'paste', 'contextmenu', 'dragstart'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            if (!isActive() || isExempt(event.target)) {
                return;
            }
            if (eventName === 'dragstart' && event.target instanceof Element
                && !mandatory() && event.target.closest('[data-sortable], [data-drag-handle], .drag-handle')) {
                return;
            }

            event.preventDefault();
            if (eventName !== 'selectstart') {
                notifyBlocked(eventName);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (!isActive() || isExempt(event.target)) {
            return;
        }

        const key = event.key.toLowerCase();
        const isCommand = event.ctrlKey || event.metaKey;
        if (key === 'f12' || (isCommand && ['s', 'p', 'u', 'c', 'x'].includes(key))
            || (isCommand && event.shiftKey && ['i', 'j'].includes(key))) {
            event.preventDefault();
            notifyBlocked(key === 'f12' || (event.shiftKey && ['i', 'j'].includes(key)) ? 'devtools' : key === 'p' ? 'print' : 'shortcut');
        }
    });

    const originalPrint = window.print.bind(window);
    window.print = () => {
        if (isActive()) {
            notifyBlocked('print');
            return;
        }
        originalPrint();
    };
    window.addEventListener('beforeprint', () => {
        if (isActive()) {
            document.body.classList.add('content-protected');
            notifyBlocked('print');
        }
    });

    document.querySelectorAll('[data-protection-toggle]').forEach((control) => {
        control.addEventListener(control instanceof HTMLInputElement ? 'change' : 'click', () => {
            setProtection(control instanceof HTMLInputElement ? control.checked : !enabled);
        });
    });

    window.addEventListener('storage', (event) => {
        if (event.key === PREFERENCE_KEY) {
            enabled = mandatory() || readPreference();
            updateControls();
        }
    });

    let devToolsSuspected = false;
    const inspectWindow = () => {
        if (document.visibilityState === 'hidden') {
            return;
        }
        const suspected = isActive() && window.outerWidth > 0 && window.outerHeight > 0
            && (window.outerWidth - window.innerWidth > 220 || window.outerHeight - window.innerHeight > 220);
        if (suspected !== devToolsSuspected) {
            devToolsSuspected = suspected;
            document.body.dataset.devtoolsSuspected = String(suspected);
            document.dispatchEvent(new CustomEvent('protection:devtools', { detail: { suspected } }));
        }
    };
    window.setInterval(inspectWindow, 2000);
    window.addEventListener('resize', inspectWindow);
    document.addEventListener('protection:blocked', event => {
        if (event.detail?.action === 'devtools') document.dispatchEvent(new CustomEvent('student:session-ended'));
    });
    document.addEventListener('protection:devtools', event => {
        if (event.detail?.suspected) document.dispatchEvent(new CustomEvent('student:session-ended'));
    });
};
