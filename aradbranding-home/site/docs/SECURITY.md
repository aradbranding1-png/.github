# امنیت

| حوزه | کنترل |
| --- | --- |
| ورود | Argon2id، قفل ۱۵ دقیقه پس از ۱۰ خطا، Rate Limit جداگانه، پیام یکسان برای حساب ناموجود |
| Session | ذخیره در دیتابیس با کلید SHA-256، کوکی `__Host-`، HttpOnly، Secure، SameSite=Lax، بازتولید پس از ورود |
| فرم‌ها | CSRF Token در همه درخواست‌های تغییردهنده |
| خروجی | Escape همه مقادیر، CSP با Nonce، بدون JS درون‌خطی، HSTS، X-Frame-Options، nosniff |
| دیتابیس | فقط Prepared Statement، `EMULATE_PREPARES=false` |
| دسترسی | RBAC با محدوده داده در Backend، پاسخ 404 برای منابع دیگران (ضد IDOR) |
| Stars و پرداخت | قفل ردیف، کلید Idempotency، `CHECK (balance >= 0)`، Ledger فقط افزودنی، تأیید سرور به سرور، مبلغ از دیتابیس، مرجع یکتا |
| آپلود | فقط JPG/PNG/WebP، بررسی MIME واقعی، بازسازی کامل تصویر، حذف Metadata، نام تصادفی، سقف حجم |
| اطلاعات تماس | جلوگیری از درج در بخش‌های عمومی (ContactGuard) در ذخیره و نمایش |
| مدیریت | رمز مجدد برای کارهای حساس، ثبت در Audit Log، بنر ورود به جای کاربر |
| خطاها | پنهان‌بودن Stack Trace در Production، کد پیگیری |
| بروزرسانی | فقط با مجوز updates.manage و رمز مجدد، اعتبارسنجی مسیرهای ZIP، محافظت از .env و storage |
| توکن‌ها | `HEALTH_TOKEN` فقط برای `/health` (قابل دادن به مانیتورینگ). `OPS_TOKEN` جدا و فقط برای `/ops/*`؛ اگر خالی، کوتاه یا برابر HEALTH_TOKEN باشد همه `/ops/*` خاموش‌اند. `OPS_ALLOWED_IPS` اختیاری |
| فایل‌های حساس | `.env` و `backups/` باید 600/700 باشند؛ Cron و بروزرسانی خودکار اصلاحشان می‌کنند و `/health` هشدار می‌دهد |
| درگاه آزمایشی | در `APP_ENV=production` همیشه خاموش است، حتی اگر `PAYMENT_TEST_GATEWAY=true` باشد؛ پرداخت آزمایشی نیمه‌کاره هرگز Star نمی‌دهد |
| IP کاربر | `TRUSTED_PROXIES` با پشتیبانی CIDR؛ `X-Forwarded-For` از راست خوانده می‌شود تا قابل جعل نباشد |

## بازبینی پس از اصلاح توکن مشترک (1.19.14)

چون تا 1.19.13 همان HEALTH_TOKEN برای `/ops/*` هم کار می‌کرد و در سرویس مانیتورینگ هم بوده، یک‌بار این‌ها را بررسی کنید:

```sql
-- همه فراخوانی‌های /ops (مدیر کردن، شارژ Stars، migrate)
SELECT created_at, action, target_id, INET6_NTOA(ip) AS ip, meta
FROM audit_logs WHERE action LIKE 'ops.%' ORDER BY created_at DESC;

-- Super Adminها
SELECT u.id, u.email, ur.created_at FROM user_roles ur
JOIN roles r ON r.id = ur.role_id AND r.slug = 'super_admin' JOIN users u ON u.id = ur.user_id;

-- خریدهای موفق از درگاه آزمایشی
SELECT p.id, p.user_id, p.stars, p.bonus_stars, p.created_at FROM payments p
JOIN payment_gateways g ON g.id = p.gateway_id AND g.code = 'test' WHERE p.status = 4;
```
سپس هر دو توکن را عوض کنید: `php bin/console keys:generate --only=HEALTH_TOKEN,OPS_TOKEN` (یا دستی در .env) و HEALTH_TOKEN جدید را در سرویس مانیتورینگ بگذارید.
