@extends('student.layout')

@section('content')
@if(isset($documentPages))
@php($pageCount = count($documentPages))
<main class="document-viewer" data-document-viewer data-refresh-url="{{ $refreshUrl }}" data-student-watermark="{{ $lessonData['watermark'] }}" data-lesson-id="{{ $attachment->lesson_id }}" data-view-link="{{ request('view') }}">
    <div class="document-topbar"><a class="lesson-back" href="{{ $lessonData['url'] }}"><span aria-hidden="true">→</span> العودة للدرس</a><div><h1>{{ $documentTitle }}</h1><p>{{ $lessonData['course_title'] }} · {{ $pageCount }} صفحة</p></div><span class="document-secure"><span aria-hidden="true">▤</span> عرض محمي</span></div>
    <div class="document-toolbar"><div class="document-page-controls"><button type="button" class="icon-btn" data-viewer-previous aria-label="الصفحة السابقة">→</button><span>صفحة <b data-viewer-page>1</b> من <span data-viewer-page-count>{{ $pageCount }}</span></span><button type="button" class="icon-btn" data-viewer-next aria-label="الصفحة التالية">←</button></div><div class="document-zoom-controls"><button type="button" class="icon-btn" data-viewer-zoom="-1" aria-label="تصغير الصفحة">−</button><output data-viewer-zoom-label>100%</output><button type="button" class="icon-btn" data-viewer-zoom="1" aria-label="تكبير الصفحة">＋</button><button type="button" class="btn small" data-viewer-fit>ملاءمة الصفحة</button><button type="button" class="icon-btn" data-viewer-fullscreen aria-label="عرض بملء الشاشة">⛶</button></div></div>
    <div class="document-workspace">
        <aside class="document-thumbnails" aria-label="صفحات الملزمة">
            @foreach($documentPages as $documentPage)<button type="button" class="document-thumbnail {{ $loop->first ? 'is-current' : '' }}" data-viewer-goto="{{ $loop->index }}" aria-label="الصفحة {{ $documentPage['number'] }}" aria-current="{{ $loop->first ? 'page' : 'false' }}"><span class="document-thumbnail-sheet"><img src="{{ $documentPage['url'] }}" alt="" loading="lazy"><span class="document-thumbnail-number">{{ str_pad((string) $documentPage['number'], 2, '0', STR_PAD_LEFT) }}</span></span><strong>{{ $documentPage['number'] }}</strong></button>@endforeach
        </aside>
        <section class="document-canvas" tabindex="0" aria-label="صفحة الملزمة، استخدم الأسهم للتنقل">
            @foreach($documentPages as $documentPage)<div class="document-sheet document-image-sheet" data-document-page="{{ $loop->index }}" @if(!$loop->first) hidden @endif><img data-document-image src="{{ $documentPage['url'] }}" alt="{{ $documentTitle }}، الصفحة {{ $documentPage['number'] }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async" draggable="false">@foreach([18,50,82] as $vertical)<span class="document-watermark" data-document-watermark style="--watermark-y:{{ $vertical }}%">{{ $lessonData['watermark'] }}</span>@endforeach</div>@endforeach
        </section>
    </div>
    <p class="sr-only" data-viewer-announcement aria-live="polite"></p>
