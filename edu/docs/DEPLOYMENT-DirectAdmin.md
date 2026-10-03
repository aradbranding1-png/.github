# راهنمای استقرار روی DirectAdmin — edu.aradbranding.me

## ۱. پیش‌نیازهای سرور
| مورد | مقدار |
|---|---|
| PHP | 8.1 به بالا (پیشنهادی **8.2 یا 8.3**) — PHP-FPM یا LSPHP |
| دیتابیس | MySQL 5.7+/8.0 یا MariaDB 10.4+ (utf8mb4) |
| افزونه‌های لازم | `pdo_mysql` `mbstring` `json` `openssl` `fileinfo` `zip` `gd` |
| افزونه‌های توصیه‌شده | `curl` (برای SSO و تست بوت پس از بروزرسانی)، `dom`، `intl`، `sodium`، OPcache |
| وب‌سرور | Apache یا LiteSpeed با `mod_rewrite` (بدون mod_rewrite هم کار می‌کند) |
| Composer / Node.js / npm | **لازم نیست** |
| Queue / Redis | **لازم نیست** (کارهای پس‌زمینه با Cron) |
| SSH | لازم نیست؛ همه کارها از پنل DirectAdmin و پنل مدیر کل سامانه |

### تنظیمات PHP (DirectAdmin → PHP Settings / Select PHP version → Options)
```
upload_max_filesize = 512M     ; حجم ویدیوهای آموزشی
post_max_size       = 520M
memory_limit        = 256M
max_execution_time  = 300      ; برای پشتیبان‌گیری و بروزرسانی
display_errors      = Off
```

## ۲. ساخت دیتابیس
DirectAdmin → **MySQL Management** → Create new Database. نام دیتابیس، نام کاربری و رمز را یادداشت کنید (Collation: `utf8mb4_unicode_ci`).

## ۳. آپلود فایل‌ها
ساختار صحیح روی سرور:
```
/home/USER/domains/edu.aradbranding.me/
├── app/            ← کد برنامه (خارج از Web Root)
├── database/       ← Migrationها
├── docs/
├── storage/        ← آپلودها، پشتیبان‌ها، لاگ‌ها، نشست‌ها (خارج از Web Root)
├── public_html/    ← تنها پوشه قابل دسترس از وب (index.php + assets)
├── cli.php  cron.php  VERSION  manifest.json  .htaccess  .env.example
└── .env            ← توسط نصب‌کننده ساخته می‌شود
```
1. DirectAdmin → **File Manager** → وارد پوشه `domains/edu.aradbranding.me/` شوید.
2. فایل `index.html` پیش‌فرض داخل `public_html` را حذف کنید.
3. فایل ZIP را در همین پوشه (نه داخل public_html) آپلود و **Extract** کنید.
4. سطح دسترسی: پوشه‌ها `755`، فایل‌ها `644`، پوشه `storage` و زیرپوشه‌هایش باید قابل نوشتن باشند (`755` در حالت suexec/PHP-FPM کافی است).

> اگر به هر دلیل Document Root دامنه روی پوشه اصلی پروژه تنظیم شده باشد، فایل `.htaccess` ریشه همه درخواست‌ها را به `public_html/` هدایت و پوشه‌های خصوصی را مسدود می‌کند.

## ۴. SSL
DirectAdmin → **SSL Certificates** → Free & automatic certificate from Let's Encrypt → برای `edu.aradbranding.me` صادر کنید و گزینه **Force SSL with https redirect** را فعال کنید. مسیر `/.well-known/` در قوانین امنیتی باز گذاشته شده است.

## ۵. نصب
آدرس `https://edu.aradbranding.me/` را باز کنید (به `index.php?r=/install` هدایت می‌شوید):
- جدول پیش‌نیازها باید همه سبز باشد.
- آدرس سامانه، اطلاعات دیتابیس و حساب **مدیر کل** (نام، موبایل، رمز) را وارد کنید.
- نصب‌کننده: فایل `.env` را با کلید تصادفی می‌سازد، همه Migrationها را اجرا می‌کند، نقش‌ها و دسترسی‌های پیش‌فرض، گروه‌های اصلی، سطوح، ساختار سازمانی و موضوعات آموزشی تاجران را ایجاد می‌کند و مدیر کل (ROOT_ADMIN با تیک آبی) را می‌سازد.
- پس از نصب، فایل `storage/installed.lock` ساخته شده و مسیر نصب **برای همیشه غیرفعال** (404) می‌شود.

## ۶. Cron
DirectAdmin → **Advanced Features → Cronjobs**:
```
*/15 * * * *   /usr/local/bin/php /home/USER/domains/edu.aradbranding.me/cron.php >/dev/null 2>&1
```
(مسیر PHP را با `which php` یا از بخش PHP DirectAdmin بررسی کنید؛ مثلاً `/usr/local/php83/bin/php`).
وظایف Cron: منقضی‌کردن آموزش‌های سررسیدشده، بستن آزمون‌های زمان‌گذشته، یادآوری مهلت، اجرای قوانین تخصیص خودکار، ارزیابی نظام رشد، یادآوری عدم فعالیت، پشتیبان شبانه دیتابیس (۱۰ نسخه آخر)، پاک‌سازی فایل‌های موقت.

## ۷. پس از نصب
1. ورود با موبایل و رمز مدیر کل.
2. **سیستم → سلامت سامانه**: همه موارد OK باشد (Cron پس از اولین اجرا OK می‌شود).
3. **ساختار → ساختار سازمانی**: معاونت‌ها، واحدها، بخش‌ها و سمت‌ها.
4. **کاربران و دسترسی → نقش‌ها**: دسترسی نقش‌های پیش‌فرض را بازبینی و تیم مدیریتی را به نقش‌ها اضافه کنید.
5. **سیستم → اتصال SSO**: پس از دریافت API رسمی my (راهنما: `docs/SSO.md`).
6. **سیستم → پشتیبان‌گیری**: یک پشتیبان کامل بگیرید.

## ۸. بدون mod_rewrite
اگر تست نصب‌کننده «آدرس تمیز» را غیرفعال نشان دهد، سامانه با آدرس‌های `index.php?r=/...` کار می‌کند (`APP_PRETTY_URLS=false`). هیچ قابلیتی از دست نمی‌رود.

## ۹. بازیابی دسترسی مدیر کل
از **Terminal** در DirectAdmin (در صورت وجود) یا Cronjob یک‌باره:
```
/usr/local/bin/php /home/USER/domains/edu.aradbranding.me/cli.php root:password 'NewPass2026'
```
