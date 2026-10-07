# دليل النشر وتطبيق سطح المكتب

## البنية

```text
الطلاب ─ HTTPS ─ Nginx ─ PHP-FPM/Laravel ─ MySQL
                                  ├─ Redis (cache/queue)
                                  ├─ تخزين خاص (المرفقات وHLS والمفاتيح)
                                  └─ Node video-worker ─ FFmpeg/ffprobe
```

Nginx نقطة الدخول الوحيدة. عامل Node يستمع على `127.0.0.1:3300`. لا تنشر MySQL أو Redis أو العامل للإنترنت.

## متطلبات مبدئية

| الحمل | التطبيق + DB + Redis | عامل التحويل | الشبكة الصادرة | تخزين الفيديو |
|---|---|---|---|---|
| ≤25 مشاهداً متزامناً / نحو 100 فيديو قصير | 4 vCPU، 8 GB RAM، NVMe 150 GB | عامل واحد، يمكن مشاركة الخادم كبداية | 100 Mbps تقريباً | 250 GB |
| ≤100 مشاهد / نحو 500 فيديو | 8 vCPU، 16–32 GB، يفضل فصل DB والعامل | 4–8 vCPU و16 GB | 1 Gbps | 1–2 TB |
| ≥500 مشاهد | App وDB منفصلان، موازنة حمل | عمال منفصلون | ≥2 Gbps أو CDN | Object Storage خاص |

الأرقام تخطيط أولي وليست ضمان سعة. معدل الدقات النظري مع الصوت يقارب 11 Mbps لكل مجموعة 240–1080p؛ فيديو 30 دقيقة قد يحتاج نحو 2.5 GB للمخرجات قبل الأصل. احسب `ساعات الفيديو × مجموع bitrate Mbps × 0.45 = GB تقريبية`، ثم أضف الأصل والنسخ الاحتياطية ومساحة العمل. للشبكة استخدم `المشاهدون المتزامنون × متوسط bitrate × 1.3`. اختبر بملفاتك الفعلية قبل شراء السعة النهائية.

## 1. Ubuntu والجدار الناري

استخدم Ubuntu Server LTS مدعوماً، نطاق DNS صحيحاً، مستخدم نشر غير root، دخول SSH بالمفتاح، وتعطيل root/password بعد تجربة دخول المفتاح.

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server redis-server ffmpeg poppler-utils supervisor git unzip curl ca-certificates
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

ثبّت PHP-FPM إصداراً يوافق `^8.3` وامتدادات `curl, mbstring, xml, mysql, zip, intl, bcmath, pcntl, redis, gd` حسب التطبيق. ثبّت Composer 2 مع التحقق من التوقيع/checksum، وNode.js LTS مدعوماً وnpm. تحقق:

```bash
php -v
node --version
ffmpeg -version
ffprobe -version
pdfinfo -v
pdftoppm -v
```

## 2. الملفات والنشر الأولي

اجعل جذر Nginx هو `.../current/public` فقط. هيكل مقترح:

```text
/srv/sheet-academy/current/        release التطبيق
/srv/sheet-academy/shared/.env
/srv/sheet-academy/shared/storage/  يتضمن app/private
/srv/sheet-academy/backups/         لا تعتمد عليه وحده
```

اربط `current/storage` بمجلد مشترك. امنح FPM/العامل الكتابة إلى `storage/app/private`, `storage/framework`, `storage/logs` فقط. أبقِ المرفقات والأصل وHLS والمفاتيح خارج `public`; صلاحيات المجلد الخاص `0700` والملف `0600`. لا تنفذ `storage:link` لـprivate.

```bash
cd /srv/sheet-academy/current
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

نفّذ `php artisan key:generate` مرة واحدة عند تأسيس البيئة فقط، وليس في كل نشر. أنشئ `.env` منفصلاً ولا تنسخ أسرار التطوير. إعداد إنتاجي أساسي:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://academy.example.edu
LOG_LEVEL=warning
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=redis
CACHE_STORE=redis
FILESYSTEM_DISK=private
DB_CONNECTION=mysql
REDIS_HOST=127.0.0.1
VIDEO_WORKER_URL=http://127.0.0.1:3300
VIDEO_WORKER_TOKEN=<قيمة عشوائية طويلة مشتركة مع العامل>
INTERNAL_CALLBACK_URL=https://academy.example.edu/internal/video-processing
```

