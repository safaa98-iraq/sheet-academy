# التدقيق النهائي للجودة والأمن — منصة تعليم طب الأسنان

تاريخ الفحص: **8 أكتوبر 2026**. المستودع: `safaa98-iraq/sheet-academy`. أساس المراجعة هو شجرة `e77f33396f52eba19fe0070af17b37724cca9f2d`، المطابقة للدمج `9098cd01a8b827ddb4eb560db4bf3fe40bff8b5d` على `main`. فُتحت الملفات الفعلية، وشُغّلت الخدمات والاختبارات على النسخة المعدّلة. أعيد تنفيذ التحقق في هذه المراجعة على الملفات المعدلة؛ التقارير السابقة ليست دليلاً على نتائج هذه الجولة.

## 1) الملخص التنفيذي

**نتيجة القائمة: 66 من 71 بنداً ✅، بنسبة 93.0٪، و5 بنود ⚠️، وصفر بنود ❌.** النسبة تخص البنود المفصلة في الجدول، ولا تعني درجة أمان رياضية أو ضماناً ضد كل هجوم. البنود الجزئية تتعلق بضمانات مطلقة لا يقدمها المتصفح، وباختبارات أجهزة أصلية لم تتوافر في بيئة التنفيذ. لم أرفع حالتها إلى مكتملة لمجرد وجود كود.

النتائج النهائية: **116 اختبار PHP ناجحاً، 883 تحققاً على SQLite وعلى MySQL 8.0.46**؛ **9 اختبارات Node ناجحة**؛ **45 فحصاً مسجلاً بالمتصفح**؛ تجربة فعلية للرفع عبر tus، وقائمة BullMQ/Redis، وتحويل FFmpeg إلى HLS مشفّر بدقات 720/480/360 وتشغيل الناتج. البناء وPint وترجمة Blade وتهيئة route cache نجحت. فحص Composer وnpm للويب والعامل وElectron أعاد **صفر تنبيهات معروفة في وقت الفحص**. هذه النتيجة لا تثبت خلو الحزم من ثغرات غير معلنة.

أهم الإصلاحات المثبتة في فرع هذه المراجعة مقارنةً بـmain، بما فيها تعديلات محلية راجعتها ودمجتها ثم أعدت اختبارها:

- إزالة النسخة المشفّرة القابلة للاسترجاع لتوكن الطالب؛ بقي SHA-256 لتوكن عشوائي 256 بت، وعرض النص مرة واحدة عند الإصدار أو التجديد فقط. ترحيل مستقل يحذف عمود `encrypted_token`.
- فرض جهاز واحد حتى على التوكنات القديمة ذات حدود الأجهزة المتعددة، مع قفل صف الطالب أثناء إصدار التوكن وتسجيل الجهاز، وإبطال الروابط عند التجديد والتجميد والعبث بالبصمة. ترحيل إضافي يبطل كل جلسات الطلاب القديمة ويضبط device_limit=1؛ التقدم والدقات تبقى محفوظة.
- تشفير nonce رابط العرض بـLaravel Crypt، مع توثيق وترميز URL وتخزين التجزئة فقط؛ الرابط القديم يبطل عند التدوير والخروج. إيقاف الفيديو المحمّل مسبقاً عند أول نبضة حفظ تكتشف انتهاء الجلسة.
- جعل حماية الطالب إلزامية؛ تغيير تفضيل `localStorage` إلى false لا يعطلها. توسيع فحص البصمة للحذف والتغيير والشفافية والأسلاف وطبقة التغطية وتغييرات stylesheet، مع توقف محلي وإبطال الرابط على السيرفر.
- إضافة بصمة مدمجة في **بيانات صور الملازم والصور** من السيرفر، إلى جانب البصمة المتحركة. إصلاح فحص العارض الذي كان يرفض الصورة قبل اكتمال تحميلها.
- منع الأدمن المفوّض من منح صلاحيات لا يملكها، ومنع تزوير IP/Host بواسطة ترويسات البروكسي غير الموثوق، ومعالجة حقن الصيغ في تصدير CSV.
- منع تسريب دروس منشورة تقع تحت فروع مخفية في بيانات صفحات المادة؛ استخدام شجرة المنهج الحقيقية في سايدبار المشغّل بدلاً من مجموعات ثابتة وهمية.
- إصلاح نقطة بدء HLS عند الاستئناف، وصيغة boolean في beacon عند مغادرة الصفحة، وربط زر قائمة التشغيل بالسيرفر، ودعم روابط الدقات المنفردة للمشغّل الأصلي، وإضافة ملء شاشة داخل الصفحة عند غياب API الأصلي.
- فصل عدادات التنقل والمشاهدة والإشارات الأمنية دون حذف حدود الطلبات. عند إغراق endpoint النشاط يسجّل السيرفر مخالفة ويُبطل رابط العرض؛ تُجمع مخالفة الإغراق مرة واحدة في الدقيقة لتجنب تضخيم سجل التنبيهات.
- تقييد بروتوكولات FFmpeg/ffprobe بالملفات المحلية، وإضافة مهلة وحد أبعاد للمعالجة؛ إصلاح تنبيهات الحزم، وحذف 1247 ملف dependencies مولداً من تتبع Git مع الإبقاء على lockfile و`npm ci`.
- عزل أقراص Storage::fake لكل عملية اختبار؛ منع تصادم تشغيل SQLite وMySQL معاً الذي كان يؤدي إلى404 وهمي لملفات الاختبار.
- توحيد ألوان الوضعين المحايدة مع البرتقالي، وتعريب النصوص والرسائل العامة وصفحات الأخطاء، وتصحيح متطلبات PHP في README ودليل VPS، وأحجام المرفقات والمجدول وتثبيت العامل في دليل VPS.

### الجرد الفعلي

| الجزء | الدليل والحجم |
|---|---|
| ملفات التطبيق والقوالب والإعداد والعامل وElectron | `docs/audit/inventory.txt`: 291 ملفاً في وقت الجرد؛ لا يشمل vendor أو node_modules أو الملفات المولدة |
| المسارات | `docs/audit/routes.json`: 104 مسارات بعد استبعاد vendor؛ الأمر `php artisan route:list --except-vendor --json` |
| الترحيلات | 29 ملفاً في `database/migrations`؛ حالة قاعدة التطوير الفعلية في `docs/audit/migrations.txt`؛ الاختبارات أنشأت مخططاً نظيفاً أيضاً على MySQL |
| المصادقة والصلاحيات | `app/Http/Controllers/Auth`, `app/Http/Middleware`, `app/Policies`, `app/Models/User.php`, `database/seeders/PermissionsAndRolesSeeder.php` |
| الخدمات | `app/Services/{StudentTokenService,StudentAuditService,StudentDocumentPageService,LessonProgressService}.php` و`app/Video/{VideoUploadService,VideoProcessingGateway,VideoStreamService}.php` |
| البث والمعالجة | `video-worker/index.js`, `video-worker/processor.js`, `app/Http/Controllers/Internal/VideoProcessingController.php` |
| الواجهة | `resources/views/{admin,student,components,errors}`, `resources/js/{player,viewer,watermark-guard,protection,progress-store,app,pwa}.js` |
| التطبيق والأدلة | `public/{manifest.json,sw.js,offline.html}`, `electron/main.cjs`, `docs/PHASE_7_VPS_AND_DESKTOP_AR.md` |

