# سامانه جامع آموزش آراد برندینگ — edu.aradbranding.me

LMS کامل برای **تاجران، کارمندان و نمایندگان** با مدیریت نقش و دسترسی ماتریسی، مدیر کل (تیک آبی)، SSO با my.aradbranding.me، مسیر آموزشی، نظام رشد، آزمون و بانک سؤال، تمرین، ارزیابی عملی، گواهی، گزارش‌های تصویری، تقویم فعالیت، Audit Log، پشتیبان‌گیری، Health Check، مدیریت Migration و سیستم بروزرسانی داخلی.

- **تکنولوژی:** PHP 8.1+ (پیشنهادی 8.2/8.3) + MySQL 5.7+/8 یا MariaDB 10.4+ — بدون نیاز به Composer، Node.js، SSH یا Queue.
- **رابط کاربری:** فارسی، RTL کامل، واکنش‌گرا (موبایل/دسکتاپ)، حالت تاریک، فونت وزیرمتن و نمودارهای داخلی (بدون CDN).
- **امنیت:** فقط `public_html/index.php` در Web Root است؛ کد، تنظیمات، لاگ، پشتیبان و فایل‌های آپلودی خارج از Web Root.

## نصب سریع (جزئیات: `docs/DEPLOYMENT-DirectAdmin.md`)
1. یک دیتابیس MySQL و کاربر آن را در DirectAdmin بسازید.
2. محتویات ZIP را در `/home/USER/domains/edu.aradbranding.me/` استخراج کنید (پوشه‌های `app/`، `storage/` و … کنار `public_html/` قرار می‌گیرند).
3. SSL را فعال و آدرس `https://edu.aradbranding.me/` را باز کنید؛ نصب‌کننده اجرا می‌شود.
4. اطلاعات دیتابیس و حساب **مدیر کل** را وارد کنید.
5. Cron را تنظیم کنید: `*/15 * * * * /usr/local/bin/php /home/USER/domains/edu.aradbranding.me/cron.php >/dev/null 2>&1`

## مستندات
| فایل | موضوع |
|---|---|
| `docs/DEPLOYMENT-DirectAdmin.md` | پیش‌نیازها، نصب، SSL، Cron، تنظیمات PHP |
| `docs/ROLES-PERMISSIONS.md` | نقش‌ها، ماتریس دسترسی، دسترسی فردی، محدوده داده، مدیر کل |
| `docs/ARCHITECTURE.md` | معماری، ماژول‌ها، دیتابیس، جریان‌های آموزشی |
| `docs/SSO.md` | اتصال به my.aradbranding.me |
| `docs/API.md` | REST API نسخه ۱ |
| `docs/ARAD-CONTACT.md` | اتصال آراد کانتکت (ساخت حساب و شارژ خدمات) |
| `docs/UPDATE-BACKUP.md` | بروزرسانی، Migration، پشتیبان‌گیری و بازیابی |
| `docs/SECURITY.md` | معماری امنیتی و گزارش Security Check |
| `docs/TROUBLESHOOTING.md` | رفع اشکال |