اجعل مستخدم MySQL محدوداً بقاعدة المنصة، لا root. استخدم Redis ACL/كلمة مرور واتصال loopback. إذا كان عامل FFmpeg على خادم آخر، شارك التخزين عبر mount خاص موثوق أو Object Storage خاص؛ لا تكفي مشاركة DB وحدها.

## 3. Nginx وTLS

التجزئة tus حجمها 4 MiB؛ حد الجسم 8 MiB يكفي للـPATCH. `Upload-Length` هو مجموع الفيديو، لا جسم طلب واحد.

```nginx
server {
    listen 80;
    server_name academy.example.edu;
    root /srv/sheet-academy/current/public;
    index index.php;
    client_max_body_size 8m;
    server_tokens off;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
    location ^~ /storage/app/private/ { deny all; }
    location ~* \.(?:css|js|woff2|png|jpg|jpeg|webp|svg)$ {
        try_files $uri =404;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json application/manifest+json image/svg+xml;
}
```

استبدل نطاق المثال ومسار FPM بإصدار الجهاز ثم `sudo nginx -t`. فعّل الشهادة:

```bash
sudo snap install --classic certbot
sudo ln -s /snap/bin/certbot /usr/local/bin/certbot
sudo certbot --nginx -d academy.example.edu
sudo certbot renew --dry-run
```

لأن رابط المشاهدة والتوقيع يمران في query string، لا تسجل `$request` أو `$args` أو Referer. أضف داخل سياق `http` في Nginx (ملف ضمن `conf.d`):

```nginx
log_format academy_safe '$remote_addr [$time_local] "$request_method $uri $server_protocol" $status $body_bytes_sent "$http_user_agent"';
```

وفي server الموقع استخدم `access_log /var/log/nginx/academy.access.log academy_safe;` مع تدوير وصلاحيات مناسبة. هذا يسجل URI دون query string أو Referer.

HSTS مفعّل من التطبيق في الإنتاج. لا تضف `includeSubDomains` أو `preload` حتى يكون كل نطاق فرعي HTTPS. لا تعمل cache عام لملفات HLS الموقعة أو ردود المفتاح؛ الضغط والتخزين المؤقت يقتصران على assets العامة. CDN للفيديو الخاص يحتاج edge authorization وتوقيعاً يعادل تحقق Laravel.

## 4. Supervisor وNode worker

أنشئ `/etc/supervisor/conf.d/sheet-video-worker.conf`، واجعل ملف الأسرار root-owned بصلاحية `0600` (الأفضل EnvironmentFile منفصل):