الجرد لا يعرض `.env` أو مفاتيح فعلية أو توكنات الطلاب. `audit-fixture.php` يرفض بيئة الإنتاج، ويضع أسرار اختبار المتصفح في ملف مؤقت بصلاحية 0600 خارج المستودع.

## 2) جدول التحقق التفصيلي

المعنى: ✅ منفّذ ومتحقق ضمن النطاق المذكور؛ ⚠️ منفّذ جزئياً مع سبب صريح؛ ❌ غير منفّذ. لكل صف حالة نهائية واحدة. أسماء الاختبارات أدناه موجودة في `tests/Feature` ما لم يذكر غير ذلك. يمكن تتبع نتائج التنفيذ في `docs/audit/verification.json` و`docs/audit/browser-results.json`.

| الرقم | المتطلب | الحالة | الدليل الفعلي | التعديل أو الملاحظة |
|---|---|---|---|---|
| أ-1.1 | معرّف فريد للطالب فوق كل فيديو | ✅ | `app/Services/StudentAuditService.php::watermarkText`؛ `resources/views/components/video-player.blade.php`؛ اختبار `test_server_watermark_identifier_is_unique_and_ignores_client_input` | HMAC من السيرفر مع رقم الطالب؛ لا يُعرض توكن الدخول |
| أ-1.2 | حركة عشوائية وشفافية وحجم مقروء | ✅ | `resources/js/player.js`: تحريك كل 10 ثوانٍ؛ `resources/css/player.css`: opacity .48، حد خط 12px؛ browser: normal/mobile | ضبط الحدود حسب أبعاد العلامة والمشغّل |
| أ-1.3 | العادي، ملء الشاشة، تغيير الدقة، الاستئناف، الموبايل | ✅ | `tests/Browser/security-audit.cjs`: normal/fullscreen/fallback/quality/mobile/server resume | اختبارات فعلية على Chromium 1440×1000 و390×844؛ تأهيل الأجهزة الأخرى في هـ-7 |
| أ-1.4 | رصد سيناريوهات حذف/إخفاء/تغطية البصمة وإيقاف الفيديو وتسجيلها | ✅ | `guardWatermark`؛ `app/Http/Controllers/Student/StudentActivityController.php::store`؛ browser: remove/display/opacity/text/ancestor/cover/stylesheet؛ الرابط يعيد 404 | MutationObserver وفحص computed style/hit test دوري؛ أُبطل الرابط بعد الاستلام |
| أ-1.5 | ضمان اكتشاف كل عبث ممكن من DevTools | ⚠️ | السيناريوهات السبعة نجحت؛ الكود في `resources/js/watermark-guard.js` يعمل داخل المتصفح الذي يسيطر عليه المستخدم | تعطيل JavaScript أو أدوات الالتقاط/طبقات خاصة قد تتجاوز الرصد؛ البديل موضح في 4 و5 |
| أ-1.6 | توليد السيرفر وعدم قبول معرّف يختاره الطالب | ✅ | `app/Services/StudentAuditService.php::watermarkText` يستخدم HMAC-SHA256 وAPP_KEY وهوية المصادقة؛ endpoint النشاط لا يستقبل نص بصمة | تغيير النص محلياً يوقف العرض في الاختبار؛ اختراق العميل كاملاً حد مستقل |
| أ-1.7 | FFmpeg watermark شخصي أو توثيق سبب عدم تفعيله | ✅ | `docs/VIDEO_STREAMING_DEPLOYMENT_AR.md`؛ `video-worker/processor.js::processVideo`؛ تفصيل القسم 4 | الدمج الشخصي للفيديو **غير مفعّل**؛ توثيق تكلفة نسخ HLS لكل طالب والبديل؛ صور الملازم مدمجة فعلياً |
| أ-1.8 | البصمة نفسها في عارض الملازم والصور | ✅ | `app/Services/StudentDocumentPageService.php::watermarkedPage`؛ `app/Http/Controllers/Student/DocumentViewerController.php::page`؛ browser PDF/image/fullscreen/tamper | HMAC نفسه داخل PNG من السيرفر وطبقة متحركة؛ اختبار يقارن بكسلات الأصل والناتج |
| أ-2.1 | عدم تقديم MP4/WebM أو الأصل للطالب | ✅ | مسح `public` و`storage/app/public`: صفر أصل/مفتاح/PDF؛ `app/Http/Controllers/Student/VideoStreamController.php::asset` يقبل m3u8/ts فقط | الأصول `.part` تبقى في private للمعالجة؛ لا route طالب يقدمها |
| أ-2.2 | HLS AES-128 فقط ومفتاح فريد لكل فيديو | ✅ | `processor.js::processVideo`: `randomBytes(16)` وhls_key_info_file؛ playlist الفعلية METHOD=AES-128؛ smoke | 720/480/360 من فيديو حقيقي؛ كل الدقات تشترك في مفتاح ذلك الفيديو الخاص |
| أ-2.3 | endpoint المفتاح لجلسة صحيحة ومادة مخصصة | ✅ | `app/Http/Controllers/Student/VideoStreamController.php::{key,authorizeStream}`؛ route `student.video.key`: signed + token/device/view-link + Gate | Guest 302/JSON 401، طالب آخر 404، توكن منتهي 302 |
| أ-2.4 | توقيع playlists/segments قصير العمر | ✅ | `app/Video/VideoStreamService.php::rewritePlaylist`؛ `app/Http/Controllers/Student/VideoStreamController.php::session`؛ middleware signed | صلاحية 8 دقائق؛ التعديل والانتهاء يعيدان 403؛ تجديد العميل كل 5 دقائق |
| أ-2.5 | تنفيذ محاولات أ-2 السبع فعلياً | ✅ | جدول القسم 3؛ browser script و`FinalSecurityAuditTest::test_private_media_refuses_missing_expired_unassigned_and_forged_access` | اختبارات HTTP ومحتوى مشفّر حقيقي؛ لا افتراض اعتماداً على وجود middleware |
| أ-2.6 | لا زر تنزيل؛ تعطيل PiP وقائمة السياق | ✅ | `<video controlslist="nodownload noremoteplayback" disablepictureinpicture ...>` في video-player؛ player/protection contextmenu؛ browser | لا controls أصلية أو رابط أصل؛ ذلك لا يمنع أدوات الشبكة المصرح لها |
| أ-2.7 | CORS وHotlink protection | ✅ | `app/Http/Middleware/AddSecurityHeaders.php::handle`: رفض Origin مختلف/cross-site وCORP same-origin؛ اختبار API لا ACAO | محاولة أصل آخر 403، مع رؤوس أمن على رد الرفض |
| أ-2.8 | منع تنزيل الفيديو حتى من جلسة مشاهدة مصرح بها | ⚠️ | browser فك مقطع حقيقي بالمفتاح الصحيح ونجح ffprobe؛ بدون المفتاح فشل | AES-HLS ليس DRM؛ لا يمكن ادعاء منع استخراج مطلق من جهاز يملك مفتاح فك التشفير |
| أ-2.9 | المفتاح لا يخزّن علناً | ✅ | `processor.js`: ملفات 0600 ومجلدات 0700؛ `config/filesystems.php`: private خارج public/local.serve=false؛ `SecurityHeadersTest` | لا keyinfo علني؛ تزال ملفات keyinfo بعد التحويل |
| أ-2.10 | فحص HTML/JSON بحثاً عن رابط أصل الفيديو أو PDF | ✅ | browser يفحص كل HTML/JSON أثناء زيارة التعلم والمادة والدرس والتقدم والقائمة والعارض؛ النتيجة صفر | يدعمه فحص serializer `app/Support/LearningUi.php::course`؛ الفحص يغطي السيناريوهات والمسارات الطالبية المفحوصة، لا كل استجابة مستقبلية |
| أ-3.1 | تحديد/نسخ/قص/لصق/سحب/زر أيمن/طباعة/اختصارات | ✅ | `resources/js/protection.js::initProtection`؛ CSS print؛ browser cancellation وwindow.print وF12 | حماية إلزامية للطالب؛ استثناء حقول التحرير فقط؛ إلغاء الاستثناء العام data-protection-exempt |
| أ-3.2 | viewer صفحات صور مؤقتة بدون رابط PDF مباشر | ✅ | `app/Services/StudentDocumentPageService.php::{pages,renderPdf}` يستخدم pdfinfo/pdftoppm؛ `DocumentViewerController` | PDF حقيقي حُوّل إلى PNG خاص؛ سقف 60 صفحة، روابط 5 دقائق |
| أ-3.3 | مرفقات خارج public وصلاحية controller | ✅ | `UploadLessonAttachmentsRequest`؛ `app/Http/Controllers/LessonAttachmentController.php::store`؛ `app/Http/Controllers/Student/DocumentViewerController.php::{show,page}`؛ `ContentManagementTest` | الطالب يشاهد النسخة المحمية، والأستاذ وحده يصل للمرفق الأصلي بصلاحيته |
| أ-3.4 | إرسال وتسجيل محاولات النسخ والطباعة | ✅ | `app.js::auditEvent`، `app/Http/Controllers/Student/StudentActivityController.php::store`، `app/Services/StudentAuditService.php::record`؛ browser وأثر audit | التجميع المحلي لكل نوع خلال 3.5 ثوانٍ؛ إغراق السيرفر يُسجّل ويوقف الرابط بدلاً من إسقاط الحماية بصمت |
| أ-3.5 | اكتشاف كل فتح DevTools مهما كانت طريقته | ⚠️ | shortcut F12 أوقف الفيديو وسجّل suspicious_devtools؛ protection يستخدم فرق أبعاد النافذة | الأداة المنفصلة/remote debugging لا يمكن كشفها بثقة في صفحة ويب؛ القياس heuristic وقد يخطئ |
| أ-3.6 | منع أي نسخ أو تنزيل للنص أو الصورة التي ظهرت للمستخدم | ⚠️ | HTML المصرح به وصورة PNG متاحة في ذاكرة العميل؛ الحماية المذكورة تعمل على الأحداث | لا يمكن منع screenshot/OCR أو عميل معدل؛ PNG يحمل بصمة مدمجة للردع والتتبع |
| أ-4.1 | رابط جديد وإبطال القديم عند الدخول/الخروج من العرض | ✅ | `app/Http/Controllers/Student/StudentViewLinkController.php::{issue,close}`: nonce عشوائي 256 بت مشفّر بواسطة Crypt::encryptString، وSHA-256 للنص المشفّر بالجهاز؛ `tests/Feature/PhaseFiveDeterrenceTest.php` وFinalSecurityAuditTest وbrowser old link 404 | تشفير Laravel الموثق بـMAC وترميز URL؛ اختبار test_view_link_is_encrypted_and_rotation_or_tampering_invalidates_it؛ المغادرة لا تبطل عرضاً أحدث |
| أ-4.2 | جهاز واحد وإنهاء الجلسة الأولى برسالة واضحة | ✅ | `app/Http/Controllers/Auth/StudentTokenAuthController.php::store` وقفل الطالب؛ `app/Http/Middleware/EnsureStudentDeviceIsActive.php::handle`؛ browser second device | فرض جهاز واحد أثناء الدخول، وترحيل enforce_single_student_device_sessions يبطل الأجهزة القديمة؛ توقف التشغيل المحمّل مسبقاً خلال نبضة الحفظ التالية (12 ثانية)، وفحص المتصفح نجح خلال 16 ثانية؛ الرسالة عربية |
| أ-4.3 | سجل الأجهزة وIP وUser-Agent للأستاذ | ✅ | `StudentDevice`؛ `app/Http/Controllers/Admin/StudentProgressController.php::show`؛ صفحات admin/students وstudent-progress؛ اختبار forwarded spoof | البروكسيات غير الموثوقة لا تغيّر الهوية المسجلة |
| أ-4.4 | تبديل الروابط/الجلسات يبقي الموضع والدقة | ✅ | `lesson_progress`, `student_preferences`؛ `tests/Feature/FinalSecurityAuditTest.php`؛ browser resume/quality/new device | إصلاح startPosition وloadedmetadata وboolean beacon |
| أ-5.1 | Audit للدخول والخروج والمشاهدة والملازم والتوكن والأجهزة | ✅ | `app/Services/StudentAuditService.php::POINTS/record/recordFailedToken`؛ Auth، Viewer، Activity، ViewLink controllers؛ `tests/Feature/PhaseFiveDeterrenceTest.php` | أضيف suspicious_activity_flood أيضاً |
| أ-5.2 | نقاط مخالفات وتجميد وإيقاف تلقائي اختياري | ✅ | `app/Services/StudentAuditService.php::record`؛ `config/audit.php`؛ `app/Http/Controllers/Admin/StudentController.php::status`؛ اختبار configured score يعطي 423 | auto_suspend_score=0 يعطل الإيقاف الاختياري فقط؛ التجميد يبطل الأجهزة والروابط |
| أ-5.3 | تنبيه الأستاذ عند مخالفة | ✅ | `notifyAdminsWhenNeeded` و`SuspiciousStudentActivity`؛ notifications DB؛ `tests/Feature/PhaseFiveDeterrenceTest.php` | التنبيه يكتب فوراً في DB؛ عداد الواجهة يتحدث كل 15 ثانية؛ البريد اختياري وفق الإعداد |
| أ-5.4 | تعهد الطالب عند أول دخول | ✅ | `StudentAgreementController`؛ `EnsureStudentAgreementAccepted`؛ `loginStudent` في Tests/TestCase؛ `tests/Feature/PhaseFiveDeterrenceTest.php` | نسخة التعهد وتاريخ الموافقة محفوظان في students |
| ب-1 | لا تسجيل عام | ✅ | routes.json؛ `test_no_public_registration_routes_or_mutations_exist` | GET/POST register/signup/api/student register ترجع 404 |
| ب-2 | إنشاء الحسابات للأستاذ أو أدمن مخوّل | ✅ | `RequirePermission` و`StudentPolicy` وroutes admin؛ `AccountAuthorizationTest` | طالب أو أدمن بلا صلاحية لا ينشئ الحساب |
| ب-3 | الطالب يدخل بتوكن فقط مجزأ | ✅ | `app/Services/StudentTokenService.php::issue`، `app/Http/Controllers/Auth/StudentTokenAuthController.php::store`، `TokenAuthenticationTest` | إزالة encrypted_token بالترحيل؛ SHA-256 صالح لتوكن عشوائي قوي، لا لكلمة مرور بشرية |
| ب-4 | النص يظهر مرة واحدة | ✅ | `app/Http/Controllers/Admin/StudentController.php::index` session pull وtoken reveal=422؛ `AccountAuthorizationTest` و`StudentTokenRevealTest` | زر الاسترجاع أزيل؛ endpoint القديم لا يسترجع أي سر |
| ب-5 | إعادة توليد/تجميد/انتهاء اختياري | ✅ | `app/Services/StudentTokenService.php::issue` و`app/Http/Controllers/Admin/StudentController.php::{regenerate,status}`؛ TokenAuthentication/FinalSecurityAudit tests | التجديد يبطل التوكن والجهاز القديمين؛ لا يحذف التقدم؛ التوكن المجمد القديم لا يُعاد تفعيله بعد التجديد |
| ب-6 | Rate limiting ورسالة لا تكشف وجود التوكن | ✅ | `AppServiceProvider`: student-token-login 5/IP/min؛ `TokenAuthenticationTest` | الرسالة نفسها للفشل والانتهاء؛ IP spoof test نجح |
| ب-7 | مصفوفة صلاحيات بالمسارات وPolicies والواجهة | ✅ | `app/Models/User.php::{hasPermissionTo,canDelegateRoles,canDelegatePermissions}`؛ Gate/RequirePermission/CoursePolicy؛ admin @can | منع التفويض أعلى من صلاحية الفاعل؛ لا يمكن منح دور super-admin المحمي |
| ب-8 | اختبارات IDOR وأدمن بلا صلاحية وتصعيد تفويض | ✅ | AccountAuthorization/StudentAccessScope/ContentManagement/FinalSecurityAudit tests؛ browser stranger key/master 404 | عمليات الرفض لا تحدث mutation بقاعدة البيانات |
| ج-1 | مرحلة ← مادة ← أجزاء/فصول/أقسام/محاضرات | ✅ | GradeLevel/Course/CurriculumNode/Lesson ومهاجراتها؛ `LectureWorkspaceTest` | الإضافة السريعة مرحلة ثم مادة ثم اسم المحاضرة؛ المستويات الافتراضية تُنشأ تلقائياً |
| ج-2 | إضافة/تعديل/حذف/سحب/ترتيب/نقل مستويات | ✅ | `app/Http/Controllers/Admin/CurriculumController.php::{store,update,reorder,destroy}`؛ `ContentManagementTest::test_curriculum_can_be_reordered_and_soft_deleted`؛ Sortable/admin-ui | النقل بين آباء من النوع الصحيح داخل المادة؛ يمنع الدورات والنقل غير الصالح |
| ج-3 | فيديو ونص ومرفقات في محاضرة واحدة ومحرر غني | ✅ | admin/lessons/form، `app/Http/Controllers/Admin/LessonController.php::update`؛ `resources/js/app.js` editor/autosave؛ `LectureWorkspaceTest` وsmoke | رفع الفيديو الحقيقي والنص والمرفقات لا تتطلب إنشاء دروس منفصلة |
| ج-4 | النص آمن من XSS | ✅ | `app/Support/SafeRichText.php` والقائمة المسموحة؛ FormRequests؛ اختبار ContentManagement حقن script/onclick/javascript | HTML يُنظف في السيرفر، لا يعتمد على تنظيف المحرر وحده |
| ج-5 | MIME حقيقي وحد حجم للمرفقات | ✅ | `app/Http/Requests/Admin/UploadLessonAttachmentsRequest.php::rules`: finfo/MIME، 20MiB×10، صور≤25MP؛ ContentManagementTest | منع الصور التالفة/كبيرة الأبعاد وتضخم canvas للصور شديدة النحافة؛ nginx/PHP guide يصحح حد جسم الطلب |
| ج-6 | مسودة/منشور/مجدول ومعاينة وربط الطلاب وحذف ناعم | ✅ | Course/Lesson visibility scopes وPolicies؛ `app/Http/Controllers/Admin/CurriculumController.php::preview`؛ `StudentAccessScopeTest`, ContentManagement/LectureWorkspace tests | دروس الأسلاف المخفية لا تتسرب في JSON/HTML؛ المعاينة بصلاحية الأستاذ |
| ج-7 | Chunked/Resumable للرفع الكبير | ✅ | `app/Video/VideoUploadService.php::{start,append,complete}`؛ tus-js-client؛ VideoStreamingTest resumable offset؛ smoke 34.9MiB | حد الملف 10GiB وتجزئة 4MiB؛ تحقق offset/ملكية/MIME/fingerprint |
| ج-8 | Queue وحالات وانتظار/معالجة/جاهز/فشل وإعادة محاولة | ✅ | `VideoProcessingGateway`, worker index BullMQ، `processVideo/reportStatus`، `app/Http/Controllers/Admin/VideoUploadController.php::retry`، callback؛ smoke | 3 محاولات backoff؛ job_id قبل enqueue؛ ffprobe20s وFFmpeg2h وقائمة بروتوكولات محلية |
| د-1 | heartbeat 10–15 ثانية وحفظ pause/unload | ✅ | `player.js` heartbeat12s؛ `progress-store::{persistServerProgress,flushProgress}`؛ `app/Services/LessonProgressService.php::save` | test_real_beacon_form_payload + Node beacon + LessonProgressTest |
| د-2 | استئناف تلقائي من السيرفر ومن جهاز آخر | ✅ | `app/Support/LearningUi.php::progressSnapshot` وplayer startPosition؛ browser resume/new device؛ LessonProgressTest | التقدم مرتبط بالطالب والدرس وليس localStorage للطالب الحقيقي |
| د-3 | تابع من mm:ss/ابدأ من البداية والرئيسية | ✅ | video-player وstudent/learning؛ browser resume/start from beginning | يظهر الموضع المحفوظ؛ إعادة البداية اختُبرت فعلياً |
| د-4 | تلقائي و1080/720/480/360 حسب المتوفر | ✅ | quality-menu، `processor.js` selectedHeights، `app/Support/LearningUi.php::available_resolutions` | المصدر الحقيقي 720 أظهر 720/480/360؛ لا تُختلق 1080 عند عدم توفرها |
| د-5 | عرض الدقة الفعلية بجانب تلقائي | ✅ | `createQualityAdapter` وHls LEVEL_SWITCHED وnative videoHeight؛ `data-auto-quality` | توجد إشارة فعلية للدقة؛ اختبار المتصفح تحقق من videoHeight=360 |
| د-6 | تغيير الدقة دون فقدان الموضع | ✅ | `player.js::createQualityAdapter/refreshStream`؛ browser مقارنة currentTime قبل/بعد switch مع تشغيل فعلي | المسار الأصلي يستعمل rendition_urls ويحفظ الموضع؛ Safari يحتاج تأهيلاً أصلياً |
| د-7 | حفظ اختيار الطالب وتشفير كل الدقات | ✅ | `app/Http/Controllers/Student/PlayerPreferenceController.php::update` وstudent_preferences؛ `PlayerPreferenceTest`؛ browser preferred-quality بعد عرض جديد؛ worker keyinfo | الدقة المعطلة لا تخدم حتى برابط قديم صالح التوقيع |
| د-8 | سرعة/±10ث/ملء الشاشة/اختصارات | ✅ | video-player/player.js؛ `LearningExperienceTest`؛ browser playback/fullscreen/fallback | RTL يعكس معنى الأسهم؛ fallback يحافظ على البصمة ولا يستخدم native fullscreen المنفصل |
| د-9 | إكمال بحد قابل للضبط ومنع التحايل بالقفز | ✅ | `config/learning.php`, `app/Services/LessonProgressService.php::save`؛ `LessonProgressTest` elapsed/seeked | لا يحتسب قفزة كبيرة أو وقتاً يتجاوز الفاصل؛ لا يثبت أن الإنسان منتبه، كما في القسم 5 |
| د-10 | قائمة تشغيل إضافة/حذف/ترتيب داخل الحساب | ✅ | `app/Http/Controllers/Student/StudentPlaylistController.php::{create,store,destroy,reorder,index}`؛ LearningExperienceTest؛ browser button persistence | الزر يسجل في السيرفر؛ filtering يخفي العناصر بعد فقدان الصلاحية |
| د-11 | تقدم الطالب للمادة/الفصل/الدرس | ✅ | `app/Support/LearningUi.php::course` addProgressPercentages؛ student/_curriculum-node و_player-curriculum-node، progress page؛ LearningExperience/Profile tests | شجرة فعلية ونسب مجموعات؛ أحداث progress تحدث سايدبار الدرس |
| د-12 | تقدم الأستاذ بالتفصيل وبحث/فلترة/تصدير/إحصاءات | ✅ | `app/Http/Controllers/Admin/StudentProgressController.php::{index,show,export,statistics}`؛ `LessonProgressTest` وCSV formula test | آخر درس ونقطة توقف ووقت مشاهدة؛ CSV آمن من صيغ تبدأ =/+/-/@ |
| هـ-1 | صفحة تعلم وبطاقات وAccordion وسايدبار منهج | ✅ | student/learning/course/lesson؛ components/course-card/curriculum-sidebar؛ browser وLearningExperienceTest | أزيل تقسيم المنهج الوهمي واستُعملت الشجرة الحقيقية |
| هـ-2 | RTL وعربية في الواجهة | ✅ | layouts lang=ar dir=rtl؛ CSS logical properties؛ `resources/lang/ar/validation.php`؛ errors views؛ browser no overflow | تعريب العناوين التوضيحية والرسائل العامة؛ أكواد الدقة وMIME ومعرّفات البصمة مصطلحات تقنية |
| هـ-3 | برتقالي+أسود وبرتقالي+أبيض مع حفظ الاختيار | ✅ | CSS variables app.css؛ theme script/localStorage؛ browser reload theme tests | --bg ليلي #101010، نهاري #fff؛ البرتقالي #e96d28/#f58c51 |
| هـ-4 | Responsive | ✅ | app/player/admin/content CSS؛ smoke desktop/mobile/sidebar؛ browser 390×844 overflow=0 | فحص فعلي بحجمين، وليس شهادة لكل جهاز في السوق |
| هـ-5 | PWA manifest/SW/icons/offline بدون cache محتوى | ✅ | public/manifest.json/sw.js/icons/offline.html وpwa.js؛ browser cache allowlist/offline navigation | الكاش أصول ثابتة فقط؛ لا HLS أو keys أو صفحات حساب أو صور ملازم |
| هـ-6 | حالات تحميل/فارغة/خطأ وElectron أو توثيق حالته | ✅ | empty-state، video-not-ready، processing UI، errors views؛ `electron/main.cjs` وVPS desktop guide | setContentProtection(true)، sandbox/contextIsolation/devTools=false؛ syntax/APIs فُحصت؛ لا حزمة موقعة صُنعت |
| هـ-7 | تحقق أصلي لكل Safari/iOS/PWA مثبت/Windows/macOS | ⚠️ | تنفيذ Chromium responsive وSW؛ فحص كود native HLS وElectron؛ لا أجهزة أو شهادات أصلية بالبيئة | الكود والبديل موجودان؛ اختبار أجهزة أصلية وتوقيع Electron لا يجوز ادعاؤهما من headless Linux |
| و-1 | الاختبارات تعمل وتنجح | ✅ | verification.json؛ SQLite/MySQL116/883؛ Node9؛ browser 45؛ upload smoke | شغلتها بعد الإصلاحات، دون حذف اختبارات سلبية أو تعطيل الحمايات |
| و-2 | مراجعة OWASP CSRF/XSS/SQLi/IDOR/الرفع | ✅ | تفصيل OWASP أدناه؛ tests وبث/CSRF419/worker SSRF test؛ SafeRichText وPolicies | مراجعة كود وتجارب محددة؛ ليست شهادة اختراق شاملة أو scan إنتاجي |
| و-3 | Headers أمان | ✅ | AddSecurityHeaders، bootstrap error response؛ SecurityHeadersTest وFinalSecurityAudit errors test | CSP nonce، DENY/nosniff/CORP/COOP/PermissionsPolicy/no-store؛ HSTS في الإنتاج |
| و-4 | دليل VPS/متطلبات/نسخ احتياطي/استخدام | ✅ | PHASE_7_VPS_AND_DESKTOP_AR، PHASE_7_TEACHER_ADMIN_HANDOVER_AR، VIDEO_STREAMING_DEPLOYMENT_AR | PHP≥8.4.1، GD/Poppler/FFmpeg/MySQL/Redis، worker npm ci، cron، upload20MiB×10، backup/restore وخطوات التوقيع |

