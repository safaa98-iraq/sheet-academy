@props(['audience' => 'student'])
@php($role = $audience === 'teacher' ? 'teacher' : 'student')
<aside class="guide-download-banner" aria-label="دليل الاستخدام" data-guide-download>
    <span class="guide-download-icon" aria-hidden="true"><x-icon name="book"/></span>
    <div><strong>{{ $role === 'teacher' ? 'دليل الأستاذ' : 'دليل الطالب' }}</strong><p>افتح دليل الاستخدام المصوّر واحتفظ بنسخة PDF للرجوع إليها.</p></div>
    <a class="btn primary" href="{{ asset('guides/'.$role.'-guide.pdf') }}?v=20261013-replacement" download><x-icon name="download"/>تحميل {{ $role === 'teacher' ? 'دليل الأستاذ' : 'دليل الطالب' }} · PDF</a>
</aside>
