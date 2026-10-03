<?php
/*
 * رجیستری مجوزها (Permission Registry)
 * هر ماژول = یک بخش/صفحه از سامانه. هر اکشن = یک سطح دسترسی مستقل.
 * کلید مجوز: module.action   (مثال: courses.publish)
 * این فهرست هنگام نصب/بروزرسانی با جدول permissions همگام می‌شود؛ برچسب‌ها از پنل قابل تغییرند.
 * مجوزهای root_only فقط برای مدیر کل هستند و به هیچ نقشی قابل اعطا نیستند.
 */
return [
    'actions' => [
        'view' => 'مشاهده',
        'create' => 'ایجاد',
        'edit' => 'ویرایش',
        'delete' => 'حذف',
        'assign' => 'تخصیص',
        'publish' => 'انتشار',
        'approve' => 'تأیید / بررسی',
        'report' => 'گزارش',
        'export' => 'خروجی Excel',
        'run' => 'اجرا',
        'restore' => 'بازیابی',
        'download' => 'دانلود',
    ],

    'sections' => [
        'overview' => ['label' => 'پیشخوان', 'icon' => 'layout-dashboard'],
        'people' => ['label' => 'کاربران و دسترسی', 'icon' => 'users'],
        'structure' => ['label' => 'ساختار سازمانی و گروه‌ها', 'icon' => 'network'],
        'content' => ['label' => 'محتوای آموزشی', 'icon' => 'book-open'],
        'assessment' => ['label' => 'آزمون و ارزیابی', 'icon' => 'clipboard-check'],
        'growth' => ['label' => 'تخصیص و نظام رشد', 'icon' => 'trending-up'],
        'sessions' => ['label' => 'جلسات آنلاین', 'icon' => 'video'],
        'reports' => ['label' => 'گزارش‌ها و پایش', 'icon' => 'chart-column'],
        'system' => ['label' => 'سیستم', 'icon' => 'settings'],
    ],

    'modules' => [
        'dashboard'     => ['label' => 'داشبورد مدیریتی', 'section' => 'overview', 'actions' => ['view']],
        'team'          => ['label' => 'تیم تحت مسئولیت (مسئول آموزش)', 'section' => 'overview', 'actions' => ['view', 'report']],

        'users'         => ['label' => 'کاربران', 'section' => 'people', 'actions' => ['view', 'create', 'edit', 'delete', 'assign', 'approve', 'report', 'export']],
        'credits'       => ['label' => 'اعتبار زمانی کاربران (دقیقه)', 'section' => 'people', 'actions' => ['view', 'edit']],
        'roles'         => ['label' => 'نقش‌ها و سطوح دسترسی', 'section' => 'people', 'actions' => ['view', 'create', 'edit', 'delete', 'assign']],
        'api_tokens'    => ['label' => 'کلیدهای API', 'section' => 'people', 'actions' => ['view', 'create', 'delete']],
        'role_grants'   => ['label' => 'شارژ گروهی نقش‌ها (مشاهده و تنظیم مقادیر)', 'section' => 'people', 'actions' => ['view', 'edit']],
        'grant_employee' => ['label' => 'دکمه شارژ گروهی کارمندان فراگیر', 'section' => 'people', 'actions' => ['run']],
        'grant_agent'   => ['label' => 'دکمه شارژ گروهی نمایندگان', 'section' => 'people', 'actions' => ['run']],

        'groups'        => ['label' => 'گروه‌ها (تاجران، کارمندان، نمایندگان)', 'section' => 'structure', 'actions' => ['view', 'create', 'edit', 'delete', 'assign']],
        'org'           => ['label' => 'ساختار سازمانی (معاونت، واحد، بخش، سمت)', 'section' => 'structure', 'actions' => ['view', 'create', 'edit', 'delete', 'assign']],
        'levels'        => ['label' => 'سطح‌بندی و طبقه‌بندی‌ها', 'section' => 'structure', 'actions' => ['view', 'create', 'edit', 'delete']],

        'categories'    => ['label' => 'موضوعات آموزشی', 'section' => 'content', 'actions' => ['view', 'create', 'edit', 'delete']],
        'courses'       => ['label' => 'دوره‌ها', 'section' => 'content', 'actions' => ['view', 'create', 'edit', 'delete', 'publish', 'report', 'export']],
        'lessons'       => ['label' => 'درس‌ها', 'section' => 'content', 'actions' => ['view', 'create', 'edit', 'delete', 'publish']],
        'library'       => ['label' => 'کتابخانه محتوا و فایل‌ها', 'section' => 'content', 'actions' => ['view', 'create', 'edit', 'delete', 'download']],
        'paths'         => ['label' => 'مسیرهای آموزشی', 'section' => 'content', 'actions' => ['view', 'create', 'edit', 'delete', 'publish', 'assign']],

        'exams'         => ['label' => 'آزمون‌ها', 'section' => 'assessment', 'actions' => ['view', 'create', 'edit', 'delete', 'publish', 'report', 'export']],
        'questions'     => ['label' => 'بانک سؤال', 'section' => 'assessment', 'actions' => ['view', 'create', 'edit', 'delete']],
        'reviews'       => ['label' => 'بررسی تمرین‌ها و پاسخ‌های تشریحی', 'section' => 'assessment', 'actions' => ['view', 'approve']],
        'evaluations'   => ['label' => 'ارزیابی عملی', 'section' => 'assessment', 'actions' => ['view', 'create', 'delete']],
        'certificates'  => ['label' => 'گواهی‌ها', 'section' => 'assessment', 'actions' => ['view', 'create', 'delete', 'export']],

        'events'        => ['label' => 'جلسات آنلاین (وبینار، کارگاه، میتینگ)', 'section' => 'sessions', 'actions' => ['view', 'create', 'edit', 'delete', 'report']],

        'assignments'   => ['label' => 'تخصیص آموزش', 'section' => 'growth', 'actions' => ['view', 'assign', 'delete']],
        'rules'         => ['label' => 'قوانین تخصیص خودکار', 'section' => 'growth', 'actions' => ['view', 'create', 'edit', 'delete', 'run']],
        'growth'        => ['label' => 'نظام رشد تاجر (مراحل، بررسی مدارک، خدمات)', 'section' => 'growth', 'actions' => ['view', 'create', 'edit', 'delete', 'approve', 'run', 'export']],
        'needs'         => ['label' => 'نیازسنجی آموزشی', 'section' => 'growth', 'actions' => ['view', 'create', 'edit', 'delete']],

        'reports'       => ['label' => 'گزارش‌های مدیریتی', 'section' => 'reports', 'actions' => ['view', 'report', 'export']],
        'notifications' => ['label' => 'اعلان‌ها (ارسال همگانی)', 'section' => 'reports', 'actions' => ['view', 'create']],
        'audit'         => ['label' => 'Audit Log (رویدادنگاری)', 'section' => 'reports', 'actions' => ['view', 'export']],

        'settings'      => ['label' => 'تنظیمات سامانه', 'section' => 'system', 'actions' => ['view', 'edit']],
        'sso'           => ['label' => 'اتصال SSO به my.aradbranding.me', 'section' => 'system', 'actions' => ['view', 'edit']],
        'health'        => ['label' => 'سلامت سامانه', 'section' => 'system', 'actions' => ['view']],
        'backups'       => ['label' => 'پشتیبان‌گیری', 'section' => 'system', 'actions' => ['view', 'create', 'delete', 'download', 'restore']],
        'errors'        => ['label' => 'خطاهای سامانه', 'section' => 'system', 'actions' => ['view']],
        'migrations'    => ['label' => 'مدیریت Migration', 'section' => 'system', 'actions' => ['view', 'run']],
        'updates'       => ['label' => 'بروزرسانی سامانه', 'section' => 'system', 'actions' => ['view', 'run']],
        'impersonate'   => ['label' => 'ورود به حساب کاربر', 'section' => 'system', 'actions' => ['run']],
    ],

    // فقط مدیر کل — این مجوزها به هیچ نقش یا کاربری قابل اعطا نیستند
    'root_only' => [
        'updates.view', 'updates.run',
        'migrations.run',
        'impersonate.run',
        'backups.restore', 'backups.download',
    ],
];