### مراجعة OWASP Top 10:2025

| الفئة | ما فُحص أو عولج | حد النتيجة |
|---|---|---|
| A01 صلاحيات الوصول | سياسات الطالب/المادة، IDOR للمفاتيح والملفات والقائمة، صلاحيات الأدمن والتفويض، الأسلاف المخفية | الطلبات السلبية المحددة رُفضت؛ يلزم تجديد الاختبارات عند إضافة route |
| A02 الإعداد غير الآمن | القرص الخاص غير قابل للخدمة العامة، رؤوس الأمن، proxy allowlist، الإنتاج DEBUG=false/HTTPS في الدليل | لم أغيّر أو أفحص خادم إنتاج فعلي؛ طبّق دليل النشر |
| A03 سلسلة الإمداد | Composer وnpm بثلاثة مشاريع، shell-quote وglobal-agent، lockfiles، إزالة dependencies المتتبعة | صفر تنبيهات حالياً؛ لا ضمان للثغرات غير المعلنة؛ لا CI تلقائي مثبت بهذه المراجعة |
| A04 التشفير | توكنات عشوائية hash-only، HMAC للهوية، AES128 خاص بالفيديو، توقيع وانتهاء وربط جلسة | المفتاح يصل للمشاهد المصرح؛ يلزم TLS في الإنتاج؛ ليس DRM |
| A05 الحقن | SafeRichText/DOM وLIBXML_NONET، output escaping، Eloquent/bindings واستعلامات raw ثابتة، CSV formula، spawn بلا shell | لا تتضمن التجربة فحص كل مدخل بكل payload ممكن |
| A06 التصميم | جهاز واحد، إبطال الروابط، watermark، تعهد، حدود واجهة المستخدم الصريحة | سياسة الردع لا تضمن منع التسجيل أو تنبه الإنسان |
| A07 المصادقة | عدم التسجيل العام، 5 محاولات/IP، رسائل موحدة، إبطال الأجهزة والتوكنات | MFA للأستاذ غير موجود؛ يُوصى به |
| A08 سلامة البيانات | CSRF419 فعلياً، callback Bearer وjob_id، مسارات عامل مقيدة، SHA256 للرفع | حماية secret worker ومنع الوصول إليه من الإنترنت مهمة في النشر |
| A09 التسجيل والتنبيه | audit server، نقاط وتجميد وتعهد، إشارات العبث، رصد flood مع إبطال الرابط | التوقيت المحلي قد يخطئ؛ سجل خارجي غير قابل للتعديل غير مفعّل |
| A10 الاستثناءات | failed/retry، مهلات ffprobe/FFmpeg/PDF، حد dimensions، أخطاء عربية عامة وعدم cache | اختبارات حمل/نفاد قرص/انقطاع Redis طويل ليست منفذة هنا |

