<?php
declare(strict_types=1);

namespace App\Core;

/** Persian labels for enum values. */
final class Labels
{
    public const STATUS = [
        // enrollment
        'not_started' => ['شروع نشده', 'gray'],
        'in_progress' => ['در حال انجام', 'primary'],
        'completed' => ['تکمیل شده', 'success'],
        'needs_retake' => ['نیازمند تکرار', 'warning'],
        'failed' => ['مردود', 'danger'],
        'locked' => ['قفل‌شده', 'dark'],
        'expired' => ['منقضی‌شده', 'danger'],
        // publish
        'draft' => ['پیش‌نویس', 'gray'],
        'published' => ['منتشر شده', 'success'],
        'archived' => ['بایگانی', 'dark'],
        // users
        'active' => ['فعال', 'success'],
        'inactive' => ['غیرفعال', 'danger'],
        'pending' => ['در انتظار تأیید', 'warning'],
        // exam attempts
        'submitted' => ['ارسال شده', 'info'],
        'graded' => ['نمره‌دهی شده', 'success'],
        'pending_review' => ['در انتظار تصحیح', 'warning'],
        'passed' => ['قبول', 'success'],
        // exercises
        'accepted' => ['پذیرفته شده', 'success'],
        'needs_revision' => ['نیازمند اصلاح', 'warning'],
        'rejected' => ['رد شده', 'danger'],
        // updates / migrations / backups
        'success' => ['موفق', 'success'],
        'fail' => ['ناموفق', 'danger'],
        'denied' => ['رد دسترسی', 'danger'],
        'running' => ['در حال اجرا', 'info'],
        'validated' => ['اعتبارسنجی شده', 'info'],
        'rolled_back' => ['بازگردانی شده', 'warning'],
        'uploaded' => ['آپلود شده', 'gray'],
        // needs
        'open' => ['باز', 'warning'],
        'planned' => ['برنامه‌ریزی شده', 'info'],
        'resolved' => ['رفع شده', 'success'],
        // health
        'ok' => ['OK', 'success'],
        'warning' => ['Warning', 'warning'],
        'error' => ['Error', 'danger'],
    ];

    public const TRAINING_TYPE = [
        'mandatory' => 'اجباری',
        'optional' => 'اختیاری',
        'supplementary' => 'تکمیلی',
        'suggested' => 'پیشنهادی',
    ];

    public const SEGMENT = [
        'merchant' => 'تاجران',
        'employee' => 'کارمندان',
        'agent' => 'نمایندگان',
        'custom' => 'سایر',
    ];

    public const SEGMENT_ONE = [
        'merchant' => 'تاجر',
        'employee' => 'کارمند',
        'agent' => 'نماینده',
        'custom' => 'سایر',
    ];

    public const QTYPE = [
        'single' => 'چهارگزینه‌ای (تک پاسخ)',
        'multiple' => 'چندگزینه‌ای (چند پاسخ)',
        'truefalse' => 'درست / غلط',
        'short' => 'پاسخ کوتاه',
        'essay' => 'تشریحی',
    ];

    public const CONTENT_TYPE = [
        'text' => 'متن',
        'video' => 'ویدیو',
        'audio' => 'صوت',
        'image' => 'تصویر',
        'pdf' => 'PDF',
        'file' => 'فایل',
        'link' => 'لینک',
    ];

    public const SCOPE = [
        'all' => 'همه کاربران',
        'supervised' => 'فقط افراد تحت مسئولیت',
        'own' => 'فقط موارد خودش',
    ];

    public const DIFFICULTY = [1 => 'آسان', 2 => 'متوسط', 3 => 'دشوار'];

    public const TARGET_TYPE = [
        'user' => 'شخص',
        'group' => 'گروه',
        'org_unit' => 'واحد سازمانی',
        'role' => 'نقش',
        'level' => 'سطح',
        'term' => 'طبقه‌بندی',
    ];

    public const PRIORITY = ['low' => 'کم', 'medium' => 'متوسط', 'high' => 'زیاد'];

    public const AVATAR_SOURCE = ['my' => 'my.aradbranding.me', 'edu' => 'آپلود در سامانه آموزش', 'none' => 'بدون تصویر'];

    public const AUDIT = [
        'auth.login' => 'ورود',
        'auth.logout' => 'خروج',
        'auth.login_failed' => 'ورود ناموفق',
        'auth.password_forced_change' => 'تعیین رمز جدید (رمز موقت)',
        'arad_contact.provision' => 'آراد کانتکت — ساخت حساب / شارژ خدمات',
        'arad_contact.settings' => 'آراد کانتکت — تغییر تنظیمات',
        'arad_contact.token_set' => 'آراد کانتکت — ثبت توکن',
        'arad_contact.token_clear' => 'آراد کانتکت — حذف توکن',
        'roles.grant' => 'شارژ اعتبار نقش',
        'paths.reorder' => 'تغییر ترتیب مسیرهای آموزشی',
        'paths.reorder_steps' => 'تغییر ترتیب مراحل مسیر',
        'roles.grant_bulk' => 'شارژ گروهی اعتبار نقش',
        'roles.grant_settings' => 'تنظیم شارژ گروهی نقش‌ها',
        'access.denied' => 'رد دسترسی',
        'impersonate.start' => 'شروع ورود به حساب کاربر',
        'impersonate.stop' => 'پایان ورود به حساب کاربر',
    ];
}
