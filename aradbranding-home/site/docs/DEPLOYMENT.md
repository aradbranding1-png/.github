# راه‌اندازی روی DirectAdmin

## پیش‌نیازها
| مورد | مقدار |
| --- | --- |
| PHP | 8.3 یا 8.4 با OPcache |
| Extensions | pdo_mysql, mbstring, openssl, json, fileinfo, sodium, curl, zip, intl, gd |
| دیتابیس | MySQL 8.0+ یا MariaDB 10.6+ (Collation: utf8mb4_unicode_520_ci) |
| SSL | الزامی (دامنه .app فقط با HTTPS باز می‌شود) |

## ساختار
```
/home/USER/domains/aradbranding.app/
├── public_html/   ← Document Root (index.php, assets, media, icons, manifest, service-worker)
├── app/ bootstrap/ config/ routes/ database/ bin/
├── storage/ backups/        ← خارج از دسترس وب
└── .env                     ← خارج از دسترس وب
```

## نصب اولیه
1. دامنه و SSL (Let's Encrypt) را در DirectAdmin فعال کنید؛ PHP را روی 8.3 بگذارید.
2. دیتابیس و کاربر بسازید.
3. ZIP را در `domains/aradbranding.app/` آپلود و Extract کنید.
4. `https://aradbranding.app/install` را باز کنید؛ کلید نصب در `storage/install.key` است.
5. عضو شوید و نقش مدیر کل بگیرید:
   `curl -X POST -H "X-Ops-Token: <OPS_TOKEN>" -d "email=..." https://aradbranding.app/ops/promote-admin`

## Cron (هر کدام جداگانه)
| زمان | دستور |
| --- | --- |
| `* * * * *` | `/usr/local/php83/bin/php /home/USER/domains/aradbranding.app/bin/console queue:work --max-time=55 >/dev/null 2>&1` |
| `*/15 * * * *` | `/usr/local/php83/bin/php /home/USER/domains/aradbranding.app/cron.php >/dev/null 2>&1` |

Cron پانزده‌دقیقه‌ای این کارها را زمان‌بندی می‌کند: پاک‌سازی، Partitionهای ماه آینده، تطبیق پرداخت‌ها، انقضای پیشنهادها، آمار ساعتی، sitemap روزانه، پشتیبان خودکار (اگر روشن باشد).

## بررسی سلامت
`curl -H "X-Health-Token: <HEALTH_TOKEN>" https://aradbranding.app/health`

## پیش از راه‌اندازی عمومی
- `PAYMENT_TEST_GATEWAY=false` و `ZARINPAL_MERCHANT_ID` تنظیم شود.
- OPcache فعال باشد (در Health سبز).
- فونت `public_html/assets/fonts/Vazirmatn-Variable.woff2` اضافه شود.
- رکوردهای SPF، DKIM و DMARC برای ایمیل‌های آینده تنظیم شود.
