# اتصال آراد کانتکت

سامانه فروش «آراد کانتکت» پس از هر خرید، حساب تاجر را در سامانه آموزش می‌سازد (یا پیدا می‌کند) و خدمات خریداری‌شده را شارژ می‌کند.

## راه‌اندازی
1. Migration شماره `2026_09_30_000011_arad_contact_integration` را اجرا کنید (پنل ← Migrationها یا `php cli.php migrate`).
2. پنل ← سیستم ← **اتصال آراد کانتکت**: مدیر کل «ساخت توکن تصادفی» را می‌زند (یا توکن داده‌شده توسط آراد کانتکت را ثبت می‌کند، حداقل ۲۴ کاراکتر). توکن فقط یک‌بار نمایش داده می‌شود و فقط هش آن ذخیره می‌شود.
   - جایگزین: `ARAD_CONTACT_TOKEN=...` در `.env`.
3. (اختیاری) IPهای مجاز، نقش/گروه/سطح پیش‌فرض و «فعال‌سازی خودکار» را در همان صفحه تنظیم کنید.

## احراز هویت
همه درخواست‌ها: `Authorization: Bearer <توکن>` — توکن غلط یا نبودِ هدر ← `401`.
(`public_html/.htaccess` هدر Authorization را برای PHP در حالت CGI/FastCGI عبور می‌دهد.)
محدودیت‌ها: ۲۴۰ درخواست در دقیقه برای هر IP؛ پس از ۲۰ تلاش با توکن غلط، آن IP پانزده دقیقه مسدود می‌شود (`429`).

## ۱) فهرست خدمات
`GET /api/integrations/arad-contact/services`
```json
{"success":true,"services":[
 {"code":"platform_account","title":"اکانت سامانه آموزش آراد برندینگ","units":["سالانه"]},
 {"code":"webinar","title":"وبینار تجاری","units":["ساعت"]},
 {"code":"workshop","title":"کارگاه تجاری آنلاین","units":["عدد"]},
 {"code":"skill_files","title":"فایل‌های تجاری مهارت‌محور","units":["ساعت"]},
 {"code":"online_meeting","title":"میتینگ آنلاین","units":["سالانه"]}]}
```

| کد | اثر در حساب | balance در پاسخ |
|---|---|---|
| platform_account | تمدید اکانت سامانه، هر واحد ۱۲ ماه (از پایان اشتراک جاری، وگرنه از امروز) | روزهای باقی‌مانده + `valid_until` |
| webinar | اعتبار وبینار، هر ساعت ۶۰ دقیقه | ساعت |
| workshop | اعتبار کارگاه، تعداد | عدد |
| skill_files | اعتبار دوره‌ها/درس‌ها، هر ساعت ۶۰ دقیقه | ساعت |
| online_meeting | تمدید اشتراک میتینگ، هر واحد ۱۲ ماه | روزهای باقی‌مانده + `valid_until` |

## ۲) اعمال خرید
`POST /api/integrations/arad-contact/provision` — `Content-Type: application/json`
```json
{"external_id":"arad-contact-order-123-item-456","mobile":"09123456789","first_name":"علی","last_name":"رضایی",
 "items":[{"service_code":"webinar","quantity":10,"unit":"ساعت"}]}
```
- `mobile`: شکل‌های `0912…`، `912…`، `+98912…`، `0098912…` و ارقام فارسی یکی حساب می‌شوند.
- `quantity`: عدد صحیح ۱ تا ۱۰۰۰۰۰. `unit` اختیاری است (پیش‌فرض: تنها واحد آن خدمت). حداکثر ۵۰ قلم.
- کاربر نبود ← ساخته می‌شود: نوع «تاجر»، نقش «تاجر فراگیر»، گروه «تاجران» با سطح «تاجر آموز»، نام کاربری = موبایل، رمز تصادفی ۱۰ کاراکتری (بدون 0/O/o و 1/l/I/i)، **تغییر اجباری رمز در اولین ورود**.
- کاربر بود ← رمزش تغییر نمی‌کند؛ نام فقط اگر خالی بوده پر می‌شود.
- همه‌چیز در یک تراکنش: یا کل سفارش اعمال می‌شود یا هیچ.
- **external_id یکتا است**: ارسال دوباره، هیچ چیز را دوباره شارژ نمی‌کند و همان پاسخ قبلی را با `"duplicate":true` برمی‌گرداند (تا وقتی کاربر رمز موقت را عوض نکرده، رمز هم دوباره برگردانده می‌شود تا قطعی شبکه باعث گم‌شدن آن نشود).

پاسخ موفق (`200`):
```json
{"success":true,"duplicate":false,"user_created":true,"user_id":57,"user_status":"active",
 "username":"09123456789","password":"Xk7pQm2Rta","login_url":"https://edu.aradbranding.me/login",
 "applied":[{"service_code":"webinar","quantity":10,"unit":"ساعت","balance":10,"balance_unit":"ساعت"}]}
```
`password` فقط برای حساب تازه ساخته‌شده مقدار دارد؛ برای کاربر موجود `null` است.

خطا: `{"success":false,"message":"..."}`

| کد | علت |
|---|---|
| 401 | هدر یا توکن نامعتبر |
| 403 | IP غیرمجاز |
| 405 | متد اشتباه |
| 409 | موبایل متعلق به مدیر کل است / external_id در حال پردازش |
| 422 | داده نامعتبر (JSON، موبایل، کد خدمت، واحد، تعداد…) |
| 429 | تعداد درخواست یا تلاش ناموفق بیش از حد |
| 503 | اتصال غیرفعال یا توکن تعریف نشده / قفل هم‌زمانی |
| 500 | خطای داخلی — هیچ خدمتی شارژ نشده، همان درخواست را دوباره بفرستید |

## لاگ
هر درخواست (حتی رد شده) در جدول `arad_contact_logs` و فایل لاگ ثبت می‌شود و در صفحه «اتصال آراد کانتکت» با جزئیات درخواست/پاسخ قابل مشاهده است. رمز عبور هرگز در لاگ ذخیره نمی‌شود.

## نمونه
```bash
curl -H "Authorization: Bearer TOKEN" https://edu.aradbranding.me/api/integrations/arad-contact/services
curl -X POST -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -d '{"external_id":"arad-contact-order-123-item-456","mobile":"09123456789","first_name":"علی","last_name":"رضایی","items":[{"service_code":"webinar","quantity":10,"unit":"ساعت"}]}' \
  https://edu.aradbranding.me/api/integrations/arad-contact/provision
```
