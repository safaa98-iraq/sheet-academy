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

const lectureFilters = document.querySelector('[data-lecture-filters]');
if (lectureFilters) {
    const grade = lectureFilters.querySelector('[data-lecture-grade]');
    const course = lectureFilters.querySelector('[data-lecture-course]');
    const filterCourses = () => {
        [...course.options].forEach(option => {
            const visible = !option.value || (!grade.value && !option.dataset.gradeId) || option.dataset.gradeId === grade.value;
            option.hidden = !visible; option.disabled = !visible;
        });
        if (course.selectedOptions[0]?.disabled) course.value = '';
    };
    grade.addEventListener('change', () => { course.value = ''; filterCourses(); });
    course.addEventListener('change', () => { if (course.value) lectureFilters.requestSubmit(); });
    filterCourses();
}

document.querySelectorAll('[data-publication-fields]').forEach(panel => {
    const status = panel.querySelector('[data-publication-status]');
    const field = panel.querySelector('[data-scheduled-field]');
    const update = () => { field.hidden = status.value !== 'scheduled'; field.querySelector('input').required = status.value === 'scheduled'; field.querySelector('input').disabled = status.value !== 'scheduled'; };
    status.addEventListener('change', update); update();
});

const lectureForm = document.querySelector('#lecture-content-form');
if (lectureForm) {
    let dirty = false;
    const status = lectureForm.querySelector('[data-rich-save-status]');
    const markDirty = () => { dirty = true; status.textContent = 'تغييرات غير محفوظة'; };
    lectureForm.addEventListener('input', markDirty);
    document.querySelector('.lecture-publish')?.addEventListener('change', markDirty);
    lectureForm.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    if (lectureForm.querySelector('[name="_method"]')) {
        window.academySaveLecture = async () => {
            if (!lectureForm.reportValidity()) throw new Error('أكمل الحقول المطلوبة قبل رفع الوسائط.');
            lectureForm.dispatchEvent(new Event('lecture:sync'));
            const response = await fetch(lectureForm.action, {method: 'POST', body: new FormData(lectureForm), headers: {'Accept': 'application/json'}, credentials: 'same-origin'});
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'تعذّر حفظ المحاضرة.');
            dirty = false; status.textContent = 'تم حفظ المحاضرة';
        };
        document.querySelector('[data-lecture-attachments]')?.addEventListener('submit', async event => {
            event.preventDefault();
            try { await window.academySaveLecture(); event.target.submit(); }
            catch (error) { status.textContent = error.message; }
        });
    }
}

document.querySelector('[data-lecture-attachments] input[type="file"]')?.addEventListener('change', event => {
    document.querySelector('[data-attachment-selection]').textContent = [...event.target.files].map(file => file.name).join('، ') || 'يمكنك اختيار عدة ملفات معاً.';
});
