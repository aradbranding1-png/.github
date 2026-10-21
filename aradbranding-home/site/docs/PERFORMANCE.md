# کارایی و تست بار

## اصول پیاده‌شده
- همه فهرست‌ها با Keyset/Cursor (بدون OFFSET در مسیرهای کاربر).
- Feed از جدول باریک `proposal_feed`؛ صندوق نامه از `thread_participants`؛ شمارنده‌ها از `user_counters`.
- داشبوردها از `daily_metrics` (محاسبه ساعتی در Cron)؛ هیچ COUNT روی جدول بزرگ هنگام درخواست.
- جدول‌های پرحجم Partition ماهانه دارند: پیام‌ها، اعلان‌ها، بازدیدها، رویدادها، لاگ‌ها.
- Cache با نسخه‌گذاری؛ صفحه اصلی و Teaser صفحه‌ها Cache عمومی (آماده CDN).
- هدر `Server-Timing` در هر پاسخ؛ Slow Query Log بالای `SLOW_QUERY_MS`؛ بودجه Query در هر درخواست.

## تست بار (روی سرور Staging، نه Production)
1. در `.env` سرور تست: `APP_ENV=staging`.
2. داده نمونه: `php bin/console seed:demo --users=1000000` (حساب‌ها: demoN@example.test / demo-password).
3. با یک حساب نمونه وارد شوید و کوکی Session را بردارید.
4. اجرای سناریوها با [k6](https://k6.io):
```
BASE=https://staging.example COOKIE="__Host-sadt=..." k6 run tests/load/feed.js
BASE=https://staging.example COOKIE="__Host-sadt=..." HANDLES=demo10,demo20,demo30 k6 run tests/load/core.js
```

## معیار قبولی
| مسیر | P95 |
| --- | --- |
| Feed | < 250ms |
| صفحه عمومی (Teaser) | < 200ms |
| صندوق نامه | < 250ms |
| داشبورد | < 250ms |
| خطا | < 0.1٪ |

پس از هر اجرا: Slow Query Log را بررسی کنید (`storage/logs/slow-query-*.log`).
