@extends('admin.layout', ['title' => 'مراجعة عواقب الحذف'])
@section('content')
@php($label = ['grade' => 'الصف بالكامل', 'course' => 'المادة', 'lesson' => 'الدرس'][$preview['kind']])
<div class="admin-head"><div><h1>راجع العواقب قبل الحذف</h1><p class="muted">{{ $preview['name'] }}</p></div><a class="btn" href="{{ $cancelUrl }}">إلغاء والعودة</a></div>
<dialog open class="content-delete-dialog" data-content-delete-dialog data-cancel-url="{{ $cancelUrl }}" aria-labelledby="delete-heading" aria-describedby="delete-consequences">
<div class="account-card-heading"><x-icon name="trash"/><h2 id="delete-heading" tabindex="-1" autofocus>حذف {{ $label }}؟</h2></div>@if($coverCourse)<x-course-image :src="$coverCourse->cover_image" :title="$coverCourse->title"/>@endif<p class="delete-content-name">{{ $preview['name'] }}</p>
<div id="delete-consequences"><p>راجع ما سيحدث قبل التأكيد:</p><ul>
@if($preview['kind'] === 'grade')<li>سيختفي الصف من قائمة الصفوف، وتُحذف مواده ودروسه من العرض.</li>@elseif($preview['kind'] === 'course')<li>ستختفي المادة وجميع دروسها من حسابات الطلاب ولوحة المحتوى.</li>@else<li>سيختفي الدرس، ولن يستطيع الطلاب فتح الفيديو أو المرفقات المرتبطة به.</li>@endif
<li>المحتوى المتأثر: {{ $preview['courses'] }} مادة، {{ $preview['lessons'] }} درس، {{ $preview['attachments'] }} مرفق. الطلاب المسجلون في محتواه: {{ $preview['students'] }}.</li>
<li>تُحفظ حسابات الطلاب واشتراكاتهم وموادهم الأخرى ونقاط المشاهدة.</li>
<li>الحذف أرشفة آمنة؛ تبقى الملفات والسجلات الأصلية محفوظة، ويمكن استرجاع العنصر من صفحة الأرشيف.</li>
</ul></div>
<x-step-tip>إذا كنت تريد إخفاء المحتوى مؤقتاً فقط، ألغِ الحذف واستخدم حالة «مسودة» من صفحة تعديل المادة أو الدرس.</x-step-tip>
@if($errors->any())<div class="errors" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ $action }}">@csrf @method('DELETE')<input type="hidden" name="confirmation" value="{{ $nonce }}"><label class="delete-acknowledgement"><input type="checkbox" name="confirmed" value="1" required data-delete-acknowledgement><span>قرأت العواقب وأفهم أن المحتوى سيُحجب عن الطلاب.</span></label><div class="delete-dialog-actions"><a class="btn" href="{{ $cancelUrl }}">إلغاء، احتفظ بالمحتوى</a><button class="btn content-delete-button" data-delete-final><x-icon name="trash"/>تأكيد حذف {{ $label }}</button></div></form>
</dialog>
@endsection
