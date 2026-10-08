// Browser deterrence only: the server remains responsible for media authorization.
export async function toggleProtectedFullscreen(container) {
    if (document.fullscreenElement) { await document.exitFullscreen(); return; }
    if (container.classList.contains('is-viewport-fullscreen')) {
        container.classList.remove('is-viewport-fullscreen');
        document.body.classList.remove('viewport-fullscreen-open');
        return;
    }
    if (container.requestFullscreen) {
        try { await container.requestFullscreen(); return; } catch { /* Keep the watermark in the viewport fallback. */ }
    }
    container.classList.add('is-viewport-fullscreen');
    document.body.classList.add('viewport-fullscreen-open');
    const exit = event => {
        if (event.key !== 'Escape') return;
        container.classList.remove('is-viewport-fullscreen');
        document.body.classList.remove('viewport-fullscreen-open');
        document.removeEventListener('keydown', exit);
    };
    document.addEventListener('keydown', exit);
}

export function guardWatermark({container, selector, text, onTamper, isVisible = () => true}) {
    let stopped = false;
    let pending = false;
    const original = [...container.querySelectorAll(selector)];
    const textSurfaces = container.matches('[data-text-protected]') ? [...container.querySelectorAll('p, h2, h3, strong, em, b, i, u, li, ul, ol, blockquote, a')] : [];
    const inspect = () => {
        pending = false;
        if (stopped || document.hidden || !isVisible()) return;
        const marks = [...container.querySelectorAll(selector)];
        let invalid = !container.isConnected || marks.length !== original.length || marks.length === 0;
        for (const mark of marks) {
            if (mark.textContent !== text || !original.includes(mark)) invalid = true;
            const bounds = mark.getBoundingClientRect();
            const frame = container.getBoundingClientRect();
            let opacity = 1;
            for (let node = mark; node instanceof Element; node = node.parentElement) {
                const style = getComputedStyle(node);
                opacity *= Number(style.opacity);
                if (style.display === 'none' || style.visibility !== 'visible' || style.contentVisibility === 'hidden') invalid = true;
            }
            if (opacity < 0.12 || bounds.width < 30 || bounds.height < 8
                || bounds.right < frame.left || bounds.left > frame.right || bounds.bottom < frame.top || bounds.top > frame.bottom) invalid = true;
            // Inspect the visible centre; a covering sibling or external layer must not hide the mark.
            const x = Math.max(0, Math.min(innerWidth - 1, bounds.left + bounds.width / 2));
            const y = Math.max(0, Math.min(innerHeight - 1, bounds.top + bounds.height / 2));
            if (bounds.top < innerHeight && bounds.bottom > 0 && bounds.left < innerWidth && bounds.right > 0) {
                const hit = document.elementFromPoint(x, y);
                if (hit && !mark.contains(hit) && hit !== container && !hit.matches('video, [data-document-image]') && !textSurfaces.includes(hit)) invalid = true;
            }
        }
        if (invalid) { stopped = true; observer.disconnect(); clearInterval(timer); onTamper(); }
    };
    const observer = new MutationObserver(() => {
        if (!pending) { pending = true; queueMicrotask(inspect); }
    });
    observer.observe(document.documentElement, {subtree: true, childList: true, attributes: true, characterData: true,
        attributeFilter: ['style', 'class', 'hidden']});
    const timer = setInterval(inspect, 1000);
    document.addEventListener('fullscreenchange', inspect);
    window.addEventListener('pagehide', () => {observer.disconnect(); clearInterval(timer);}, {once: true});
    inspect();
    return {inspect, get stopped() {return stopped;}};
}
