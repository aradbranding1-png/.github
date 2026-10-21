# ارسال خبر به ربات بله (سرویسی که my.aradbranding.me باید داشته باشد)

سامانه آموزش فهرست اعضای ربات بله را ندارد؛ فقط دو مسیر API را روی سامانه **my** صدا می‌زند و ارسال واقعی با ربات بلهٔ my انجام می‌شود.
اگر در پنل آموزش ← تنظیمات ← ربات بله خطای `HTTP 404` دیده شود، یعنی این دو مسیر هنوز روی my پیاده‌سازی نشده‌اند (یا «آدرس پایه API» اشتباه است).

## تنظیم در سامانه آموزش
- **آدرس پایه API سامانه my:** مثلاً `https://my.aradbranding.me` (بدون `/` انتهایی). مسیرهای زیر به همین آدرس اضافه می‌شوند.
- **توکن API:** یک رشته تصادفی (حداقل ۳۲ کاراکتر) که در my ساخته و ذخیره می‌شود و همان را در پنل آموزش وارد می‌کنید.

## احراز هویت
همه درخواست‌ها هدر `Authorization: Bearer <توکن>` و `Accept: application/json` دارند.
توکن غلط یا نبودِ هدر ← `401` با بدنه `{"ok":false,"error":"unauthorized"}`.
(در CGI/FastCGI مطمئن شوید هدر Authorization به PHP می‌رسد، مثلاً با `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.)

همه پاسخ‌ها JSON هستند و کلید `ok` دارند. در خطا: `{"ok":false,"error":"<توضیح کوتاه>"}` — متن `error` در پنل آموزش نمایش داده می‌شود.

## ۱) تعداد گیرندگان (دکمه «تست اتصال»)
`GET /api/edu/bale/recipients?segment=merchant`

| پارامتر | توضیح |
|---|---|
| `segment` | گروه گیرندگان؛ فعلاً همیشه `merchant` (تاجرانی که ربات بله را به حسابشان در my وصل کرده‌اند) |

پاسخ موفق:
```json
{"ok": true, "count": 128}
```

## ۲) ارسال یک خبر
`POST /api/edu/bale/broadcast` — `Content-Type: application/json`
```json
{
  "ref": "event-42",
  "segment": "merchant",
  "text": "📢 وبینار تجاری جدید: ...\n\n🗓 دوشنبه ۵ آبان — ساعت ۱۸:۰۰\n\n🔗 جزئیات و ثبت‌نام:\nhttps://edu.aradbranding.me/learn/event/42",
  "button": {"text": "مشاهده و ثبت‌نام", "url": "https://edu.aradbranding.me/learn/event/42"}
}
```

| فیلد | توضیح |
|---|---|
| `ref` | شناسه یکتای خبر: `event-<id>` یا `course-<id>`. **my باید بر اساس آن تکراری‌ها را رد کند**: اگر این ref قبلاً ارسال شده، دوباره نفرستد و همان پاسخ موفق (با `duplicate: true`) را برگرداند. سامانه آموزش ارسال ناموفق را تا ۳ بار تکرار می‌کند. |
| `segment` | گیرندگان (فعلاً `merchant`) |
| `text` | متن کامل پیام (چندخطی، با لینک) |
| `button` | اختیاری؛ دکمه شیشه‌ای (inline keyboard) با یک لینک |

پاسخ موفق (بعد از قرار گرفتن در صف ارسال یا ارسال کامل):
```json
{"ok": true, "recipients": 128}
```
`recipients` = تعداد کسانی که پیام برایشان ارسال (یا در صف) شد.

### پیشنهاد پیاده‌سازی در my
- برای فهرست‌های بزرگ، پیام‌ها را در صف بگذارید و با Cron/Worker با سرعت مجاز Bale ارسال کنید، و فوراً `ok:true` برگردانید (مهلت انتظار سامانه آموزش ۲۰ ثانیه است).
- ارسال با متد `sendMessage` در Bot API بله: `https://tapi.bale.ai/bot<TOKEN>/sendMessage` با `chat_id`، `text` و در صورت وجود دکمه `reply_markup = {"inline_keyboard":[[{"text":..., "url":...}]]}`.
- کاربری که ربات را بلاک کرده (خطای 403 از بله) را از فهرست گیرندگان غیرفعال کنید.

## بررسی سریع از خط فرمان
```bash
curl -H "Authorization: Bearer $TOKEN" "https://my.aradbranding.me/api/edu/bale/recipients?segment=merchant"
```
پاسخ باید `{"ok":true,"count":...}` باشد؛ `404` یعنی مسیر وجود ندارد.