## 3) نتائج محاولات التنزيل والتجاوز

السيناريو استخدم Laravel محلياً، طالباً مخصصاً وطالباً غير مخصص وطالباً تنتهي جلسته، وHLS مولداً من FFmpeg فعلياً. للزائر تعني 302 **رفضاً وتحويلاً للدخول**، لا ملف فيديو؛ طلب JSON يرجع 401 كما في PHP tests. لا تتضمن ملفات الأدلة URLs موقعة أو مفاتيح أو cookies.

| المحاولة المطلوبة | النتيجة الفعلية | معنى النتيجة والدليل |
|---|---|---|
| أ-2/1 فتح m3u8 بلا جلسة | فشلت: 302 إلى الدخول؛ JSON401 | browser guest request وFinalSecurityAuditTest لكل asset |
| أ-2/2 جلسة الطالب منتهية | فشلت: 302 | fixture غيّر expires_at ثم طلب الرابط نفسه |
| أ-2/3 طلب المفتاح بلا جلسة | فشلت: 302 | browser key بدون cookies |
| أ-2/3 طالب غير مخصص للمادة يطلب المفتاح/manifest | فشلت: 404 | طالب آخر سجل الدخول فعلياً ثم أعاد استخدام الرابط |
| أ-2/4 إعادة استخدام توقيع منتهي | فشلت: 403 | URL مولد بتوقيع صحيح ولكن expires في الماضي؛ محاكاة زمنية دون انتظار 8 دقائق |
| أ-2/4 تعديل query/signature | فشلت: 403 | إضافة forged=1؛ التوقيع لا يقبل تغيير الطلب |
| أ-2/5 تنزيل segment بلا جلسة | فشلت: 302 | طلب مسار مقطع فعلي بلا cookies |
| أ-2/5 تشغيل segment مشفّر دون المفتاح | فشلت: ffprobe غير ناجح | bytes من مقطع حقيقي محفوظ مؤقتاً، لا مجرد نص وهمي؛ فك بمفتاح عشوائي فشل |
| أ-2/6 البحث عن الأصل في public/storage العام | فشلت في إيجاد أصل: صفر | rglob/find وgit tracked scan للـmp4/webm/mov/mkv/avi/part/key/pdf؛ private يحتوي الأصول بشكل مقصود |
| أ-2/7 البحث في ردود HTML/JSON | فشلت في إيجاد رابط الأصل: صفر | response listener أثناء صفحات الطالب وAPI المستعملة؛ serializer statically audited |
| Hotlink من موقع آخر | فشلت: 403 | Origin attacker.invalid + Sec-Fetch-Site cross-site |
| تغيير حالة بلا CSRF | فشلت: 419 | POST playlist item بلا _token/X-CSRF-TOKEN عبر HTTP الفعلي |
| حذف watermark الفيديو | فشلت: أوقف العرض وأصبح الرابط404 | DOM remove؛ تمت مراقبة رد تسجيل المخالفة قبل فحص الرابط |
| display:none | فشلت: توقف+404 | MutationObserver/computed style |
| opacity:0 | فشلت: توقف+404 | computed opacity |
| استبدال نص البصمة | فشلت: توقف+404 | مقارنة النص والمعرّف الصادر من السيرفر |
| شفافية سلف watermark=0 | فشلت: توقف+404 | تراكم opacity عبر أسلاف العنصر |
| تغطية بطبقة div مع z-index مرتفع | فشلت: توقف+404 | hit test فعلي |
| إخفاء العلامة بواسطة stylesheet | فشلت: توقف+404 | فحص دوري وقائمة تغيرات DOM |
| إخفاء watermark الملزمة | فشلت: حذف الصور وإخفاء canvas وتسجيل مخالفة | viewer.js وaudit، مع بصمة PNG المدمجة أيضاً |
| تعطيل تفضيل حماية localStorage | فشلت: بقيت content-protected ومنع copy | صفحة طالب فعلية، وليس teacher preview |
| إنهاء جهاز عند دخول جهاز ثانٍ | نجح منع الجلسة الأولى والتوقف خلال 16 ثانية | إعادة الفحص بعد تشفير رابط العرض؛ الحفظ بقي متاحاً للجهاز الثاني |
| F12 | فشلت في متابعة العرض: paused وaudit devtools | ضغط لوحة المفاتيح في المتصفح؛ لا يدّعي كشف كل طريقة لفتح الأدوات |
| إعادة استخدام رابط بعد فتح عرض جديد/إغلاق | فشلت:404 | PHP tests وbrowser resume؛ view token hash تغير/حُذف |
| جهاز ثانٍ | فشلت الجلسة الأولى: تحويل للدخول برسالة | HTTP login جديد ثم طلب التعلم من صفحة الجهاز الأول |
| تشبع عداد playback لمحاولة إسقاط tamper | فشل التجاوز | اختبار30 heartbeat ثم watermark؛ الإشارة الأمنية بقيت مسجلة |
| إغراق الإشارات الأمنية نفسها | 429 وإبطال الرابط ومخالفة flood واحدة/دقيقة | FinalSecurityAuditTest؛ لم يحذف rate limit لتجاوز المشكلة |
| **استخراج بمفتاح جلسة مصرح بها** | **نجح فك المقطع الصحيح وقراءته بـffprobe** | **حد صريح: العميل المصرح يستلم المفتاح؛ لذلك أ-2.8 جزئي، لا ادعاء منع تنزيل مطلق** |

