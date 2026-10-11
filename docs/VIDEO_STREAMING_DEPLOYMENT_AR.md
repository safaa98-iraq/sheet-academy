# نشر رفع وبث الفيديو على VPS

## البنية

- الواجهة تستخدم tus عبر `tus-js-client`؛ Laravel ينفذ إنشاء الرفع ثم `HEAD` لاسترجاع الموضع و`PATCH` لأجزاء 4 ميجابايت. الملفات والمواضع تُحفظ في `storage/app/private/video-uploads` وفي قاعدة البيانات، وتُرفض الأنواع التي لا يطابقها فحص MIME على الخادم.
- Laravel يرسل مهمة صغيرة موقّعة إلى API داخلي على `127.0.0.1:3300`؛ API يضيفها إلى BullMQ على Redis.
- عامل Node يستخدم FFmpeg وffprobe، ويحفظ HLS في `storage/app/private/hls` والمفتاح في `storage/app/private/video-keys`.
- Laravel يقرأ قوائم التشغيل والمقاطع والمفاتيح بعد فحص جلسة الطالب وتسجيله بالمادة والتوقيع المؤقت.
- يجب أن يرى Laravel وعامل Node مجلد التخزين الخاص نفسه. لا تضف هذا المجلد إلى `public` ولا تنشئ له رابط `storage:link`.

## بصمة المشاهدة وحدودها

تعرض الواجهة بصمة الطالب المتحركة فوق الفيديو وصفحات الملزمة، وتتحقق من بقاء الطبقة ظاهرة وترسل محاولات العبث إلى سجل التدقيق. البصمة المرئية الحالية لا تعيد ترميز الفيديو لكل طالب.

يمكن إضافة بصمة محروقة باستخدام FFmpeg بإنشاء نسخة HLS منفصلة لكل طالب أو مجموعة مقرّرة. هذا يضاعف وقت المعالجة والتخزين تقريباً بعدد النسخ؛ فإذا احتاج كل طالب نسخة منفردة يصبح لدينا عملياً `عدد الدروس × عدد الطلاب` من المخرجات، إلى جانب إعادة المعالجة عند تغيير النص أو الدقة. الحل العملي الموصى به هو الإبقاء على HLS مشتركاً ومشفّراً وإضافة البصمة المرئية الخاصة بالطالب في المشغّل، مع تسجيل هوية الطالب وروابطه وجلساته ومراقبة محاولات العبث. هذا يردع التسريب ويساعد على تتبعه، لكنه لا يمنع تصوير الشاشة بهاتف أو كاميرا خارجية.

## المتطلبات

ثبّت PHP وإضافات Laravel وComposer، Node.js 22 أو أحدث، Nginx، Redis، FFmpeg وffprobe، وأدوات Poppler (`pdfinfo` و`pdftoppm`) لتحويل ملفات PDF إلى صفحات خاصة في عارض الملازم. أنشئ مستخدم خدمة بلا صلاحيات إدارية. في Ubuntu/Debian:

```bash
sudo apt update
sudo apt install nginx redis-server ffmpeg poppler-utils supervisor
node --version
ffmpeg -version
ffprobe -version
pdfinfo -v
pdftoppm -v
```

اجعل Redis يستمع على loopback فقط، واضبط كلمة مرور قوية في `/etc/redis/redis.conf`:

```ini
bind 127.0.0.1 ::1
protected-mode yes
requirepass REPLACE_WITH_A_LONG_RANDOM_PASSWORD
```

ثم أعد تشغيل Redis. لا تفتح منفذ Redis أو واجهة عامل الفيديو للإنترنت.

## إعداد التطبيق

انشر التطبيق في `/var/www/sheet-academy`، ثم أنشئ البيئة وقاعدة البيانات قبل تشغيل migrations:

```bash
cd /var/www/sheet-academy
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
```

اضبط إعدادات الإنتاج في `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://academy.example.com`, اتصال قاعدة البيانات، الجلسات، التخزين والبريد. أنشئ سراً مشتركاً عشوائياً:

```bash
openssl rand -hex 32
```

ضع الناتج في `VIDEO_WORKER_TOKEN` في Laravel وفي `/etc/sheet-academy/video-worker.env`. أكمل أيضاً:

```dotenv
VIDEO_WORKER_URL=http://127.0.0.1:3300
```

أنشئ ملف `/etc/sheet-academy/video-worker.env` بصلاحية قراءة مستخدم الخدمة فقط، باستخدام `video-worker/.env.example` قالباً. عدّل `PRIVATE_STORAGE_ROOT` إلى `/var/www/sheet-academy/storage/app/private`، واجعل `INTERNAL_CALLBACK_URL` عنوان Laravel الداخلي الصحيح، وعيّن عنوان Redis مع كلمة المرور. لا تضع هذه الأسرار في ملفات المشروع أو Git.

أنشئ المجلدات واضبط ملكيتها وصلاحياتها:

```bash
sudo install -d -o www-data -g www-data -m 0700 /var/www/sheet-academy/storage/app/private/video-uploads
sudo install -d -o www-data -g www-data -m 0700 /var/www/sheet-academy/storage/app/private/hls
sudo install -d -o www-data -g www-data -m 0700 /var/www/sheet-academy/storage/app/private/video-keys
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

شغّل Laravel تحت مستخدم يستطيع الكتابة إلى مجلدات التخزين الخاصة فقط؛ يجب ألا يكون عامل Node مستخدماً بصلاحيات root.

## إعداد Nginx

اجعل `root` يشير إلى مجلد `public` وحده. اضبط حجم الطلب قليلاً فوق حجم الجزء (4 ميجابايت)، لا فوق حجم الفيديو الكامل:

```nginx
server {
    listen 443 ssl http2;
    server_name academy.example.com;
    root /var/www/sheet-academy/public;
    index index.php;
    client_max_body_size 6m;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ /\. {
        deny all;
    }
}
```

استبدل مسار PHP-FPM بإصدار PHP المثبت، وأضف إعدادات TLS المعتادة. لا تضف `alias` أو مساراً عاماً إلى `storage/app/private`. أبقِ الطلبات على نفس النطاق لتعمل حماية CSRF، واسمح بطرق `POST`, `HEAD`, و`PATCH` لمسار tus بحجم طلب 6 ميجابايت. `client_max_body_size` يحد حجم جزء tus، وليس حجم الفيديو الإجمالي البالغ 10 جيجابايت.

## تشغيل عامل Node تحت Supervisor

احفظ إعدادات الخدمة في `/etc/sheet-academy/video-worker.env` وقيّد قراءته إلى مستخدم التطبيق. ثم أنشئ `/etc/supervisor/conf.d/sheet-academy-video-worker.conf`:

```ini
[program:sheet-academy-video-worker]
directory=/var/www/sheet-academy/video-worker
command=/usr/bin/node --env-file=/etc/sheet-academy/video-worker.env /var/www/sheet-academy/video-worker/index.js
user=www-data
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=30
stopsignal=TERM
stdout_logfile=/var/log/supervisor/sheet-academy-video-worker.log
stderr_logfile=/var/log/supervisor/sheet-academy-video-worker-error.log
```

ثبّت اعتمادات الخدمة وشغّلها:

```bash
cd /var/www/sheet-academy/video-worker
npm ci --omit=dev
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status sheet-academy-video-worker
```

يفتح Node واجهته على loopback فقط. لا تنشر المنفذ 3300 في Nginx أو جدار الحماية. يستخدم العامل عاملاً واحداً افتراضياً؛ ارفع `WORKER_CONCURRENCY` فقط بعد قياس الذاكرة وعدد أنوية المعالج ومساحة القرص.

## التحقق والتشغيل اليومي

- اختبر رفع فيديو قصير من لوحة الأستاذ، ثم تأكد من ظهور `جاهز` ومن وصول الطالب المسجل للمادة إلى الفيديو.
- راقب `storage/app/private`, مساحة `/tmp` وسجلات Supervisor وNginx وLaravel.
- يزيل إجراء حذف الدقة ملفات تلك النسخة نهائياً من التخزين الخاص؛ يلزم إعادة معالجة المصدر لاستعادتها.
- احتفظ بنسخة احتياطية من قاعدة البيانات ومفاتيح الفيديو الخاصة مع صلاحيات وصول مقيدة. فقدان مفتاح فيديو يعني ضرورة إعادة معالجة مصدره.
- لا تحذف `video-uploads` النشطة. ضع مهمة تنظيف دورية تحذف جلسات الرفع المنتهية وملفات الأجزاء اليتيمة بعد مدة احتفاظ مناسبة.
- راجع مساحة مجلد HLS قبل تفعيل المزيد من الدقات؛ مجموع ملفات HLS أكبر من المصدر في بعض الحالات.

## حدود الحماية

### حالة دمج بصمة الطالب في الفيديو

الدمج الشخصي داخل بكسلات الفيديو بـFFmpeg **غير مفعّل**. العامل `processVideo` يولد نسخ HLS مشتركة للدرس؛ وضع علامة مختلفة لكل طالب يتطلب إعادة ترميز مستقلة لكل طالب أو خدمة بث تدعم بصمة جنائية شخصية. ذلك يضاعف كلفة المعالجة والتخزين ويحتاج قياس عدد الطلاب والتزامن قبل تفعيله. علامة ثابتة باسم الأستاذ لا تحقق البصمة الشخصية المطلوبة.

البديل المنفذ هو معرّف HMAC صادر من السيرفر، وطبقة بصمة متحركة مع كشف سيناريوهات العبث وإيقاف العرض وإبطال رابط الجلسة، وسجل مخالفات وتعهد وتجميد. في الملازم والصور تُدمج البصمة فعلياً في PNG من السيرفر، إضافةً إلى الطبقة المتحركة. تعطيل كود العميل بالكامل يمكن أن يتجاوز طبقة الفيديو؛ لا نسوّق ذلك على أنه حماية غير قابلة للتجاوز.

الروابط الموقعة تنتهي بعد ثماني دقائق ويعيد المشغّل إصدار روابط قبل انتهائها. الوصول إلى القائمة والمقطع والمفتاح يحتاج جلسة طالب فعالة وتخصيص المادة. هذه الضوابط تقلل مشاركة الروابط والوصول المباشر، لكنها لا تمنع تصوير الشاشة أو تسجيل الصوت أو نسخ بيانات الفيديو بعد فك تشفيرها داخل متصفح مخوّل. خيار `controlslist="nodownload"` يخفي زر التنزيل الأصلي فقط ولا يوقف أدوات المطور أو تسجيل الشاشة. AES-128 هنا تشفير HLS تشغيلي؛ ليس DRM معتمدًا من Widevine أو FairPlay أو PlayReady.
