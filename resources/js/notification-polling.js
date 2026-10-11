export function initializeNotificationPolling(root = document, timers = window, fetcher = fetch) {
    const bell = root.querySelector('[data-notifications-url]');
    const badge = bell?.querySelector('[data-notification-count]');
    if (!bell || !badge) return;
    const arrival = root.querySelector('[data-notification-arrival]');
    const message = arrival?.querySelector('[data-notification-message]');
    let lastShown = null;
    let pending = false;
    let stopped = false;
    let timer;
    const check = async () => {
        if (root.hidden || pending || stopped) return;
        pending = true;
        try {
            const response = await fetcher(bell.dataset.notificationsUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (!response.ok) {
                if ([401, 403, 419, 423].includes(response.status)) {
                    stopped = true;
                    timers.clearInterval(timer);
                    if (response.status === 423) root.dispatchEvent(new CustomEvent('student:session-ended'));
                }
                return;
            }
            const {count, latest} = await response.json();
            const unread = Math.max(0, Number(count) || 0);
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.hidden = unread < 1;
            bell.setAttribute('aria-label', `تنبيهاتي: ${unread} غير مقروء`);
            if (unread > 0 && latest?.id && latest.id !== lastShown && message && arrival) {
                message.textContent = latest.message;
                arrival.hidden = false;
                lastShown = latest.id;
            }
            if (unread < 1 && arrival) arrival.hidden = true;
        } catch {} finally { pending = false; }
    };
    timer = timers.setInterval(check, 15000);
    root.addEventListener('visibilitychange', check);
    timers.addEventListener('pagehide', () => {
        stopped = true;
        timers.clearInterval(timer);
        root.removeEventListener('visibilitychange', check);
    }, {once: true});
    check();
}
