@extends('admin.layout')
@section('content')
<div class="admin-head"><div><span class="eyebrow">مساحة الأستاذ</span><h1>إدارة الطلاب <span class="badge">{{ $students->total() }}</span></h1><p class="muted">الحسابات والمواد ورموز الدخول، في مكان واحد.</p></div>@can('create', \App\Models\Student::class)<a class="btn primary" href="{{ route('admin.students.create') }}"><x-icon name="plus"/><span>إضافة طالب</span></a>@endcan</div>
@if($issuedToken)
<section class="token-once"><b>تم إصدار توكن للطالب {{ $issuedToken['student'] }}</b><p>يمكنك العودة لعرضه ونسخه من ملف الطالب في أي وقت.</p><code id="issuedToken" class="student-token-value">{{ $issuedToken['token'] }}</code><button class="btn" type="button" data-copy-target="#issuedToken"><x-icon name="copy"/><span data-copy-label>نسخ التوكن</span></button></section>
@endif
<aside class="teacher-hint"><x-icon name="book"/><div><b>كل ما يخص الطالب في ملف واحد</b><p>اضغط «إدارة الطالب» لعرض بياناته ومواده وتقدّمه، أو تعديل حسابه والتحكم برمز الدخول.</p></div></aside>
<section class="students-panel">
<form class="students-filters" method="get"><label>البحث عن طالب<input type="search" name="q" value="{{ request('q') }}" placeholder="اسم الطالب أو بريده الإلكتروني"></label><label>حالة الحساب<select name="status"><option value="">كل الحالات</option>@foreach(['active'=>'نشط','frozen'=>'مجمّد','suspended'=>'موقوف'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label><button class="btn primary"><x-icon name="search"/><span>بحث</span></button><a class="btn" href="{{ route('admin.students.index') }}"><x-icon name="refresh"/><span>إعادة ضبط</span></a></form>
<div class="table-wrap students-table"><table><thead><tr><th>الطالب</th><th>حالة الحساب</th><th>توكن الدخول</th><th>إجراءات</th></tr></thead><tbody>
@forelse($students as $student)
@php($token = $student->tokens->first())
<tr>
<td data-label="الطالب"><a class="student-name" href="{{ route('admin.students.show', $student) }}"><span class="student-initial">{{ mb_substr($student->name,0,1) }}</span><b>{{ $student->name }}</b></a><small class="muted student-email">{{ $student->email ?: 'بدون بريد إلكتروني' }}</small></td>
<td data-label="حالة الحساب"><span class="badge {{ $student->status==='active'?'published':'draft' }}">{{ ['active'=>'نشط','frozen'=>'مجمّد','suspended'=>'موقوف'][$student->status] ?? $student->status }}</span><small class="student-cell-note muted">آخر دخول: {{ $token?->last_used_at?->diffForHumans() ?? 'لم يدخل بعد' }}</small></td>
<td data-label="توكن الدخول"><small class="student-cell-note muted">{{ !$token ? 'لا يوجد توكن' : ($token->expires_at?->isPast() ? 'منتهي الصلاحية' : (['active'=>'توكن فعال','frozen'=>'توكن مجمّد','suspended'=>'توكن موقوف','revoked'=>'توكن ملغى'][$token->status] ?? $token->status)) }}</small></td>
<td data-label="إجراءات"><a class="btn small" href="{{ route('admin.students.show', $student) }}" aria-label="عرض بيانات وإجراءات {{ $student->name }}"><x-icon name="user"/><span>إدارة الطالب</span></a></td>
</tr>
@empty<tr><td colspan="4"><div class="students-empty"><h2>لا يوجد طلاب مطابقون</h2><p class="muted">غيّر البحث أو أضف حساب طالب جديد.</p></div></td></tr>@endforelse
</tbody></table></div></section>
{{ $students->links() }}
<aside class="teacher-hint"><x-icon name="shield"/><div><b>متى تستخدم التجميد؟</b><p>لإيقاف الوصول مؤقتاً مع الاحتفاظ ببيانات الطالب وتقدّمه. يمكنك إعادة تفعيل الحساب لاحقاً من ملفه.</p></div></aside>

@endsection
