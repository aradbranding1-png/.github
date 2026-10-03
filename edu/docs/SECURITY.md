# امنیت و گزارش Security Check

## معماری امنیتی
| حوزه | پیاده‌سازی |
|---|---|
| Web Root | فقط `public_html/index.php` و `assets/` از وب در دسترس است. `app/`، `database/`، `storage/`، `docs/`، `.env`، `cli.php`، `cron.php` خارج از Web Root. هر پوشه خصوصی `.htaccess` با `Require all denied` دارد؛ `.htaccess` ریشه در صورت اشتباه در Document Root، همه را به public_html هدایت می‌کند. |
| فایل‌های حساس | هیچ `config.php / db.php / migrate.php / update.php / install.php / setup.php` قابل اجرای مستقیمی وجود ندارد. نصب فقط پیش از نصب فعال است و پس از آن 404؛ Migration/Update/Backup فقط از طریق مسیرهای احرازهویت‌شده **مدیر کل** + تأیید رمز + Audit Log، یا CLI (که از وب اجرا نمی‌شود). |
| اجرای PHP در Web Root | `.htaccess` اجرای هر فایل PHP به جز index.php را مسدود می‌کند؛ آپلودها با نام تصادفی خارج از Web Root ذخیره می‌شوند. |
| Directory Listing | `Options -Indexes` |
| Secrets | همه از `.env` (دیتابیس، SSO، API Key، کلید امضا). هیچ رمز/توکنی در کد نیست. کلید API به صورت SHA-256 ذخیره می‌شود. رمز کاربران با Argon2id/Bcrypt. |
| لاگ‌ها | `storage/logs` خارج از Web Root؛ کلیدهای password/token/secret/authorization خودکار REDACT می‌شوند. |
| Error Handling | در Production هیچ Stack Trace، مسیر سرور، خطای دیتابیس یا تنظیمات نمایش داده نمی‌شود؛ فقط پیام فارسی و «کد پیگیری». مدیر کل خطاها را در «خطاهای سامانه» می‌بیند. |
| Authentication | Session امن (HttpOnly, SameSite=Lax, Secure روی HTTPS، strict mode، بازتولید شناسه هنگام ورود، انقضای عدم فعالیت، اتصال به User-Agent)، Rate Limit ورود (۶ تلاش برای هر نام کاربری و ۲۰ برای هر IP در ۱۵ دقیقه). |
| Authorization | همه مسیرها به صورت پیش‌فرض نیازمند ورود؛ مجوز هر مسیر در `routes.php` + بررسی محدوده داده و مالکیت در کنترلرها؛ جلوگیری از افزایش سطح دسترسی؛ مجوزهای فقط مدیر کل غیرقابل اعطا. |
| CSRF | توکن برای همه درخواست‌های POST (به جز API با توکن Bearer). |
| XSS | همه خروجی‌ها با `e()` escape؛ متن غنی دوره/درس با Sanitizer مبتنی بر allow-list؛ CSP سخت‌گیرانه (`script-src 'self'`، بدون اسکریپت inline). |
| SQL Injection | فقط Prepared Statement (PDO با EMULATE_PREPARES=false)؛ شناسه‌های جدول با allow-list. |
| آپلود | allow-list پسوند + تطبیق MIME واقعی (finfo) + بررسی تصویر + محدودیت حجم + رد محتوای اسکریپت + بازنویسی آواتار با GD؛ سرو فایل فقط پس از بررسی دسترسی (ثبت‌نام در دوره) و پرچم «مشاهده آنلاین/دانلود». |
| Headers | X-Frame-Options، X-Content-Type-Options، Referrer-Policy، Permissions-Policy، CSP، HSTS روی HTTPS. |
| Impersonation | فقط مدیر کل، با ثبت مدیر، کاربر مقصد، زمان شروع/پایان، مدت، IP؛ نوار هشدار دائمی و بازگشت امن. |
| SSO | state + PKCE، بررسی امضا/انقضا/استفاده مجدد JWT، TLS اجباری، اتصال فقط با شناسه یکتای my. |
| Backup | خارج از Web Root، دانلود/بازیابی فقط مدیر کل با تأیید مجدد رمز. |
| Update | Checksum، Path Traversal، Symlink، مسیرهای مجاز، بررسی نحوی PHP، امضای Ed25519 اختیاری، Atomic replace، Rollback. |
| Excel | جلوگیری از Formula Injection در خروجی‌ها. |

