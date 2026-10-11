                <section class="video-upload-panel" data-video-upload data-start-url="{{ route('admin.lessons.video-uploads.start', $lesson) }}" data-status-url="{{ route('admin.lessons.video-status', $lesson) }}" data-retry-url="{{ route('admin.lessons.video-retry', $lesson) }}" data-resolution-url="{{ route('admin.lessons.video-resolutions', $lesson) }}" data-delete-resolution-url="{{ route('admin.lessons.video-resolutions.delete', ['lesson' => $lesson, 'resolution' => 0]) }}" data-csrf="{{ csrf_token() }}">
                    <div><b>فيديو الدرس</b><small data-video-status-text role="status">{{ ['not_uploaded'=>'لم يُرفع','queued'=>'بانتظار المعالجة','processing'=>'قيد المعالجة','ready'=>'جاهز','failed'=>'فشل'][$lesson->video?->status ?? $lesson->video_status] ?? 'لم يُرفع' }}</small></div>
                    <label class="video-file-picker">اختر فيديو MP4 أو MOV أو WebM أو MKV<input type="file" accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/*" data-video-file></label>
                    <button class="btn small primary" type="button" data-video-start><x-icon name="upload"/> رفع / استئناف الرفع</button>
                    <progress data-video-progress max="100" value="0" aria-label="نسبة رفع الفيديو"></progress>
                    <p class="muted" data-video-message role="status">محاضرة نصف ساعة أو أطول، حتى 10 جيجابايت حسب مساحة التخزين المتاحة. نرفع بدفعات 512 كيلوبايت. اترك الصفحة مفتوحة أو اختر الملف نفسه خلال 7 أيام لاستئنافه.</p>
                    <div class="video-resolution-options" data-video-resolutions hidden>
                        @foreach(array_values(array_unique([1080, 720, 480, 360, 240, ...($lesson->video?->available_resolutions ?? [])])) as $resolution)
                            @if(in_array($resolution, $lesson->video?->available_resolutions ?? [], true))<div class="video-resolution-row" data-video-resolution-row><label><input type="checkbox" value="{{ $resolution }}" @checked(in_array($resolution, $lesson->video?->enabled_resolutions ?? [], true)) data-video-resolution> بث {{ $resolution }}p</label><button type="button" class="text-button" data-delete-video-resolution data-height="{{ $resolution }}">حذف ملفات الدقة</button></div>@endif
                        @endforeach
                        <span class="muted" data-save-video-resolutions>الدقات المختارة تُحفظ مع زر النشر في نهاية النموذج.</span>
                    </div>
                    <button class="btn small" type="button" data-video-retry hidden>إعادة معالجة الفيديو</button>
                </section>