## 4) تقرير البصمة

### التوليد والهوية

يشتق السيرفر رمزاً من `HMAC-SHA256("watermark:" + student_id, APP_KEY)` ويعرض أول 16 رمزاً hex مع رقم الطالب واسمه. رقم الطالب يميّز الهوية حتى لو تطابق اسمان. هذا **معرّف تتبع وليس سر دخول**، ولا يستقبل API قيمة بديلة له من الطالب. الـAPP_KEY لا يخرج للعميل. تدوير APP_KEY يغير المعرّفات؛ احتفظ بنسخة آمنة وسجل هوية الطالب عند التعامل مع واقعة تسريب.

### العرض والحماية

في الفيديو: span داخل stage المشغّل نفسه، شفافية .48 وخط يبدأ من12px. يتحرك كل10 ثوانٍ ضمن مساحة محسوبة. ملء الشاشة يطلب container المشغّل كله؛ لا يُستخدم fullscreen الفيديو المنفصل الذي يخفي DOM. إذا غاب requestFullscreen تُستخدم طبقة viewport بحجم الشاشة. `webkitbeginfullscreen` يوقف العرض ويوجه الطالب لزر المشغّل المحمي. طبقة النص تستخدم العلامة نفسها؛ عارض الملازم يحمل ثلاث علامات متحركة.

