import { guardWatermark, toggleProtectedFullscreen } from './watermark-guard';
export function initViewers() {
    document.querySelectorAll('[data-document-viewer]').forEach((viewer) => {
        if (viewer.dataset.initialized) return;
        viewer.dataset.initialized = 'true';
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
                await toggleProtectedFullscreen(viewer);
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
            const stopViewer = () => {
                viewer.querySelectorAll('[data-document-image], .document-thumbnail-sheet img').forEach(image => image.removeAttribute('src'));
                canvas.hidden = true;
                announcement.textContent = 'أُوقف العرض لحماية المحتوى. افتح الملزمة مجدداً.';
            };
            pages.forEach(page => guardWatermark({container: page, selector: '[data-document-watermark]', text: watermarkText,
                isVisible: () => !page.hidden && page.querySelector('[data-document-image]')?.naturalWidth > 0,
                onTamper: () => { stopViewer(); window.reportStudentActivity?.('suspicious_watermark', viewer.dataset.lessonId); }}));
            document.addEventListener('student:session-ended', stopViewer);
            window.setInterval(() => {
                pages[currentPage]?.querySelectorAll('[data-document-watermark]').forEach((watermark) => {
                    const parent = watermark.parentElement;
                    const room = Math.max(0, 84 - watermark.offsetWidth / parent.clientWidth * 100);
                    watermark.style.insetInlineStart = `${8 + Math.random() * room}%`;
                });
            }, 9000);
        }
        new ResizeObserver(render).observe(canvas);
        render();
    });
}
