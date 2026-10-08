@extends('admin.layout', ['title' => 'المحاضرات والمحتوى'])
@section('content')
<div class="admin-head">
    <div><span class="eyebrow">مساحة المحتوى</span><h1>المحاضرات والمحتوى</h1><p class="muted">اختر المرحلة ثم المادة، وأضف محاضرتك بكل محتوياتها في مكان واحد.</p></div>
    @can('courses.manage')<a class="btn" href="{{ route('admin.courses.create', ['grade_level_id' => request('grade_level_id')]) }}"><x-icon name="plus"/> مادة / كتاب جديد</a>@endcan
</div>
<form class="admin-form lecture-filters" method="get" action="{{ route('admin.lessons.index') }}" data-lecture-filters>
    <ol class="lecture-steps" aria-label="خطوات إضافة المحاضرة"><li><span>١</span> المرحلة الدراسية</li><li><span>٢</span> المادة / الكتاب</li><li><span>٣</span> المحاضرة</li></ol>
    <div class="lecture-filter-grid">
        <label>المرحلة الدراسية<select name="grade_level_id" data-lecture-grade><option value="">اختر المرحلة</option>@foreach($gradeLevels as $grade)<option value="{{ $grade->id }}" @selected((string) request('grade_level_id', $selectedCourse?->grade_level_id) === (string) $grade->id)>{{ $grade->name }}</option>@endforeach</select></label>
        <label>المادة / الكتاب<select name="course_id" data-lecture-course><option value="">اختر المادة أو الكتاب</option>@foreach($courses as $item)<option value="{{ $item->id }}" data-grade-id="{{ $item->grade_level_id }}" @selected($selectedCourse?->id === $item->id)>{{ $item->title }}{{ $item->grade_level_id ? '' : ' — غير مصنفة' }}</option>@endforeach</select></label>
        <button class="btn" type="submit"><x-icon name="search"/> عرض المحاضرات</button>
    </div>
    @if($gradeLevels->isEmpty())<p class="muted">ابدأ بإضافة مرحلة دراسية، ثم مادة أو كتاب.</p>@endif
    @can('courses.manage')<div class="lecture-setup-links"><a class="text-link" href="{{ route('admin.grade-levels.create') }}"><x-icon name="plus"/> إضافة مرحلة</a><a class="text-link" href="{{ route('admin.courses.create', ['grade_level_id' => request('grade_level_id')]) }}"><x-icon name="plus"/> إضافة مادة / كتاب</a></div>@endcan
</form>
@if($selectedCourse)
    <div class="admin-head"><div><h2>{{ $selectedCourse->title }}</h2><p class="muted">{{ $selectedCourse->gradeLevel?->name ?? 'مادة غير مصنفة' }} · {{ $selectedCourse->lessons->count() }} محاضرة</p></div><div class="admin-head-actions">@can('courses.manage')<a class="btn primary" href="{{ route('admin.courses.lessons.create', $selectedCourse) }}"><x-icon name="plus"/> إضافة محاضرة</a><a class="btn" href="{{ route('admin.courses.edit', $selectedCourse) }}"><x-icon name="settings"/> إعدادات المادة</a>@endcan</div></div>
    <div class="lecture-list">
        @forelse($selectedCourse->lessons as $item)
        <article class="lecture-card"><span class="lecture-card-icon"><x-icon :name="$item->video ? 'play' : 'file'"/></span><div class="lecture-card-copy"><h3>{{ $item->title }}</h3><p class="muted">{{ ['draft'=>'مسودة','published'=>'منشورة','scheduled'=>'مجدولة'][$item->publication_status] ?? 'مسودة' }} · {{ $item->attachments->count() }} مرفق @if($item->video) · {{ ['queued'=>'بانتظار معالجة الفيديو','processing'=>'الفيديو قيد المعالجة','ready'=>'الفيديو جاهز','failed'=>'تعذّرت معالجة الفيديو'][$item->video->status] ?? $item->video->status }}@endif</p></div><div class="admin-head-actions"><a class="btn small" href="{{ route('admin.courses.preview', ['course'=>$selectedCourse,'lesson'=>$item->id]) }}"><x-icon name="eye"/> معاينة</a>@can('courses.manage')<a class="btn small" href="{{ route('admin.lessons.edit', $item) }}"><x-icon name="edit"/> تحرير المحاضرة</a>@endcan</div></article>
        @empty
        <x-empty-state title="محاضرتك الأولى تبدأ هنا" description="أضف عنوان المحاضرة ونصها، ثم ارفع الفيديو والمرفقات." icon="book"/>
        @endforelse
    </div>
    @can('courses.manage')<details class="lecture-advanced"><summary>تنظيم متقدم للمنهج</summary><p class="muted">يمكنك تنظيم الأجزاء والفصول والأقسام عند الحاجة.</p><a class="btn small" href="{{ route('admin.courses.curriculum', $selectedCourse) }}">فتح تنظيم المنهج</a></details>@endcan
@else
    <x-empty-state title="اختر المادة لبدء العمل" description="محاضرات كل مادة تظهر هنا، ويمكنك إضافة وتحرير محتواها مباشرة." icon="layers"/>
@endif
@endsection
