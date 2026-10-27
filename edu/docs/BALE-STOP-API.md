# درخواست از سامانه my: توقف ارسال

`POST /api/edu/bale/broadcast/{ref}/cancel` (همان Bearer token)

- ارسال‌های هنوز در صف برای آن `ref` لغو شود؛ آنچه قبلاً ارسال شده دست نخورد.
- پاسخ: `{ "ok": true, "ref": "event-152", "sent": 120, "failed": 3, "cancelled": 2343, "finished": true }`
- اگر `ref` پیدا نشد `404`؛ اگر ارسال تمام شده بود `{ "ok": false, "error": "finished" }`.
- پس از توقف، `GET /broadcast/{ref}` باید `finished: true` برگرداند.
