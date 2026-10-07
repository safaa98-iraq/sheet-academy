@props(['curriculum', 'currentId'])
<aside id="lesson-curriculum" {{ $attributes->merge(['class' => 'curriculum-sidebar']) }} aria-label="محتوى المادة">
    <div class="curriculum-heading"><div><h2>محتوى المادة</h2><p><span data-curriculum-completed>0</span> من {{ count($curriculum) }} دروس مكتملة</p></div><button type="button" class="icon-btn" data-curriculum-close aria-label="طي محتوى المادة">×</button></div>
    <div class="curriculum-progress"><span data-curriculum-bar></span></div>
    <div class="curriculum-sections">
        @forelse(array_chunk($curriculum, 4) as $sectionIndex => $sectionLessons)
            <details class="curriculum-section" open>
                <summary><span class="curriculum-chevron" aria-hidden="true">⌄</span><div><strong>الجزء {{ $sectionIndex + 1 }}: {{ ['المفاهيم الأساسية', 'الفهم والتطبيق', 'المراجعة السريرية'][$sectionIndex % 3] }}</strong><small>{{ count($sectionLessons) }} دروس · {{ (int) ceil(array_sum(array_column($sectionLessons, 'duration')) / 60) }} دقيقة</small></div></summary>
                <div class="curriculum-chapter"><span>الفصل {{ $sectionIndex + 1 }} · القسم الأول</span></div>
                @foreach($sectionLessons as $curriculumLesson)
                    <a class="curriculum-lesson {{ (string) $curriculumLesson['id'] === (string) $currentId ? 'is-current' : '' }}" href="{{ $curriculumLesson['url'] }}" data-curriculum-lesson="{{ $curriculumLesson['id'] }}" @if((string) $curriculumLesson['id'] === (string) $currentId) aria-current="page" @endif>
                        <span class="lesson-check" data-lesson-check aria-hidden="true"></span><div><strong>{{ $curriculumLesson['title'] }}</strong><small><span aria-hidden="true">{{ in_array($curriculumLesson['type'] ?? 'video', ['video', 'فيديو']) ? '▷' : '▤' }}</span> {{ in_array($curriculumLesson['type'] ?? 'video', ['video', 'فيديو']) ? 'فيديو' : 'ملزمة' }} · {{ gmdate('i:s', (int) $curriculumLesson['duration']) }}</small></div>
                        @if((string) $curriculumLesson['id'] === (string) $currentId)<span class="lesson-playing" aria-label="الدرس الحالي">▥</span>@endif
                    </a>
                @endforeach
            </details>
        @empty
            <div class="curriculum-empty"><h3>المحتوى قيد التجهيز</h3><p>ستظهر الدروس الجديدة هنا.</p></div>
        @endforelse
    </div>
</aside>
