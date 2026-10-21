# سامانه توسعه تجارت — فاز ۱: هویت و Page

International Business Networking Platform · `aradbranding.app`

این بسته هسته فنی سامانه است: Router، لایه دیتابیس، Cache، Session، صف، Outbox، Migration، Partition، Health Check، CLI، Cron و نصب‌کننده وب. ماژول‌های کسب‌وکار (کاربران، Page، Stars، Proposal، نامه) در فازهای ۱ تا ۶ روی همین هسته ساخته می‌شوند.

## پیش‌نیازها

| مورد | حداقل |
| --- | --- |
| PHP | 8.3 (8.4 توصیه می‌شود) با OPcache |
| Extensions | pdo_mysql, mbstring, openssl, json, fileinfo, sodium, curl, zip, intl, و gd یا imagick |
| Database | MySQL 8.0+ (8.4 LTS توصیه) یا MariaDB 10.6+ (11.4 LTS توصیه) |
| SSL | الزامی — پسوند `.app` فقط با HTTPS باز می‌شود |

هسته هیچ وابستگی Composer در زمان اجرا ندارد؛ Composer فقط برای اجرای تست‌ها لازم است.

## ساختار روی DirectAdmin

```
/home/USER/domains/aradbranding.app/
├── public_html/      ← Document Root (فقط index.php، assets، .htaccess)
├── app/  bootstrap/  config/  routes/  database/
├── storage/  backups/  bin/
├── cron.php
└── .env              ← خارج از public_html
```

## نصب

1. در DirectAdmin: دامنه `aradbranding.app` را اضافه کنید، SSL (Let's Encrypt) را فعال کنید و نسخه PHP را روی 8.3 یا 8.4 بگذارید.
2. یک دیتابیس و کاربر MySQL بسازید (Collation: `utf8mb4_unicode_520_ci`).
3. محتوای ZIP را در `domains/aradbranding.app/` آپلود و Extract کنید. پوشه `public_html` موجود جایگزین می‌شود.
4. دسترسی‌ها: پوشه‌های `storage/` و `backups/` باید برای کاربر PHP قابل نوشتن باشند (معمولاً 755 کافی است).
5. نصب را انجام دهید:
   - **با مرورگر:** به `https://aradbranding.app/install` بروید. فایل `storage/install.key` را در File Manager باز کنید و کلید را وارد کنید، سپس اطلاعات دیتابیس را بدهید.
   - **یا با SSH:** `cp .env.example .env`، اطلاعات دیتابیس را در `.env` بنویسید و `php bin/console install` را اجرا کنید.
6. Cron Jobs در DirectAdmin (مسیر PHP را با `which php` بررسی کنید؛ معمولاً `/usr/local/bin/php`):

```
* * * * *     /usr/local/bin/php /home/USER/domains/aradbranding.app/bin/console queue:work --max-time=55 >/dev/null 2>&1
*/15 * * * *  /usr/local/bin/php /home/USER/domains/aradbranding.app/cron.php >/dev/null 2>&1
```

7. بررسی: `php bin/console health` یا:
   `curl -H "X-Health-Token: <HEALTH_TOKEN>" https://aradbranding.app/health`

## دستورات CLI

| دستور | کار |
| --- | --- |
| `install` | نصب از خط فرمان |
| `keys:generate` | ساخت APP_KEY، SIGNING_KEY، HEALTH_TOKEN و OPS_TOKEN (`--only=HEALTH_TOKEN,OPS_TOKEN` برای چرخش توکن‌ها) |
| `migrate` / `migrate:status` | اجرا و وضعیت Migrationها |
| `partitions:ensure` | ساخت Partitionهای ماهانه آینده |
| `health` | بررسی سلامت کامل |
| `cache:clear` | پاک‌کردن Cache |
| `maintenance:on --secret=X` / `maintenance:off` | حالت نگه‌داری؛ عبور ادمین با `/__bypass/X` |
| `queue:work --max-time=55` | پردازش صف‌ها و Outbox |
| `queue:retry [id]` | بازگرداندن Jobهای ناموفق |
| `cron` | کارهای زمان‌بندی‌شده (پاک‌سازی، Partition، بازیابی Jobهای گیرکرده) |

دستورات `backup`، `restore`، `package:release` و `package:update` در فاز ۶ همراه با سیستم Update اضافه می‌شوند.

## اصول هسته (ارجاع به سند معماری)

- **Query Budget:** بیش از ۸ Query در یک درخواست در Log هشدار می‌دهد و در `APP_ENV=development` خطا می‌دهد (تشخیص N+1).
- **Server-Timing:** هر پاسخ زمان App، زمان DB و تعداد Query را در هدر دارد.
- **Slow Query Log:** Queryهای بالای `SLOW_QUERY_MS` در `storage/logs/slow-query-*.log`.
- **Transaction:** `$db->transaction(fn)` فقط روی Deadlock (1213) و Lock Timeout (1205) تا ۳ بار تکرار می‌شود.
- **Cache:** TTL نرم با ۵ دقیقه Grace، جلوگیری از Stampede، و `version()/bump()` به جای حذف Wildcard.
- **Session:** در MySQL با کلید SHA-256؛ کوکی `__Host-sadt`؛ `session:optional` صفحات عمومی را Cacheable نگه می‌دارد.
- **Outbox:** `Outbox::record()` داخل تراکنش اصلی؛ Worker رویدادها را Async پردازش می‌کند.
- **خطاها:** در Production فقط کد پیگیری نمایش داده می‌شود؛ جزئیات در `storage/logs/app-*.log`.

## فاز ۱: آنچه اضافه شد

- ثبت‌نام، ورود، خروج، «مرا به خاطر بسپار»، قفل ۱۵ دقیقه‌ای پس از ۱۰ تلاش ناموفق
- پروفایل، تصویر پروفایل، شبکه‌های اجتماعی، حریم خصوصی، تغییر رمز
- ۲۴۳ کشور (نام فارسی و انگلیسی از CLDR، کد تلفن) و ۲۷ زبان
- نقش‌ها و دسترسی‌ها (RBAC) با ۱۱ نقش پیش‌فرض
- صفحه تجاری چندزبانه، قوانین نمایش (Routing)، صفحه پیش‌فرض
- صفحه عمومی `/p/{handle}`: لایه عمومی رایگان و قابل ایندکس + لایه کامل برای کاربران واردشده
- تأیید ایمیل و تلفن فعلاً غیرفعال است (ستون‌ها آماده‌اند)

## به‌روزرسانی از فاز ۰ به فاز ۱ (بدون SSH)

1. ZIP جدید را در `domains/aradbranding.app/` آپلود و Extract کنید و اجازه Overwrite بدهید. فایل `.env` و پوشه `storage` دست نمی‌خورند.
2. Migrationها را اجرا کنید:
   ```
   curl -X POST -H "X-Ops-Token: <OPS_TOKEN>" https://aradbranding.app/ops/migrate
   ```
3. در `https://aradbranding.app/register` عضو شوید.
4. به حساب خود نقش Super Admin بدهید:
   ```
   curl -X POST -H "X-Ops-Token: <OPS_TOKEN>" -d "email=you@example.com" https://aradbranding.app/ops/promote-admin
   ```
   با SSH: `php bin/console migrate` و `php bin/console user:promote-admin you@example.com`

## تست

```
composer install
composer test
```