</main>
@else
@php($lessonData = $lessonData ?? ['url'=>route('student.course'), 'course_title'=>'مراجعة تشريح الأسنان', 'id'=>'viewer'])
<main class="document-viewer" data-document-viewer>
    <div class="document-topbar"><a class="lesson-back" href="{{ $lessonData['url'] }}"><span aria-hidden="true">→</span> العودة للدرس</a><div><h1>ملزمة تشريح الأسنان</h1><p>{{ $lessonData['course_title'] }} · 3 صفحات</p></div><span class="document-secure"><span aria-hidden="true">▤</span> عارض المرفقات</span></div>
    <div class="document-toolbar"><div class="document-page-controls"><button type="button" class="icon-btn" data-viewer-previous aria-label="الصفحة السابقة">→</button><span>صفحة <b data-viewer-page>1</b> من 3</span><button type="button" class="icon-btn" data-viewer-next aria-label="الصفحة التالية">←</button></div><div class="document-zoom-controls"><button type="button" class="icon-btn" data-viewer-zoom="-1" aria-label="تصغير الصفحة">−</button><output data-viewer-zoom-label>100%</output><button type="button" class="icon-btn" data-viewer-zoom="1" aria-label="تكبير الصفحة">＋</button><button type="button" class="btn small" data-viewer-fit>ملاءمة الصفحة</button><button type="button" class="icon-btn" data-viewer-fullscreen aria-label="عرض بملء الشاشة">⛶</button></div></div>
    <div class="document-workspace">
        <aside class="document-thumbnails" aria-label="صفحات الملزمة">@foreach(['بنية السن', 'طبقات وأنسجة', 'مراجعة سريعة'] as $pageTitle)<button type="button" class="document-thumbnail {{ $loop->first ? 'is-current' : '' }}" data-viewer-goto="{{ $loop->index }}" aria-label="الصفحة {{ $loop->iteration }}: {{ $pageTitle }}" aria-current="{{ $loop->first ? 'page' : 'false' }}"><span class="document-thumbnail-sheet"><b>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</b><i></i><i></i><span aria-hidden="true">◇</span><i></i></span><strong>{{ $loop->iteration }}</strong><small>{{ $pageTitle }}</small></button>@endforeach</aside>
        <section class="document-canvas" tabindex="0" aria-label="صفحة الملزمة، استخدم الأسهم للتنقل">
            @foreach(['بنية السن ووظائفه', 'طبقات السن والأنسجة المحيطة', 'مراجعة المفاهيم الأساسية'] as $pageTitle)
                <div class="document-sheet" data-document-page="{{ $loop->index }}" @if(!$loop->first) hidden @endif>
                    <svg viewBox="0 0 720 980" role="img" aria-label="{{ $pageTitle }}: رسم توضيحي لبنية السن، المينا والعاج واللب والجذور" xmlns="http://www.w3.org/2000/svg">
                        <rect width="720" height="980" fill="#fffefa"/>
                        <rect x="52" y="56" width="32" height="5" rx="2" fill="#a46734"/>
                        <text x="665" y="66" text-anchor="end" fill="#34443f" font-family="sans-serif" font-size="15">عيادة التعلّم</text>
                        <text x="665" y="125" text-anchor="end" fill="#202725" font-family="sans-serif" font-size="30" font-weight="bold">{{ $pageTitle }}</text>
                        <text x="665" y="158" text-anchor="end" fill="#77807a" font-family="sans-serif" font-size="14">تشريح الأسنان · الفصل {{ $loop->iteration }}</text>
                        <line x1="54" x2="666" y1="183" y2="183" stroke="#dde1db"/>
                        <rect x="54" y="220" width="612" height="416" rx="8" fill="{{ ['#eef3ef', '#f0efea', '#edf0f3'][$loop->index] }}"/>
                        <g transform="translate(192 219) scale(.92)">
                            <path d="M93 48C60 70 76 136 97 182c14 33 16 91 30 148 8 28 20 44 28 13 8-28 7-72 23-88 20-14 23 44 32 80 7 30 21 27 30-4 17-55 17-111 31-147 24-50 34-112-4-136-31-20-55-5-83 1-35 9-58-23-91-1Z" fill="#fcfcf6" stroke="#8b9f91" stroke-width="3"/>
                            <path d="M108 72c-23 18-6 59 9 99 12 33 17 75 22 109 8-44 15-68 39-69 28-2 36 37 43 68 6-37 13-76 27-109 16-39 24-80 1-96-24-14-42 5-67 5-28 0-52-23-74-7Z" fill="#e5d4ac"/>
                            <path d="M141 112c-10 10 0 31 10 56 9 20 17 34 21 57 2-40 10-45 14-45 7 0 13 19 17 42 5-24 11-44 20-65 11-27 5-49-9-43-17 7-17 10-32 9-19-1-30-21-41-11Z" fill="#cb9690"/>
                            <path d="m144 316 29-106m55 102-26-102" stroke="#ba817b" stroke-width="4" stroke-linecap="round"/>
                            <path d="m119 101-67-20H4m231 69 57-20h60m-166 46-113 42H5m221 92 69 16h56" fill="none" stroke="#647b6a" stroke-width="1.5"/>
                            <g fill="#425c4c" font-family="sans-serif" font-size="17"><text x="20" y="65">المينا</text><text x="319" y="115">العاج</text><text x="24" y="218">اللب</text><text x="311" y="313">الجذر</text></g>
                        </g>
                        <text x="646" y="681" text-anchor="end" fill="#34443f" font-family="sans-serif" font-size="22" font-weight="bold">{{ ['من البنية إلى الوظيفة', 'ما الذي يميّز كل طبقة؟', 'اختبر فهمك'][ $loop->index ] }}</text>
                        @foreach(['المينا: الطبقة الخارجية الصلبة التي تحمي تاج السن.', 'العاج: نسيج يدعم المينا ويحيط بالحجرة اللبية.', 'اللب: يحتوي على الأوعية الدموية والأعصاب.', 'الجذور: تثبّت السن داخل العظم السنخي.'] as $line)
                            <text x="646" y="{{ 725 + $loop->index * 35 }}" text-anchor="end" fill="#59645d" font-family="sans-serif" font-size="17">{{ $line }}</text>
                        @endforeach
                        <line x1="54" x2="666" y1="913" y2="913" stroke="#dde1db"/>
                        <text x="665" y="943" text-anchor="end" fill="#8b928a" font-family="sans-serif" font-size="12">ملزمة تعليمية تجريبية · د. سليم أحمد</text>
                        <text x="54" y="943" fill="#8b928a" font-family="sans-serif" font-size="12">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</text>
                        <g data-viewer-watermark fill="#647b6a" opacity=".15" font-family="sans-serif" font-size="28" text-anchor="middle" transform="rotate(-30 360 490)"><text x="360" y="360">عيادة التعلّم · {{ $lessonData['id'] }}</text><text x="360" y="520">عيادة التعلّم · {{ $lessonData['id'] }}</text><text x="360" y="680">عيادة التعلّم · {{ $lessonData['id'] }}</text></g>
                    </svg>
                </div>
            @endforeach
        </section>
    </div>
    <p class="sr-only" data-viewer-announcement aria-live="polite"></p>
</main>
@endif
@endsection
