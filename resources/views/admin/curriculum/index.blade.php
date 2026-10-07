@extends('admin.layout')
@section('content')
<div class="admin-head">
    <div><span class="eyebrow">{{ $course->title }}</span><h1>منهج المادة</h1><p class="muted">رتّب الأجزاء والفصول والأقسام والدروس بالسحب والإفلات. تُحفظ التغييرات مباشرة.</p></div>
    <div class="admin-head-actions"><a class="btn" href="{{ route('admin.courses.preview', $course) }}">معاينة كطالب</a><a class="btn" href="{{ route('admin.courses.edit', $course) }}">إعدادات المادة</a></div>
</div>
<section class="admin-panel curriculum-editor" data-curriculum-editor data-sort-url="{{ route('admin.courses.curriculum.order', $course) }}" data-csrf="{{ csrf_token() }}">
    <div class="curriculum-root"><x-icon name="book"/><div><b>{{ $root->title }}</b><small>المادة · {{ $course->lessons()->count() }} دروس</small></div></div>
    <form class="curriculum-create" method="post" action="{{ route('admin.courses.curriculum.store', $course) }}">
        @csrf<input type="hidden" name="parent_id" value="{{ $root->id }}"><input type="hidden" name="type" value="part">
        <input name="title" maxlength="180" placeholder="عنوان الجزء الجديد" aria-label="عنوان الجزء الجديد" required>
        <input type="hidden" name="publication_status" value="draft"><button class="btn primary">＋ إضافة جزء</button>
    </form>
    <ol class="curriculum-tree" data-curriculum-list data-parent-id="{{ $root->id }}">
        @forelse($tree as $node)
            @include('admin.curriculum._node', ['node' => $node])
        @empty
            <li class="curriculum-empty">لم تُضف أجزاء للمادة بعد. ابدأ بإنشاء الجزء الأول.</li>
        @endforelse
    </ol>
</section>
<p class="admin-editor-help">النص المنسق يسمح بالتنسيق الأساسي فقط. تُخزّن المرفقات في مساحة خاصة ولا تُفتح إلا بعد التحقق من تسجيل الطالب في المادة.</p>
<script type="application/json" id="curriculum-labels">{"error":"تعذر حفظ ترتيب المنهج. أعد تحميل الصفحة وحاول مجدداً.","saved":"تم حفظ ترتيب المنهج."}</script>
@endsection
