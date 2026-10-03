# REST API نسخه ۱

- آدرس پایه: `https://edu.aradbranding.me/api/v1`
- احراز هویت: `Authorization: Bearer ae_...` — کلید از پنل «کلیدهای API» ساخته می‌شود (فقط یک‌بار نمایش؛ به صورت هش ذخیره می‌شود؛ قابل انقضا و ابطال).
- دسترسی هر کلید با Ability محدود می‌شود. محدودیت نرخ: ۳۰۰ درخواست در دقیقه برای هر کلید.
- پاسخ‌ها JSON: `{"ok":true,"data":...}` یا `{"ok":false,"status":401,"message":"..."}`

| متد و مسیر | Ability | توضیح |
|---|---|---|
| `GET /health` | — | وضعیت سرویس (عمومی) |
| `GET /courses` | courses.read | دوره‌های منتشرشده |
| `GET /users?mobile=&my_user_id=&id=&limit=&offset=` | users.read | جست‌وجوی کاربر |
| `POST /users` | users.write | ایجاد/به‌روزرسانی بر اساس `my_user_id` یا `mobile`؛ فیلدها: first_name, last_name, mobile, email, segment(merchant/employee/agent), my_user_id, job_title, group_ids[], org_unit_ids[] |
| `GET /users/{id}/progress` | progress.read | دوره‌ها، وضعیت، پیشرفت، نمره، مرحله رشد، گواهی‌ها |
| `POST /enrollments` | enrollments.write | `{"user_id":1,"course_id":2,"training_type":"mandatory","due_at":"2026-12-01"}` |
| `GET /reports/summary` | reports.read | آمار کلی |

نمونه:
```bash
curl -H "Authorization: Bearer ae_xxx" https://edu.aradbranding.me/api/v1/users?mobile=09121234567
curl -X POST -H "Authorization: Bearer ae_xxx" -H "Content-Type: application/json" \
     -d '{"mobile":"09121234567","first_name":"علی","last_name":"رضایی","segment":"employee","org_unit_ids":[4]}' \
     https://edu.aradbranding.me/api/v1/users
```
هر فراخوانی نوشتنی در Audit Log ثبت می‌شود. پس از ایجاد/تغییر کاربر، قوانین تخصیص خودکار و تخصیص‌های گروهی اعمال می‌شوند.
