# رفع اشکال

| مشکل | علت و راه‌حل |
|---|---|
| صفحه سفید / «سامانه به درستی مستقر نشده» | فایل‌ها باید کنار public_html باشند (نه داخل آن). ساختار `docs/DEPLOYMENT-DirectAdmin.md` را بررسی کنید. |
| خطای 500 بلافاصله پس از آپلود | اگر وب‌سرور اجازه `Options` در .htaccess نمی‌دهد، خط `Options -Indexes` را از `public_html/.htaccess` حذف و Directory Listing را در DirectAdmin غیرفعال کنید. نسخه PHP را ≥ 8.1 انتخاب کنید. |
| 404 برای همه صفحات به جز صفحه اول | mod_rewrite غیرفعال است: در `.env` مقدار `APP_PRETTY_URLS=false` بگذارید. |
| «خطای غیرمنتظره… کد پیگیری XXXX» | سیستم → خطاهای سامانه → کد را جست‌وجو کنید. یا فایل `storage/logs/app-تاریخ.log`. |
| آپلود ویدیو ناموفق | `upload_max_filesize` و `post_max_size` در تنظیمات PHP DirectAdmin و «حداکثر حجم آپلود» در تنظیمات سامانه. |
| «اتصال به دیتابیس برقرار نیست» در Health | اطلاعات DB در `.env` (میزبان معمولاً `localhost`). |
| Cron در Health «Warning» | Cronjob را طبق راهنما بسازید؛ مسیر PHP صحیح را از DirectAdmin بگیرید. |
| سامانه در «حالت تعمیر» ماند | از پنل مدیر کل: بروزرسانی سامانه → خروج از حالت تعمیر؛ یا حذف فایل `storage/maintenance.flag`؛ یا `php cli.php maintenance:off`. |
| بروزرسانی: «پوشه‌ها قابل نوشتن نیستند» | مالک فایل‌ها باید کاربر DirectAdmin باشد (Reset Permissions در File Manager). |
| فراموشی رمز مدیر کل | `php cli.php root:password 'NewPass2026'` (از Terminal یا Cronjob یک‌باره). |
| ورود SSO خطای «پاسخ نامعتبر از my» | نگاشت فیلدها و آدرس User Info را در «اتصال SSO» بررسی کنید؛ جزئیات در لاگ (بدون افشای توکن). |
| ساعت/تاریخ اشتباه | `APP_TIMEZONE=Asia/Tehran` در `.env`. |