الحارس يحفظ العناصر الأصلية والنص، ويراقب childList/characterData/style/class/hidden على شجرة الوثيقة، ويفحص مرة/ثانية الأبعاد والإظهار والشفافية المتراكمة والتغطية. يوقف تشغيل الفيديو، يهدم Hls، يزيل src، ويرسل suspicious_watermark. السيرفر يتحقق من الطالب والدرس والجهاز ورابط العرض، ثم يسجل5 نقاط ويبطل view_link_hash. الإيقاف التلقائي للحساب ممكن وفق threshold، ولا يُفرض افتراضياً كي لا يعاقب حساباً بخطأ heuristic دون سياسة الأستاذ.

هذه حماية من السيناريوهات المفحوصة، **ولا تحول DOM إلى بيئة موثوقة**. يستطيع صاحب جهاز معدل إيقاف الكود أو interception أو صنع طبقة لا يلتقطها hit test. لا توجد حماية ويب تستطيع ضمان سلامة watermark overlay ضد السيطرة الكاملة على المتصفح.

### الدمج في الصورة والفيديو

**الصور والملازم: مفعّل.** يحول Poppler صفحات PDF إلى PNG في private. عند كل طلب مصرح تعيد `watermarkedPage` تركيب PNG في الذاكرة، مع `#student_id / HMAC` على25/55/85٪ من ارتفاعها. تعاد PNG المتغيرة الخاصة بالطالب، لا الأصل. اختبار البكسلات أثبت تغير الصورة، وPDF فعلي ظهر في المتصفح. حذف span لا يزيل العلامات المدمجة في بيانات PNG؛ crop/inpainting لا يمكن منعه مطلقاً.

