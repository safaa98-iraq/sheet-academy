                <section class="video-upload-panel" data-video-upload data-start-url="{{ route('admin.lessons.video-uploads.start', $lesson) }}" data-status-url="{{ route('admin.lessons.video-status', $lesson) }}" data-retry-url="{{ route('admin.lessons.video-retry', $lesson) }}" data-resolution-url="{{ route('admin.lessons.video-resolutions', $lesson) }}" data-delete-resolution-url="{{ route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 0]) }}" data-csrf="{{ csrf_token() }}">
                    <div><b>فيديو الدرس</b><small data-video-status-text role="status">{{ ['not_uploaded'=>'لم يُرفع','queued'=>'بانتظار المعالجة','processing'=>'قيد المعالجة','ready'=>'جاهز','failed'=>'فشل'][$lesson->video?->status ?? $lesson->video_status] ?? 'لم يُرفع' }}</small></div>
                    <label class="video-file-picker">اختر فيديو {{ config('services.video_worker.mode') === 'direct' ? 'MP4 بترميز H.264 وصوت AAC' : 'MP4 أو MOV أو WebM أو MKV' }}<input type="file" accept="{{ config('services.video_worker.mode') === 'direct' ? 'video/mp4,.mp4' : 'video/mp4,video/quicktime,video/webm,video/x-matroska,video/*' }}" data-video-file></label>
                    <button class="btn small primary" type="button" data-video-start><x-icon name="upload"/> رفع / استئناف الرفع</button>
                    <progress data-video-progress max="100" value="0" aria-label="نسبة رفع الفيديو"></progress>
                    <p class="muted" data-video-message role="status">محاضرة نصف ساعة أو أطول، حتى 10 جيجابايت حسب مساحة التخزين المتاحة. نرفع بدفعات 512 كيلوبايت. اترك الصفحة مفتوحة أو اختر الملف نفسه خلال 7 أيام لاستئنافه.</p>
                    <div class="video-resolution-options" data-video-resolutions hidden>
                        @foreach(array_values(array_unique([1080, 720, 480, 360, 240, ...($lesson->video?->available_resolutions ?? [])])) as $resolution)
                            @if(in_array($resolution, $lesson->video?->available_resolutions ?? [], true))<div class="video-resolution-row" data-video-resolution-row><label><input type="checkbox" value="{{ $resolution }}" @checked(in_array($resolution, $lesson->video?->enabled_resolutions ?? [], true)) data-video-resolution> بث {{ $resolution }}p</label><button type="button" class="text-button" data-delete-video-resolution data-height="{{ $resolution }}">حذف ملفات الدقة</button></div>@endif
                        @endforeach
                        <span class="muted" data-save-video-resolutions>الدقات المختارة تُحفظ مع زر النشر في نهاية النموذج.</span>
                    </div>
                    @if(config('services.video_worker.mode') === 'direct')
                    <x-step-tip>الفيديو يُعرض بجودته الأصلية؛ ارفع MP4 بترميز H.264 وصوت AAC. لتقليل الحجم، صدّر نسخة أصغر من جهازك قبل الرفع.</x-step-tip>
                    @else
                    <x-step-tip>لتحديث الدقات، حدد ما تريد إتاحته ثم احفظ بالنشر. إلغاء الاختيار يخفي الدقة عن الطالب؛ «حذف ملفات الدقة» يحذف نسختها ويحتاج إلى تأكيد. عند فشل التجهيز استخدم إعادة المعالجة بعد مراجعة الرسالة.</x-step-tip>
                    @endif
                    <button class="btn small" type="button" data-video-retry hidden>إعادة معالجة الفيديو</button>
                </section>
