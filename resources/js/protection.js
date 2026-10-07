const PREFERENCE_KEY = 'dental-content-protection';
const EDITABLE_SELECTOR = 'input, textarea, select, [contenteditable]:not([contenteditable="false"]), [data-protection-exempt]';
let initialized = false;
let enabled = true;
let lastNoticeAt = 0;

const readPreference = () => {
    try {
        const value = JSON.parse(localStorage.getItem(PREFERENCE_KEY));
        return typeof value === 'boolean' ? value : true;
    } catch {
        return true;
    }
};

const isActive = () => enabled && document.body.dataset.protected === 'true';

const isExempt = (target) => target instanceof Element
    && (target.closest(EDITABLE_SELECTOR) || target.isContentEditable);

const notifyBlocked = (action) => {
    const now = Date.now();
    if (now - lastNoticeAt < 3500) {
        return;
    }
    lastNoticeAt = now;
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
    enabled = Boolean(value);
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
    enabled = readPreference();

    const styles = document.createElement('style');
    styles.dataset.protectionStyles = '';
    styles.textContent = `
        body.content-protected { -webkit-user-select: none; user-select: none; }
        body.content-protected input, body.content-protected textarea,
        body.content-protected select, body.content-protected [contenteditable],
        body.content-protected [data-protection-exempt], body.content-protected [data-protection-exempt] * {
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
                && event.target.closest('[data-sortable], [data-drag-handle], .drag-handle')) {
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
            notifyBlocked('shortcut');
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
            enabled = readPreference();
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
};