**الفيديو: watermark شخصي مدمج بـFFmpeg غير مفعّل.** `processVideo` يولد HLS مشتركاً للمادة، وليس نسخة لكل طالب. FFmpeg يدعم drawtext، لكن علامة الطالب المختلفة تتطلب نسخاً/جلسات تحويل شخصية أو خط بث متخصص. تشغيل drawtext باسم الأستاذ على نسخة مشتركة لا يحقق الطلب الشخصي، ولذلك لم أقدمه كحل. الوثيقة `VIDEO_STREAMING_DEPLOYMENT_AR.md` تشرح تكلفة وقت المعالجة والتخزين وعدد الطلاب، والبديل الحالي overlay المرتبط بهوية السيرفر + سجل/تعهد/إبطال/تجميد، مع دمج صور الملازم. هذا يحقق فرع **توثيق عدم التفعيل** الذي سمحت به القائمة، ولا يعني تفعيل watermark مدمج للفيديو.

## 5) الحدود الصريحة

1. **الوصول المصرح واستخراج الوسائط:** HLS AES128 يمنع فك bytes دون مفتاح لكنه يجب أن يعطي المفتاح للمشغّل المصرح. روابط قصيرة العمر تمنع إعادة الاستخدام بعد انتهائها/إبطالها، ولا تمحو مفتاحاً أو مقطعاً سبق أن وصل. لا يمكن إعادة تجميع segment المشفّر دون مفتاح صالح، لكن حفظ المفتاح الصالح أثناء جلسة مصرح بها يتيح ذلك، وأثبتت التجربة هذا الحد.
2. **الشاشة والنص:** كاميرا خارجية، جهاز/برنامج التقاط خارجي، OCR، وعميل يعدّل JavaScript خارج سيطرة الصفحة. منع event copy أو تعطيل PiP ليس DRM ولا ضمان عدم تسريب. استعمال البصمة والتتبع والتعهد والتجميد يضيف ردعاً ومحاسبة، لا وعداً مستحيلاً.
3. **DevTools:** الفرق بين outer/inner وF12 قرائن، لا API موثوق لكشف كل DevTools. قد يفوّت undocked/remote، وقد يشتبه في أدوات وصول/أشرطة متصفح. مراجعة الأستاذ مطلوبة قبل العقوبة الآلية الصارمة.
4. **مغادرة الصفحة:** close وunload/beacon اختُبرا مع الشبكة. إغلاق بالقوة أو قطع الشبكة قد يمنع وصول الطلب؛ لا تستطيع الصفحة ضمان إبطال فوري على سيرفر لم يتلقَّ حدثاً. الجلسة الجديدة وإبطال الحساب والتوقيع المنتهي تستمر في العمل، وأي بيانات سبق تخزينها لا يمكن سحبها عن بعد.
5. **الإكمال:** التوقيت من السيرفر ومنع القفز الكبير يوقف التقديم السريع للحصول على credit. لا يثبت انتباه الإنسان أو يمنع محاكاة heartbeats واقعية عبر عميل مصرح معدل. الإكمال هنا مؤشر متابعة تعليمي، لا نظام إثبات حضور امتحان.
6. **Electron:** يستدعي setContentProtection(true) مع sandbox/contextIsolation ومنع DevTools والتنقل الخارجي. على Windows يطلب واجهة منع الالتقاط المدعومة؛ بعض إصدارات macOS/ScreenCaptureKit تستطيع التقاط النافذة رغم هذا الخيار، وفق توثيق Electron الرسمي. لا يمنع التصوير بكاميرا ولا يضيف DRM للفيديو المشترك. PWA لا يستدعي حماية نافذة نظام التشغيل.

## 6) المتبقي وما يحتاجه صاحب المنصة

| المتبقي | السبب | الإجراء المطلوب |
|---|---|---|
| أ-1.5 وأ-3.5 | السيطرة الكاملة على المتصفح وكشف كل أدوات المطور لا يمكن ضمانهما | اعتماد سياسة ردع ومراجعة المخالفات؛ عدم التسويق بحماية غير قابلة للتجاوز |
| أ-2.8 وأ-3.6 | المحتوى المعروض وفك التشفير يصلان للعميل المصرح | إذا كانت أولوية تجارية: اختيار مزود DRM/forensic watermark وتحديد الكلفة؛ حتى DRM لا يمنع كاميرا خارجية |
| هـ-7 | بيئة headless Linux لا تقدم iPhone/Safari حقيقياً أو Windows/macOS ولا شهادات توقيع | تشغيل مصفوفة الأجهزة المذكورة أدناه؛ توفير نطاق HTTPS وشهادات Windows/Apple إذا كان توزيع Electron مطلوباً |
| ترحيل التوكن على البيئة الحية | المستودع أصلح؛ لا يوجد تفويض/عنوان وصول لخادم إنتاج مفحوص | نسخة DB قبل النشر، ثم migrate --force؛ الترحيل الأول يحذف encrypted_token ويبقي التجزئات صالحة، والثاني ينهي جلسات الطلاب الحالية ويضبط حد الجهاز=1. يلزم دخول الطلاب مجدداً بالتوكن الموجود؛ لا تضيع نقاط المشاهدة، ولا يمكن استرجاع نص التوكن إلا بتجديده |
| تكوين وتشغيل VPS والنسخ الاحتياطي | المطلوب هنا مراجعة الكود والدليل؛ لم نفحص VPS أو استعدنا backup حي | تطبيق DEBUG=false/HTTPS/secure cookies/private storage/proxy list، npm ci للعامل وSupervisor/cron، ثم اختبار استعادة في بيئة معزولة |
| Electron الموقّع | shell والكود والأدلة موجودة؛ لم نبن ملفات توزيع موقعة | origin/feed حقيقيان وشهادات توقيع؛ build/test على Windows وmacOS قبل التسليم للطلاب |

مصفوفة التأهيل المتبقية: Safari على iPhone/iPad، Chrome Android مع PWA مثبتة، Chromium desktop PWA standalone، وElectron Windows/macOS؛ في كل منها فيديو طويل يتجاوز8 دقائق، تجديد الرابط، تغيير كل دقة متاحة، resume، fullscreen، خلفية/عودة، وPDF متعدد الصفحات. تحقق من بقاء البصمة ومن توقف واضح عند إبطال الجلسة. هذه اختبارات مطلوبة قبل ادعاء دعم أصلي شامل، وليست أخطاء مخفية حُذفت لتنجح الاختبارات.

