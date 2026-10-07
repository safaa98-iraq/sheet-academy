@php
    $childType = ['material' => 'part', 'part' => 'chapter', 'chapter' => 'section', 'section' => 'lesson'][$node->type] ?? null;
    $typeLabels = ['part' => 'جزء', 'chapter' => 'فصل', 'section' => 'قسم', 'lesson' => 'درس'];
    $statusLabels = ['draft' => 'مسودة', 'published' => 'منشور', 'scheduled' => 'مجدول'];
    $lesson = $node->type === 'lesson' ? $node->lesson : null;
@endphp
<li class="curriculum-node" data-curriculum-node data-node-id="{{ $node->id }}">
    <div class="curriculum-node-heading">
        <button type="button" class="drag-handle" aria-label="اسحب لإعادة الترتيب">⠿</button>
        <span class="curriculum-type">{{ $typeLabels[$node->type] ?? $node->type }}</span>
        <b>{{ $node->title }}</b>
        <span class="badge">{{ $statusLabels[$node->publication_status] ?? $node->publication_status }}</span>
    </div>
    <details class="curriculum-node-edit"><summary>تحرير {{ $typeLabels[$node->type] ?? 'المحتوى' }}</summary>
        <form class="admin-form curriculum-edit-form" method="post" action="{{ route('admin.curriculum-nodes.update', $node) }}">
            @csrf @method('PUT')
            <label>العنوان<input name="title" value="{{ $node->title }}" maxlength="180" required></label>
            <label>حالة النشر<select name="publication_status" required>@foreach($statusLabels as $value=>$label)<option value="{{ $value }}" @selected($node->publication_status===$value)>{{ $label }}</option>@endforeach</select></label>
            <label>موعد النشر المجدول<input type="datetime-local" name="scheduled_at" value="{{ $node->scheduled_at?->format('Y-m-d\TH:i') }}"></label>
            @if($lesson)
                <label>نوع الدرس<select name="content_type"><option value="video" @selected($lesson->type==='video')>فيديو</option><option value="text" @selected($lesson->type==='text')>نص</option><option value="file" @selected($lesson->type==='file')>ملف</option></select></label>
                <label>مدة الدرس بالثواني<input type="number" min="0" max="86400" name="duration_seconds" value="{{ $lesson->duration_seconds }}"></label>
                <label>وصف الدرس<textarea name="description" rows="2">{{ $lesson->description }}</textarea></label>
                <section class="video-upload-panel" data-video-upload data-start-url="{{ route('admin.lessons.video-uploads.start', $lesson) }}" data-status-url="{{ route('admin.lessons.video-status', $lesson) }}" data-retry-url="{{ route('admin.lessons.video-retry', $lesson) }}" data-resolution-url="{{ route('admin.lessons.video-resolutions', $lesson) }}" data-delete-resolution-url="{{ route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 0]) }}" data-csrf="{{ csrf_token() }}">
                    <div><b>فيديو الدرس</b><small data-video-status-text>{{ ['not_uploaded'=>'لم يُرفع','queued'=>'بانتظار المعالجة','processing'=>'قيد المعالجة','ready'=>'جاهز','failed'=>'فشل'][$lesson->video?->status ?? $lesson->video_status] ?? 'لم يُرفع' }}</small></div>
                    <label class="video-file-picker">اختر فيديو MP4 أو MOV أو WebM أو MKV<input type="file" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/*" data-video-file></label>
                    <button class="btn small primary" type="button" data-video-start>رفع / استئناف الرفع</button>
                    <progress data-video-progress max="100" value="0" aria-label="نسبة رفع الفيديو"></progress>
                    <p class="muted" data-video-message>حتى 10 جيجابايت. اترك الصفحة مفتوحة أثناء الرفع؛ يمكن استئنافه باختيار الملف نفسه.</p>
                    <div class="video-resolution-options" data-video-resolutions hidden>
                        @foreach(array_values(array_unique([1080, 720, 480, 360, 240, ...($lesson->video?->available_resolutions ?? [])])) as $resolution)
                            @if(in_array($resolution, $lesson->video?->available_resolutions ?? [], true))<div class="video-resolution-row" data-video-resolution-row><label><input type="checkbox" value="{{ $resolution }}" @checked(in_array($resolution, $lesson->video?->enabled_resolutions ?? [], true)) data-video-resolution> بث {{ $resolution }}p</label><button type="button" class="text-button" data-delete-video-resolution data-height="{{ $resolution }}">حذف ملفات الدقة</button></div>@endif
                        @endforeach
                        <button type="button" class="btn small" data-save-video-resolutions>حفظ الدقات المفعّلة</button>
                    </div>
                    <button class="btn small" type="button" data-video-retry hidden>إعادة معالجة الفيديو</button>
                </section>
                <label>النص المنسق
                    <div class="rich-text-toolbar"><button type="button" data-rich-command="bold"><b>عريض</b></button><button type="button" data-rich-command="italic"><i>مائل</i></button><button type="button" data-rich-command="insertUnorderedList">قائمة</button></div>
                    <div class="rich-text-surface" contenteditable="true" data-rich-editor>{!! \App\Support\SafeRichText::sanitize($lesson->body_html) !!}</div>
                    <textarea name="body_html" hidden data-rich-value></textarea>
                </label>
            @endif
            <button class="btn primary">حفظ التغييرات</button>
        </form>
        @if($lesson)
            <form id="attachment-upload-{{ $node->id }}" class="attachment-upload-form" method="post" enctype="multipart/form-data" action="{{ route('admin.lessons.attachments.store', $lesson) }}">
                @csrf<input type="file" name="attachments[]" accept="application/pdf,image/jpeg,image/png,image/webp" multiple required>
                <button class="btn small">رفع المرفقات المحددة</button>
            </form>
            @if($lesson->attachments->isNotEmpty())<ul class="private-attachments">@foreach($lesson->attachments as $attachment)<li><a href="{{ route('admin.lesson-attachments.show', $attachment) }}">{{ $attachment->original_name }}</a><small>{{ number_format($attachment->size_bytes/1024, 0) }} KB · {{ $attachment->mime_type }}</small><form method="post" action="{{ route('admin.lesson-attachments.destroy', $attachment) }}" data-confirm="حذف هذا المرفق؟">@csrf @method('DELETE')<button class="text-button">حذف</button></form></li>@endforeach</ul>@endif
        @endif
    </details>
    <div class="curriculum-node-actions">
        @if($childType)
            <form class="curriculum-create" method="post" action="{{ route('admin.courses.curriculum.store', $course) }}">
                @csrf<input type="hidden" name="parent_id" value="{{ $node->id }}"><input type="hidden" name="type" value="{{ $childType }}">
                <input name="title" maxlength="180" placeholder="عنوان {{ $typeLabels[$childType] }} جديد" aria-label="عنوان {{ $typeLabels[$childType] }} جديد" required>
                @if($childType==='lesson')<select name="content_type"><option value="video">فيديو</option><option value="text">نص</option><option value="file">ملف</option></select>@endif
                <input type="hidden" name="publication_status" value="draft"><button class="btn small">＋ إضافة {{ $typeLabels[$childType] }}</button>
            </form>
        @endif
        <form method="post" action="{{ route('admin.curriculum-nodes.destroy', $node) }}" data-confirm="سيُحذف المحتوى والفروع تحته حذفاً ناعماً. هل تريد المتابعة؟">
            @csrf @method('DELETE')<button class="btn small">حذف</button>
        </form>
    </div>
    @if($node->children->isNotEmpty())
        <ol class="curriculum-tree" data-curriculum-list data-parent-id="{{ $node->id }}">
            @foreach($node->children as $child)@include('admin.curriculum._node', ['node' => $child])@endforeach
        </ol>
    @elseif($childType)
        <ol class="curriculum-tree" data-curriculum-list data-parent-id="{{ $node->id }}"></ol>
    @endif
</li>
