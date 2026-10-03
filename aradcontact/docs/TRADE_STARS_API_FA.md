# API لازم در aradbranding.app برای اتصال به «آراد کانتکت» (شارژ استارز)

این متن را به سازنده‌ی aradbranding.app بدهید. آراد کانتکت دقیقاً همین چهار درخواست را می‌فرستد.

## احراز هویت

- همه‌ی درخواست‌ها هدر `Authorization: Bearer <کلید>` دارند.
- کلید را ادمین در تنظیمات aradbranding.app می‌سازد.
- درخواستِ بدون کلیدِ درست با کد 401 رد شود.
- همه‌ی پاسخ‌ها JSON باشند. در خطا کدِ غیر 2xx برگردد، یا پاسخ `{"success": false, "message": "دلیل"}` باشد.

## ۱) جست‌وجوی حساب با همه‌ی شماره‌های مشتری

`POST /api/integrations/arad-contact/users/lookup`

```json
{"phones": ["09121234567", "09351234567"]}
```

همه‌ی شماره‌ها بعد از یکسان‌سازی (ارقامِ انگلیسی، شروع با ۰۹) جست‌وجو شوند.

پاسخ اگر حساب وجود دارد:

```json
{"found": true, "user_id": 123, "phone": "09121234567"}
```

پاسخ اگر حساب وجود ندارد:

```json
{"found": false}
```

## ۲) ساختِ حساب

`POST /api/integrations/arad-contact/users`

```json
{"full_name": "نام مشتری", "phone": "09121234567", "external_id": "arad-contact-customer-456"}
```

پاسخ:

```json
{"user_id": 124, "username": "09121234567", "password": "رمز موقت", "login_url": "https://aradbranding.app/login"}
```

- اگر همان `external_id` یا همان شماره دوباره آمد، حسابِ تکراری ساخته نشود و همان حساب برگردد.
- در آن حالت فیلدِ `password` لازم نیست.

## ۳) نرخِ فعلیِ استارز

`GET /api/integrations/arad-contact/stars/rate`

```json
{"toman_per_star": 1000}
```

نرخ از تنظیماتِ کیف پولِ استارزِ خودِ سامانه خوانده شود.

## ۴) شارژِ کیف پولِ استارز

`POST /api/integrations/arad-contact/stars/credit`

```json
{"user_id": 124, "amount_toman": 13333333, "external_id": "arad-contact-stars-item-37-to-13333333", "note": "سامانه توسعه تجارت — فاکتور 1405-0123"}
```

پاسخ:

```json
{"stars": 13333.33, "toman_per_star": 1000, "balance": 15000.5, "transaction_id": "tx-789"}
```

- تعدادِ استارز را خودِ سامانه با نرخِ همان لحظه حساب کند: `stars = amount_toman / toman_per_star`.
- شارژ در تاریخچه‌ی کیف پولِ کاربر با توضیح (`note`) ثبت شود.
- **مهم:** اگر همان `external_id` دوباره آمد، دوباره شارژ نشود و همان نتیجه‌ی قبلی برگردد. آراد کانتکت در خطا یا قطعیِ اینترنت با همان شناسه دوباره تلاش می‌کند.