### إعادة الاختبارات محلياً

```bash
composer install
npm ci
npm ci --prefix video-worker
npm ci --prefix electron
php artisan migrate
php artisan test --compact
npm run test:js
npm run build
vendor/bin/pint --dirty --format agent
php artisan view:cache
php artisan route:cache
php artisan route:clear
composer audit --locked
npm audit
npm audit --prefix video-worker
npm audit --prefix electron
```

تتطلب اختبارات الصور GD، واختبار العامل ffprobe. متطلبات المتصفح: بيئة **local/testing معزولة** تحتوي درساً published وفيديو ready حقيقياً عبر مسار الرفع؛ Playwright ومتصفح Chromium متاحان خارج dependencies التطبيق. شغّل:

```bash
ACADEMY_PHP=/path/to/php \
ACADEMY_BROWSER_EXECUTABLE=/path/to/chromium \
ACADEMY_PLAYWRIGHT_MODULE=/path/to/playwright \
node tests/Browser/security-audit.cjs
```

السكريبت يجهز طلاباً ومرفقات اختبار في قاعدة local؛ لا تشغله على بيانات حية أو نسخة تحتوي أسرار إنتاج. يستخدم8081 ويغلق خادم الاختبار في النهاية. لا توجد خدمة اختبار منشورة للطلاب في هذه المراجعة. MySQL اختُبر بقاعدة معزولة وDB_CONNECTION=mysql، لا باستبدال قاعدة التطوير.

### ناتج الأوامر في هذه الجولة

الأوامر نُفذت بعد الإصلاحات، باستخدام PHP 8.4.12، Laravel 13.34.0 وMySQL 8.0.46. المسار الخاص لثنائية PHP في بيئة المراجعة يعادل `php` في الأوامر التالية.

| الأمر | النتيجة | ملف الناتج |
|---|---|---|
| `php artisan test --compact` على SQLite | 116 passed، 883 assertions | `docs/audit/php-sqlite.txt` |
| الأمر نفسه مع `DB_CONNECTION=mysql` وقاعدة معزولة | 116 passed، 883 assertions | `docs/audit/php-mysql.txt` |
| `node --test tests/Unit/*.test.mjs` | 9 pass، 0 fail | `docs/audit/node-tests.txt` |
| `node tests/Browser/video-upload.cjs` | 7 فحوص؛ 36,636,389 بايت؛ queued → processing → ready؛ 720/480/360 | `docs/audit/upload-results.json` |
| `node tests/Browser/security-audit.cjs` بعد تشفير روابط العرض | 45 فحصاً؛ صفر أخطاء JavaScript؛ صفر روابط أصل | `docs/audit/browser-results.json` و`browser-tests.txt` |
| `npm run build` | نجاح؛ Vite 8.3.2؛ 32 modules | `docs/audit/build.txt` |
| `php vendor/bin/pint --dirty --format agent` | passed | `docs/audit/verification.json` |
| `php artisan view:cache` ثم `view:clear` | نجاح | `docs/audit/verification.json` |
| `php artisan route:cache` ثم `route:clear` | نجاح | `docs/audit/verification.json` |
| `composer audit --format=json` و`npm audit --json` للويب والعامل وElectron | صفر تنبيهات معروفة وقت الفحص | `docs/audit/composer-audit.json` و`npm-*.json` |
| `find public storage/app/public` للفيديوهات/المفاتيح/PDF | صفر ملفات | `docs/audit/verification.json` |

```json
{"tool":"phpunit","result":"passed","tests":116,"passed":116,"assertions":883}
```

أضفت خلال هذه الجولة اختبار التشفير والتدوير، واختبار ترقية الجلسات القديمة، واختبار Node لإيقاف المشغّل عند رد انتهاء الجلسة. فشل اختبار ترحيل الجلسات أولاً لأن طلب fixture أغفل حقل event المطلوب؛ صححت الطلب وأعدت الاختبار على SQLite وMySQL. تجهيز المتصفح والـfixtures احتاج إصلاحات تشغيل، وبعدها نفذت تجربة المتصفح كاملة على النسخة الأخيرة؛ لا أعد المحاولات الفاشلة نتائج نجاح.

### اختبارات الرفع القابلة لإعادة التنفيذ

`tests/Browser/video-upload.cjs` يجهز حساب أستاذ محلياً، ينشئ محاضرة عبر الواجهة، يولد فيديو 45 ثانية بـFFmpeg، يختبر الانقطاع واستئناف HEAD ورفض offset خاطئ، ثم ينتظر التحويل الفعلي. يحتاج PHP وRedis وFFmpeg وPlaywright/Chromium. يستخدم 8081 و3300 و6383 ويغلق العمليات بعد الفحص. متغيرات الربط: ACADEMY_PHP وACADEMY_REDIS وACADEMY_REDIS_LIB عند الحاجة، وACADEMY_PLAYWRIGHT_MODULE وACADEMY_BROWSER_EXECUTABLE.

نفذه ثم نفذ `security-audit.cjs` في **قاعدة local معزولة**. مولد fixtures يستخدم مسارات وأسماء فريدة ليقبل إعادة التشغيل. أسرار الاختبار تبقى في `/tmp` بصلاحية0600؛ لا تدخل ملفات الأدلة أو Git. لا توجد نسخة اختبار منشورة على الإنترنت، ولا نشر إنتاجي أو استعادة backup حي ضمن هذه المراجعة.

## 7) أهم خمس توصيات مستقبلية

1. **MFA للأستاذ والأدمنز**، مع إدارة استرداد آمنة وتنبيه تغيير الصلاحيات؛ ويمكن تقييد الإدارة بـVPN/IP موثوق.
2. **DRM وforensic watermark شخصي للفيديو** عند تبرير الكلفة، مع بقاء العلامات المرئية والتعهد والتتبع. لا وعد بمنع التصوير الخارجي.
3. **CI إلزامي** يشغّل PHP على MySQL وSQLite وNode وbuild وaudit، مع سيناريو رفع/bث معزول ومراقبة تحديث التنبيهات تلقائياً.
4. **تأهيل الأجهزة واختبارات حمل وتعطل** لفيديو طويل، صفوف عامل مزدحمة، مساحة قرص ممتلئة، وإعادة الاتصال؛ قياس false positives قبل تفعيل الإيقاف الآلي الصارم.
5. **سجل خارجي محمي ونسخ احتياطي مجرّب** مع تنبيه توقف المعالجة/النسخ، وRPO/RTO واضحين واختبار استعادة دوري وتنقيح الأسرار من logs.

### المصادر الأولية المرجعية

- [OWASP Top10:2025](https://top10.owasp.org/2025/) — فئات المراجعة.
- [Electron BrowserWindow/setContentProtection](https://www.electronjs.org/docs/latest/api/browser-window) — سلوك Windows وحد ScreenCaptureKit في macOS.

المصادر تشرح الآليات والحدود؛ الأدلة على هذا المشروع هي الملفات والأوامر ونتائج الاختبارات المرفقة. راجعت الصفحتين الرسميتين في هذه الجولة، ولم أعد النتائج السابقة بوصفها تنفيذ هذه المراجعة.
