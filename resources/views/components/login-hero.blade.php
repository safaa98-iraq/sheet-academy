@props(['audience' => 'student'])
<section class="welcome">
    <a class="brand" href="{{ route('student.login') }}">
        <span class="brand-mark"><x-icon name="tooth"/></span>
        <span>عيادة التعلّم<small>معرفة تصنع الفرق</small></span>
    </a>
    <div class="welcome-copy">
        <span class="welcome-label"><span class="status-dot"></span>{{ $audience === 'admin' ? 'مساحة الأستاذ وفريق الإدارة' : 'مساحتك لتتعلّم، وتتميّز' }}</span>
        <h1>{{ $audience === 'admin' ? 'اصنع معرفة.' : 'تعلّم بعمق.' }}<br><span>{{ $audience === 'admin' ? 'واترك أثراً.' : 'وتقدّم بثقة.' }}</span></h1>
        <p>{{ $audience === 'admin' ? 'محتوى منظّم، وطلاب أقرب، ورؤية واضحة لكل خطوة في رحلة التعلّم.' : 'رحلتك في طب الأسنان، في مكان واحد. دروس منظّمة وتجربة تعلّم تمضي على إيقاعك.' }}</p>
        <div class="welcome-pills"><span><x-icon name="book"/>{{ $audience === 'admin' ? 'إدارة المحتوى' : 'شرح واضح' }}</span><span><x-icon name="chart"/>متابعة التقدّم</span><span><x-icon name="shield"/>مساحة آمنة</span></div>
    </div>
    <div class="welcome-visual" aria-hidden="true">
        <div class="welcome-orbit orbit-outer"></div><div class="welcome-orbit orbit-inner"></div>
        <div class="welcome-dental"><x-course-art variant="dental"/></div>
        <div class="welcome-float float-lesson"><span class="float-icon"><x-icon name="book"/></span><div><small>معرفة تبني ثقتك</small><strong>كل تفصيل يستحق الفهم</strong></div></div>
        <div class="welcome-float float-progress"><span class="float-icon"><x-icon name="check"/></span><div><small>خطوة وراء خطوة</small><strong>رحلة مستمرة نحو التميّز</strong></div></div>
    </div>
    <div class="welcome-foot"><span>خطوة اليوم، تصنع طبيب الغد.</span><span>منصة تعليم طب الأسنان</span></div>
</section>
