@extends('admin.layout', ['title' => 'التنبيهات'])
@section('content')
<div class="admin-head"><div><span class="eyebrow">متابعة الطلاب</span><h1>تنبيهاتي</h1><p class="muted">انتهاء الاشتراك أو صلاحية الكود، وتنبيهات النشاط.</p></div></div>
<x-step-tip>عند انتهاء تفعيل الطالب يظهر تنبيه باسمه ورقم هاتفه. افتح بياناته لتحديد تاريخ تجديد جديد، أو أصدر كوداً بديلاً إذا انتهت صلاحية الكود نفسه. لا يتكرر تنبيه الانتهاء عند كل فحص.</x-step-tip>
<div class="student-notification-list">
@forelse($notifications as $notification)
    @php($data = $notification->data)
    <article class="student-notification {{ $notification->read_at ? '' : 'is-unread' }}">
        <span class="shortcut-icon"><x-icon name="{{ isset($data['kind']) ? 'clock' : 'shield' }}"/></span>
        <div>
            <span class="eyebrow">{{ isset($data['kind']) ? ($data['kind'] === 'subscription' ? 'انتهاء اشتراك' : 'انتهاء صلاحية الكود') : 'تنبيه نشاط' }}</span>
            <h2>{{ $data['student'] ?? 'تنبيه من المنصة' }}</h2>
            <p>{{ $data['summary'] ?? 'لديك تنبيه جديد.' }}</p>
            @if(isset($data['expired_at']))<p class="muted">انتهى في: <b dir="ltr">{{ \Illuminate\Support\Carbon::parse($data['expired_at'])->timezone('Asia/Baghdad')->format('Y-m-d H:i') }}</b> بتوقيت بغداد</p>@endif
            @if(!empty($data['phone']))<p>هاتف الطالب: <a class="text-link" dir="ltr" href="tel:{{ preg_replace('/[^+0-9]/', '', $data['phone']) }}">{{ $data['phone'] }}</a></p>@elseif(isset($data['kind']))<p class="muted">رقم الهاتف غير مسجّل؛ أضفه من بيانات الطالب.</p>@endif
            <small class="muted">{{ $notification->created_at->diffForHumans() }}</small>
            <div class="notification-actions">
                @if(isset($data['student_id']) && $data['student_id'])
                    @can('students.manage')<a class="btn small" href="{{ route('admin.students.edit', $data['student_id']) }}"><x-icon name="edit"/>تجديد التفعيل وبيانات الطالب</a>@endcan
                @endif
                @if(!isset($data['kind']))@can('audit.view')<a class="text-link" href="{{ route('admin.activity', ['student_id' => $data['student_id'] ?? null]) }}">فتح سجل النشاط</a>@endcan
                @endif
            </div>
        </div>
        @unless($notification->read_at)<form method="post" action="{{ route('admin.notifications.read', $notification->id) }}">@csrf<button class="btn small" type="submit">تمت القراءة</button></form>@endunless
    </article>
@empty
    <x-empty-state title="لا توجد تنبيهات" description="سيظهر تنبيه تلقائياً عند انتهاء تفعيل طالب، حتى لو لم يحاول تسجيل الدخول." icon="bell"/>
@endforelse
</div>
{{ $notifications->links() }}
@endsection
