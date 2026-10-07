function initializeSidebar() {
    const toggle = document.querySelector('[data-admin-nav-toggle]');
    const backdrop = document.querySelector('[data-admin-nav-close]');
    const close = () => { document.body.classList.remove('admin-nav-open'); toggle?.setAttribute('aria-expanded', 'false'); if (backdrop) backdrop.hidden = true; };
    matchMedia('(min-width: 1001px)').addEventListener('change', close);
    toggle?.addEventListener('click', () => {
        const open = document.body.classList.toggle('admin-nav-open');
        toggle.setAttribute('aria-expanded', String(open));
        if (backdrop) backdrop.hidden = !open;
    });
    document.querySelector('[data-admin-nav-close]')?.addEventListener('click', close);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });
    document.addEventListener('click', (event) => { if (event.target.closest('[data-dialog-cancel]')) document.querySelector('[data-admin-dialog]')?.close(); });
    const modal = document.querySelector('[data-admin-dialog]');
    modal?.addEventListener('click', (event) => {
        const bounds = modal.getBoundingClientRect();
        if (event.target === modal && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) modal.close();
    });
}

if (document.body.classList.contains('admin-body')) {
    initializeSidebar();
}
