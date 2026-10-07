export function initViewers() {
    document.querySelectorAll('[data-document-viewer]').forEach((viewer) => {
        if (viewer.dataset.initialized) return;
        viewer.dataset.initialized = 'true';
        if (window.ACADEMY_SETTINGS?.watermark === false) viewer.querySelectorAll('[data-viewer-watermark]').forEach(mark => mark.style.display = 'none');
        const pages = [...viewer.querySelectorAll('[data-document-page]')];
        let currentPage = 0;
        let zoom = 1;
        const canvas = viewer.querySelector('.document-canvas');
        const announcement = viewer.querySelector('[data-viewer-announcement]');
        const render = () => {
            pages.forEach((page, index) => { page.hidden = index !== currentPage; });
            viewer.querySelectorAll('[data-viewer-goto]').forEach((button) => {
                const active = Number(button.dataset.viewerGoto) === currentPage;
                button.classList.toggle('is-current', active);
                button.setAttribute('aria-current', active ? 'page' : 'false');
            });
            viewer.querySelector('[data-viewer-page]').textContent = String(currentPage + 1);
            viewer.querySelector('[data-viewer-previous]').disabled = currentPage === 0;
            viewer.querySelector('[data-viewer-next]').disabled = currentPage >= pages.length - 1;
            viewer.querySelector('[data-viewer-zoom-label]').textContent = `${Math.round(zoom * 100)}%`;
            viewer.querySelector('[data-viewer-zoom="-1"]').disabled = zoom <= 0.5;
            viewer.querySelector('[data-viewer-zoom="1"]').disabled = zoom >= 2;
            const width = Math.max(220, Math.min(720, canvas.clientWidth - 40));
            pages.forEach((page) => { page.style.width = `${width * zoom}px`; });
            announcement.textContent = `الصفحة ${currentPage + 1} من ${pages.length}، التكبير ${Math.round(zoom * 100)} بالمئة`;
        };
        const goTo = (page) => {
            currentPage = Math.min(pages.length - 1, Math.max(0, page));
            canvas.scrollTop = 0;
            render();
        };
        viewer.querySelector('[data-viewer-previous]').addEventListener('click', () => goTo(currentPage - 1));
        viewer.querySelector('[data-viewer-next]').addEventListener('click', () => goTo(currentPage + 1));
        viewer.querySelectorAll('[data-viewer-goto]').forEach((button) => button.addEventListener('click', () => goTo(Number(button.dataset.viewerGoto))));
        viewer.querySelectorAll('[data-viewer-zoom]').forEach((button) => button.addEventListener('click', () => {
            zoom = Math.max(0.5, Math.min(2, Math.round((zoom + Number(button.dataset.viewerZoom) * 0.25) * 100) / 100));
            render();
        }));
        viewer.querySelector('[data-viewer-fit]').addEventListener('click', () => { zoom = 1; render(); });
        viewer.querySelector('[data-viewer-fullscreen]').addEventListener('click', async () => {
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
                else if (viewer.requestFullscreen) await viewer.requestFullscreen();
            } catch {
                viewer.querySelector('[data-viewer-announcement]').textContent = 'تعذّر فتح العارض بملء الشاشة.';
            }
        });
        canvas.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') { event.preventDefault(); goTo(currentPage + 1); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); goTo(currentPage - 1); }
        });
        viewer.addEventListener('contextmenu', (event) => event.preventDefault());
        viewer.addEventListener('dragstart', (event) => event.preventDefault());
        if (viewer.dataset.refreshUrl) {
            const refreshPageLinks = async () => {
                try {
                    const response = await fetch(viewer.dataset.refreshUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
                    if (!response.ok) throw new Error('انتهت صلاحية جلسة عرض الملزمة. افتح الملزمة مجدداً.');
                    const {pages: refreshedPages} = await response.json();
                    pages.forEach((page, index) => {
                        const image = page.querySelector('[data-document-image]');
                        const thumbnail = viewer.querySelectorAll('.document-thumbnail-sheet img')[index];
                        if (refreshedPages[index]?.url) {
                            image.src = refreshedPages[index].url;
                            if (thumbnail) thumbnail.src = refreshedPages[index].url;
                        }
                    });
                } catch (error) {
                    announcement.textContent = error.message || 'تعذّر تجديد رابط الصفحة.';
                }
            };
            window.setInterval(refreshPageLinks, 4 * 60 * 1000);
            const watermarkText = viewer.dataset.studentWatermark || 'محتوى تعليمي مرخّص';
            const restoreWatermarks = () => {
                let changed = false;
                pages.forEach((page) => {
                    for (const watermark of page.querySelectorAll('[data-document-watermark]')) {
                        if (watermark.textContent !== watermarkText) watermark.textContent = watermarkText;
                        const style = getComputedStyle(watermark);
                        if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) < 0.12) {
                            watermark.style.removeProperty('display');
                            watermark.style.removeProperty('visibility');
                            watermark.style.opacity = '.48';
                            changed = true;
                        }
                    }
                    if (!page.querySelector('[data-document-watermark]')) {
                        [18, 50, 82].forEach((vertical) => {
                            const watermark = document.createElement('span');
                            watermark.className = 'document-watermark';
                            watermark.dataset.documentWatermark = '';
                            watermark.style.setProperty('--watermark-y', `${vertical}%`);
                            watermark.textContent = watermarkText;
                            page.append(watermark);
                        });
                        changed = true;
                    }
                });
                if (changed) window.reportStudentActivity?.('suspicious_watermark', viewer.dataset.lessonId);
            };
            const watermarkObserver = new MutationObserver(restoreWatermarks);
            pages.forEach((page) => watermarkObserver.observe(page, {subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'style', 'hidden']}));
            restoreWatermarks();
            window.setInterval(() => {
                pages[currentPage]?.querySelectorAll('[data-document-watermark]').forEach((watermark) => {
                    watermark.style.insetInlineStart = `${8 + Math.random() * 48}%`;
                });
            }, 9000);
            window.addEventListener('pagehide', () => watermarkObserver.disconnect(), {once: true});
        }
        new ResizeObserver(render).observe(canvas);
        render();
    });
}
