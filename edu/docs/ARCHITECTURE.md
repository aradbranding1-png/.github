# معماری سامانه

## انتخاب تکنولوژی
PHP 8 خالص با معماری MVC سبک، بدون وابستگی خارجی — مناسب هاست DirectAdmin بدون SSH/Composer/Node. همه دارایی‌ها (فونت وزیرمتن، آیکون‌های Lucide، نمودارها) محلی هستند؛ هیچ CDN خارجی لازم نیست (پایداری در شبکه داخلی ایران).

## ساختار پوشه‌ها
```
app/
  bootstrap.php        بارگذاری env، autoload، مدیریت خطا
  routes.php           همه مسیرها با الزام auth/perm/root
  helpers.php
  Config/              permissions.php (رجیستری مجوزها)، menu.php، icons.php
  Core/                Kernel, Router, Request, DB(PDO), Auth, Gate(RBAC), Csrf, Session, Validator,
                       Upload, Audit, Activity, Notify, Settings, Jalali, Xlsx, Migrator, Logger, ErrorHandler, HtmlSanitizer, RateLimiter
  Services/            Enrollment, PathService, Growth, Targeting(تخصیص + Rule Engine), Scope(محدوده داده),
                       Recommend, ExamService, UserService(ادغام حساب)، UserReport, SsoClient, Backup, Updater, Health, Cron
  Controllers/         Auth, Install, Dashboard, Learn, Exam, Profile, File, Notification
  Controllers/Admin/   Dashboard, User, Role, Structure, Course, Library, Path, Exam, Question, Review, Assignment, Report, System, Maintenance
  Controllers/Api/     V1Controller
  Views/               قالب‌های PHP (layouts, partials, learn, admin/*)
database/migrations/   Migrationهای شماره‌دار
public_html/           index.php (تنها ورودی)، .htaccess، assets/
storage/               uploads, avatars, backups, logs, sessions, cache, updates, tmp
```

## دیتابیس (جداول اصلی)
- **کاربران و دسترسی:** users, roles, permissions, role_permissions, user_roles, user_permissions, login_history, impersonation_logs, api_tokens, account_merges
- **ساختار:** groups (+زیرگروه، مسئول)، group_members، levels، user_levels، org_unit_types، org_units (درختی، مدیر)، user_org_units، taxonomies، taxonomy_terms، user_terms
- **محتوا:** categories، files (کتابخانه با مجوز مشاهده/دانلود)، courses، course_prerequisites، lessons، lesson_files، learning_paths، path_steps
- **یادگیری:** enrollments، lesson_progress، path_enrollments، assignments، assignment_rules، growth_stages، user_growth، growth_history، training_needs
- **ارزیابی:** question_categories، questions، question_options، exams، exam_questions، exam_attempts، attempt_answers، exercises، exercise_submissions، practical_evaluations، certificates
- **پایش:** activity_logs، user_daily_activity (تجمیع روزانه برای تقویم و آمار)، audit_logs، notifications
- **سیستم:** settings، migrations، system_updates، backups، rate_limits

حذف دوره/درس/فایل/سؤال/کاربر به صورت **حذف نرم** (`deleted_at`) است؛ سوابق آموزشی هرگز حذف نمی‌شوند.

## منطق آموزشی
- **پیشرفت دوره** = (درس‌های تکمیل‌شده + آزمون‌های الزامی قبول‌شده + تمرین‌های الزامی پذیرفته‌شده) ÷ کل.
- **وضعیت‌ها:** شروع نشده، در حال انجام، تکمیل شده، نیازمند تکرار (مردود با فرصت باقی/تمرین نیازمند اصلاح)، مردود (اتمام دفعات)، قفل‌شده (پیش‌نیاز/مرحله قبلی مسیر)، منقضی‌شده (مهلت یا انقضای دوره).
- **ادامه آموزش:** آخرین درس و موقعیت ویدیو ذخیره می‌شود (`last_lesson_id`, `position_sec`).
- **تکمیل ویدیو/صوت:** پس از مشاهده درصد تعیین‌شده (پیش‌فرض ۹۰٪).
- **مسیر آموزشی:** ثبت‌نام در همه مراحل؛ مراحل بعد تا احراز شرط عبور مرحله قبل (پیشرفت، نمره، تمرین، ارزیابی عملی) قفل است.
- **نظام رشد:** مراحل مستقل برای هر گروه با شرط‌های دوره‌های الزامی، میانگین پیشرفت، میانگین نمره، تعداد تمرین، ارزیابی عملی؛ ارتقای خودکار (بدون تنزل) یا دستی.
- **تخصیص:** به شخص/چند شخص/گروه (با زیرگروه‌ها)/واحد-بخش-سمت (با زیرمجموعه‌ها)/نقش/سطح/طبقه‌بندی؛ اعضای جدید هدف به صورت خودکار دریافت می‌کنند.
- **Rule Engine:** شرط‌های «و/یا» روی نوع کاربر، گروه، واحد سازمانی، نقش، سطح، طبقه‌بندی — اجرا هنگام تغییر کاربر، دستی و شبانه.
- **پیشنهاد آموزش:** بر اساس نیازسنجی، دوره‌های لازم مرحله بعد رشد، گروه، سطح، نوع کاربر و سوابق.

## گزارش‌گیری
هر رویداد در `activity_logs` ثبت و در `user_daily_activity` تجمیع می‌شود (ورود، مشاهده/تکمیل درس، تکمیل دوره، آزمون، تمرین، زمان مطالعه با heartbeat). تقویم فعالیت روزانه/ماهانه/سالانه شمسی، روزهای فعال/غیرفعال و آمار دوره‌ای از همین جدول ساخته می‌شود.
