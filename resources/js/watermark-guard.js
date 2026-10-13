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
            // Test only the part visible through scroll containers. The rest is normal clipping.
            const visible = {left: Math.max(0, bounds.left), right: Math.min(innerWidth, bounds.right), top: Math.max(0, bounds.top), bottom: Math.min(innerHeight, bounds.bottom)};
            for (let ancestor = mark.parentElement; ancestor instanceof Element; ancestor = ancestor.parentElement) {
                const style = getComputedStyle(ancestor);
                const clip = ancestor.getBoundingClientRect();
                if (style.overflowX !== 'visible') { visible.left = Math.max(visible.left, clip.left); visible.right = Math.min(visible.right, clip.right); }
                if (style.overflowY !== 'visible') { visible.top = Math.max(visible.top, clip.top); visible.bottom = Math.min(visible.bottom, clip.bottom); }
            }
            if (visible.left < visible.right && visible.top < visible.bottom) {
                const hit = document.elementFromPoint((visible.left + visible.right) / 2, (visible.top + visible.bottom) / 2);
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
