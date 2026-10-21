# زیرساخت برای چند صد تا چند هزار کاربر هم‌زمان

این راهنما همراه نسخه 1.19.14 است. کد همه موارد زیر را پشتیبانی می‌کند؛ هر کدام فقط با تنظیم `.env` و سرور فعال می‌شود و تا فعال نشود رفتار سایت مثل قبل است.

## ۱. Redis برای Session و Cache (اولویت اول)

نتیجه: دیگر هیچ Read/Write روی جدول `sessions` در MySQL و هیچ فایل Cache یا Rate-limit روی دیسک در هر درخواست انجام نمی‌شود.

```bash
# Ubuntu / Debian
apt install redis-server php8.3-redis        # یا از PHP Selector در DirectAdmin افزونه redis را فعال کنید
# در /etc/redis/redis.conf:
#   bind 127.0.0.1 ::1
#   requirepass <یک رمز طولانی>
#   maxmemory 1gb
#   maxmemory-policy volatile-lru     ← فقط کلیدهای TTLدار (Cache) حذف شوند، نه Session
systemctl enable --now redis-server
```

`.env`:
```
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=<همان requirepass>
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_PREFIX=sadt:
```

- با تغییر به Redis کسی از حساب خارج نمی‌شود: Sessionهای قبلی هنگام اولین درخواست از MySQL به Redis منتقل می‌شوند.
- اگر Redis از دسترس خارج شود، سایت به‌طور خودکار روی MySQL و File برمی‌گردد (در Log ثبت می‌شود و `/health` هشدار می‌دهد).
- «خروج از همه دستگاه‌ها»، مسدودسازی کاربر و تغییر رمز همچنان همه Sessionهای کاربر را باطل می‌کنند.
- `SESSION_TOUCH_SECONDS` (پیش‌فرض 300): هر چند ثانیه یک‌بار زمان آخرین فعالیت Session تمدید شود.

## ۲. چند Queue Worker با Supervisor به جای Cron

Cron هر دقیقه یک Worker با عمر ۵۵ ثانیه اجرا می‌کند؛ یعنی فقط یک پردازش و هر دقیقه یک‌بار بوت کامل PHP. با Supervisor چند Worker دائمی هم‌زمان کار می‌کنند. Jobها با `FOR UPDATE SKIP LOCKED` رزرو می‌شوند و Outbox فقط توسط یک Worker (با Lock) پردازش می‌شود، پس چند Worker امن است.

فایل نمونه: `docs/deploy/supervisor-sadt.conf` → در `/etc/supervisor/conf.d/` کپی کنید، مسیر و کاربر را اصلاح کنید، سپس:
```bash
supervisorctl reread && supervisorctl update && supervisorctl status
```
بعد از فعال شدن Supervisor، **خط Cron مربوط به `queue:work` را حذف کنید** (Cron هر ۱۵ دقیقه `cron.php` باید بماند).
بعد از هر بروزرسانی کد: `supervisorctl restart sadt-worker:*`

## ۳. جدا کردن دیتابیس از Web Server

- یک سرور جدا با دیسک NVMe و CPU نسل جدید برای MySQL 8.4 / MariaDB 11.4.
- شروع پیشنهادی `my.cnf` برای سرور 16GB:
  ```
  innodb_buffer_pool_size = 10G
  innodb_log_file_size = 1G        # MySQL 8.0.30+: innodb_redo_log_capacity = 2G
  innodb_flush_log_at_trx_commit = 1
  innodb_io_capacity = 2000
  max_connections = 300
  ```
- اتصال فقط از IP خصوصی Web Server (Firewall)، و در `.env`: `DB_HOST=<IP خصوصی>`.

## ۴. Read Replica (وقتی حجم Read بالا رفت)

در `.env`: `DB_READ_HOST=<IP replica>`. لایه دیتابیس از قبل پارامتر `useReplica` دارد؛ Queryهای گزارش و فهرست‌های عمومی را می‌توان مرحله‌به‌مرحله به آن منتقل کرد. نوشتن‌ها و هر Read بلافاصله بعد از نوشتن همیشه روی Primary می‌ماند.

## ۵. CDN برای Static و Media، و TRUSTED_PROXIES

`ASSET_URL` و `MEDIA_URL` از قبل روی ساب‌دامین جدا هستند؛ کافی است این دو ساب‌دامین پشت CDN بروند. فایل‌های Static با `Cache-Control: immutable` سرو می‌شوند.

اگر **خود سایت** (aradbranding.app) پشت CDN یا Load Balancer برود، حتماً:
```
TRUSTED_PROXIES=<IP یا رنج‌های CIDR آن CDN/LB، با ویرگول>
# فقط اگر CDN این هدر را همیشه بازنویسی می‌کند:
REAL_IP_HEADER=CF-Connecting-IP      # Cloudflare؛ برای Nginx LB معمولاً X-Real-IP
```
بدون این تنظیم، همه کاربران با IP خود CDN دیده می‌شوند و Rate-limit ورود برای همه یکی می‌شود. از 1.19.14، `X-Forwarded-For` از راست خوانده می‌شود و مقدار جعلی کاربر نادیده گرفته می‌شود.

## ۶. سخت‌افزار

پردازنده‌های Westmere (۲۰۱۰) تک‌هسته‌ای ضعیفی دارند و PHP برای هر درخواست تک‌هسته‌ای است؛ ارتقا به نسل جدید (مثلاً AMD EPYC Zen 3/4 یا Intel Ice Lake به بعد) زمان پاسخ هر صفحه را مستقیم کم می‌کند. همچنین OPcache با `opcache.validate_timestamps=0` در Production (و `opcache_reset` که بروزرسانی خودکار انجام می‌دهد).

## ۷. ترتیب پیشنهادی

1. Redis (Session + Cache) — کم‌هزینه‌ترین و بیشترین اثر
2. Supervisor با ۳ تا ۴ Worker
3. CDN برای static/media
4. سرور جدا برای دیتابیس با NVMe
5. ارتقای CPU سرور وب
6. Read Replica فقط اگر Slow Query Log و Server-Timing نشان دهد Read گلوگاه است