## جلوگیری از 403/500 ناخواسته
- قوانین `.htaccess` فقط فایل‌های پنهان، پسوندهای حساس و فایل‌های PHP غیر از index.php را مسدود می‌کنند؛ دارایی‌ها و Routeها بدون مشکل سرو می‌شوند و `/.well-known/` (SSL) باز است.
- همه دستورات Apache داخل `<IfModule>` هستند و فقط `Options -Indexes` خارج از آن است (در DirectAdmin مجاز است).
- در نبود mod_rewrite سامانه با `index.php?r=` کار می‌کند.

## گزارش Security Check (نسخه 1.0.0)
| بررسی | وضعیت |
|---|---|
| فایل‌های حساس (config/db/migrate/update/install/setup/.env) | ✅ وجود ندارند یا خارج از Web Root هستند |
| تست مستقیم URLهای حساس: `/.env` `/config.php` `/db.php` `/migrate.php` `/update.php` `/install.php` `/setup.php` `/migrations/` `/backup/` `/storage/` `/logs/` `/app/bootstrap.php` `/cli.php` `/cron.php` `/install` `/index.php?r=/install` | ✅ همه 403/404 بدون افشای اطلاعات |
| Authentication | ✅ مسیرهای مدیریتی بدون ورود به /login هدایت می‌شوند؛ API بدون توکن 401 |
| Authorization | ✅ تست‌شده: مسئول آموزش به نقش‌ها/بروزرسانی/پشتیبان 403، فقط ۴ نفر تیم خود را می‌بیند و گزارش دیگران 403؛ مدیر آموزش نمی‌تواند نقش ویرایش کند، Impersonate کند یا مدیر کل را حذف کند |
| Permissionها | ✅ منع فردی بر نقش اولویت دارد؛ مجوز فقط مدیر کل حتی با درج مستقیم در دیتابیس اعمال نمی‌شود |
| CSRF | ✅ POST بدون توکن ← 419 |
| Secretها | ✅ جست‌وجو در کد: هیچ رمز/کلید سخت‌کد نشده؛ همه از .env |
| فایل‌های اضافی Production | ✅ بسته نصب فاقد .env، *.sql، *.zip، *.log، .git، __MACOSX، فایل‌های تست/دیباگ است؛ Health Check پاکیزگی public_html را پایش می‌کند |
| Migration | ✅ ۴ Migration روی دیتابیس تمیز اجرا شد؛ داشبورد وضعیت تست شد |
| Update | ✅ بروزرسانی واقعی 1.0.0→1.0.1 با Backup و Migration |
| Recovery | ✅ بروزرسانی معیوب با بازگردانی خودکار فایل و دیتابیس؛ بازگردانی دستی |
| Health Check | ✅ همه موارد OK (به جز Cron/SSO/HTTPS که به تنظیمات سرور واقعی وابسته است) |
| نصب از صفر | ✅ |
| Routeهای اصلی | ✅ بیش از ۶۰ صفحه پنل و یادگیری با کد 200 و بدون خطای JavaScript |

> محیط تست: PHP 8.4 + سرور MySQL-سازگار (MySQL 8 wire protocol). رفتار `.htaccess` با قوانین استاندارد Apache 2.4/LiteSpeed نوشته شده؛ پس از استقرار روی DirectAdmin، URLهای جدول بالا را یک‌بار دیگر روی دامنه واقعی بررسی کنید.
