@props(['curriculum', 'currentId', 'tree' => []])
<aside id="lesson-curriculum" {{ $attributes->merge(['class' => 'curriculum-sidebar']) }} aria-label="محتوى المادة">
    <div class="curriculum-heading"><div><h2>محتوى المادة</h2><p><span data-curriculum-completed>0</span> من {{ count($curriculum) }} دروس مكتملة</p></div><button type="button" class="icon-btn" data-curriculum-close aria-label="طي محتوى المادة">×</button></div>
    <div class="curriculum-progress"><span data-curriculum-bar></span></div>
    <div class="curriculum-sections">
        @forelse($tree as $node)
            @include('student._player-curriculum-node', ['node'=>$node, 'currentId'=>$currentId])
        @empty
            <div class="curriculum-empty"><h3>المحتوى قيد التجهيز</h3><p>ستظهر الدروس الجديدة هنا.</p></div>
        @endforelse
    </div>
</aside>