```ini
[program:sheet-video-worker]
directory=/srv/sheet-academy/current/video-worker
command=/usr/bin/node /srv/sheet-academy/current/video-worker/index.js
user=sheetacademy
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=120
stopsignal=TERM
environment=NODE_ENV="production",PORT="3300",REDIS_URL="redis://:REDACTED@127.0.0.1:6379",PRIVATE_STORAGE_ROOT="/srv/sheet-academy/shared/storage/app/private",VIDEO_WORKER_TOKEN="REDACTED",INTERNAL_CALLBACK_URL="https://academy.example.edu/internal/video-processing",FFMPEG_PATH="/usr/bin/ffmpeg",FFPROBE_PATH="/usr/bin/ffprobe",WORKER_CONCURRENCY="1"
stdout_logfile=/var/log/sheet-academy/video-worker.log
stderr_logfile=/var/log/sheet-academy/video-worker-error.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=10
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

أضف برنامج queue Laravel مستقلاً: `php artisan queue:work redis --sleep=1 --tries=3 --timeout=120`. بعد الإصدار نفّذ `php artisan queue:restart`. ابدأ بـ`WORKER_CONCURRENCY=1` وارفعه فقط بعد قياس CPU/RAM/IO أثناء ملفات تمثيلية.

## 5. النسخ الاحتياطي والاستعادة

استخدم Restic أو ما يماثله إلى هدف منفصل ومشفّر. النسخ المطلوب: dump MySQL متسق، كامل `storage/app/private` (المرفقات وHLS والمفتاح والأصل عند الاحتفاظ به)، ونسخة أسرار `.env`/`APP_KEY` في Secret Manager مستقل. لا تضع dump مكشوفاً أو مفاتيح فك التشفير في الوجهة نفسها.

```bash
install -d -m 0700 /srv/sheet-academy/backups
mysqldump --defaults-extra-file=/root/.my.cnf --single-transaction --routines --triggers sheet_academy | gzip > /srv/sheet-academy/backups/db-$(date +%F-%H%M).sql.gz
```

ضعها في systemd timer يومي ثم ارفعها للمخزن الخارجي. احتفاظ نموذجي 7 يومية، 4 أسبوعية، 12 شهرية حسب RPO/RTO. راقب نجاح آخر نسخة وحجمها. شهرياً استعد نسخة إلى بيئة معزولة وافتح طالباً ومادة ومرفقاً وفيديو؛ النسخة غير المجربة ليست خطة استعادة.

## 6. Monitoring وLogs

- راقب `systemctl status nginx mysql redis-server php*-fpm`, `supervisorctl status`, `php artisan queue:failed` وBullMQ failed jobs.
- اجمع Laravel/Nginx/PHP-FPM/worker logs مع logrotate واحتفاظ 30–90 يوماً بحسب الخصوصية. احجب cookies وAuthorization وquery strings التي تحتوي `view` أو signature، ولا ترسل رقم الطالب لخدمة تحليلات عامة.
- راقب CPU/RAM/IO، القرص والـinode، Redis memory/queue depth، الوظائف المتعطلة، 4xx/5xx/429، زمن PHP، bandwidth ووقت آخر backup. نبه عند قرص 80% أو توقف العامل.
- أضف Node Exporter/Prometheus أو مراقب VPS وAPM بعد تنقيح الأسرار. `/up` يفحص Laravel فقط، لا العامل أو Redis/FFmpeg.

## 7. CI/CD اختياري

لكل Pull Request: تثبيت من lockfiles، `composer audit`, `npm audit`, `npm ci` داخل `video-worker`, الاختبارات، Pint، و`npm run build`. في النشر جهز release جديدة، اربط `.env` وstorage المشتركة، شغّل migrations، config/route/view cache، أعد PHP-FPM، `queue:restart`، ثم smoke-test للدخول ورفع صغير وبث ومرفق وcallback العامل. احتفظ بالإصدار السابق للرجوع. لا تنفذ migrations مدمرة آلياً.

## Electron

`electron/` يحتوي shell يقبل أصلاً واحداً بـHTTPS، دون Node في renderer، مع sandbox وcontext isolation وDevTools مغلق ورفض permissions/navigation الخارجية، و`setContentProtection(true)`. سكربت التحزيم يضمّن الأصل الفعلي في التطبيق ويرفض النطاقات التجريبية، كما يتطلب رابط تحديث HTTPS صريحاً.

```bash
cd electron
npm ci
ACADEMY_ORIGIN=https://academy.your-domain.tld npm start
ACADEMY_ORIGIN=https://academy.your-domain.tld UPDATE_FEED_URL=https://updates.your-domain.tld/sheet npm run dist:win
ACADEMY_ORIGIN=https://academy.your-domain.tld UPDATE_FEED_URL=https://updates.your-domain.tld/sheet npm run dist:mac
```

توقيع Windows يتطلب شهادة Authenticode (`WIN_CSC_LINK`, `WIN_CSC_KEY_PASSWORD`). توقيع macOS يتطلب Developer ID وnotarization (`CSC_LINK`, `CSC_KEY_PASSWORD` وبيانات Apple المعتمدة). احفظ الأسرار في CI secrets. وقّع الملفات وارفع metadata الخاصة بالمحدث إلى feed HTTPS واختبر تحديث Windows وmacOS على جهاز نظيف قبل التوزيع.

نسخة PWA محكومة بسياسات المتصفح ولا يمكنها استدعاء حماية نافذة نظام التشغيل. Electron يطلب `setContentProtection(true)`: Windows 10 إصدار 2004 وما بعده يخفي النافذة من واجهات الالتقاط المدعومة، والإصدارات الأقدم تعرض إطاراً أسود؛ macOS يستخدم `NSWindowSharingNone` لكن أدوات الالتقاط الأحدث التي تعتمد ScreenCaptureKit قد تلتقطها. هذه واجهة نظام وليست DRM، ولا تمنع كل أدوات التسجيل أو التصوير بكاميرا خارجية. لذا كلا الإصدارين يحتاج watermark ورصد ومحاسبة الطالب. Electron الأصلي يحدّث Windows/macOS؛ مستخدم Linux يتلقى إصداراً من مدير الحزم أو قناة AppImage التي جرى التحقق منها تحديداً.
