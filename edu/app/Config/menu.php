<?php
/*
 * منوی کناری. هر آیتم فقط زمانی نمایش داده می‌شود که کاربر مجوز لازم را داشته باشد.
 * perm: رشته یا آرایه (هرکدام کافی است) | root: فقط مدیر کل | null: همه کاربران واردشده
 */
return [
    ['section' => 'یادگیری من', 'items' => [
        ['/', 'پیشخوان من', 'house', null],
        ['/learn', 'دوره‌های من', 'graduation-cap', null],
        ['/learn/catalog', 'کاتالوگ دوره‌ها', 'layout-grid', null],
        ['/learn/paths', 'مسیرهای آموزشی', 'route', null],
        ['/learn/growth', 'نظام رشد تاجر من', 'trending-up', null],
        ['/learn/exams', 'آزمون‌های من', 'clipboard-check', null],
        ['/learn/exercises', 'تمرین‌های من', 'notebook-pen', null],
        ['/learn/certificates', 'گواهی‌های من', 'award', null],
        ['/me/report', 'گزارش فعالیت من', 'activity', null],
    ]],
    ['section' => 'جلسات آنلاین', 'items' => [
        ['/me/services', 'خدمات و جلسات من', 'bookmark', null],
        ['/learn/events/webinar', 'وبینارهای تجاری', 'video', null],
        ['/learn/events/workshop', 'کارگاه‌های تجاری آنلاین', 'briefcase', null],
        ['/learn/events/meeting', 'میتینگ‌های آنلاین', 'users', null],
    ]],
    ['section' => 'پیشخوان مدیریت', 'items' => [
        ['/admin', 'داشبورد مدیریتی', 'layout-dashboard', 'dashboard.view'],
        ['/admin/team', 'تیم تحت مسئولیت من', 'users', 'team.view'],
    ]],
    ['section' => 'کاربران و دسترسی', 'items' => [
        ['/admin/users', 'کاربران', 'users', 'users.view', 'users_pending'],
        ['/admin/roles', 'نقش‌ها و سطوح دسترسی', 'shield-check', 'roles.view'],
        ['/admin/role-grants', 'شارژ گروهی نقش‌ها', 'zap', ['role_grants.view', 'grant_employee.run', 'grant_agent.run']],
        ['/admin/api-tokens', 'کلیدهای API', 'key-round', 'api_tokens.view'],
    ]],
    ['section' => 'ساختار', 'items' => [
        ['/admin/groups', 'گروه‌ها', 'layers', 'groups.view'],
        ['/admin/org', 'ساختار سازمانی', 'network', 'org.view'],
        ['/admin/levels', 'سطح‌بندی و طبقه‌بندی', 'signal', 'levels.view'],
    ]],
    ['section' => 'محتوای آموزشی', 'items' => [
        ['/admin/categories', 'موضوعات آموزشی', 'folder-tree', 'categories.view'],
        ['/admin/courses', 'دوره‌ها و درس‌ها', 'book-open', ['courses.view', 'lessons.view']],
        ['/admin/library', 'کتابخانه محتوا', 'library', 'library.view'],
        ['/admin/paths', 'مسیرهای آموزشی', 'route', 'paths.view'],
    ]],
    ['section' => 'آزمون و ارزیابی', 'items' => [
        ['/admin/exams', 'آزمون‌ها', 'clipboard-check', 'exams.view'],
        ['/admin/questions', 'بانک سؤال', 'circle-help', 'questions.view'],
        ['/admin/reviews', 'بررسی و تصحیح', 'list-checks', 'reviews.view', 'reviews'],
        ['/admin/evaluations', 'ارزیابی عملی', 'clipboard-list', 'evaluations.view'],
        ['/admin/certificates', 'گواهی‌ها', 'medal', 'certificates.view'],
    ]],
    ['section' => 'مدیریت جلسات آنلاین', 'items' => [
        ['/admin/events/webinar', 'مدیریت وبینارها', 'video', 'events.view'],
        ['/admin/events/workshop', 'مدیریت کارگاه‌ها', 'briefcase', 'events.view'],
        ['/admin/events/meeting', 'مدیریت میتینگ‌ها', 'users', 'events.view'],
    ]],
    ['section' => 'تخصیص و رشد', 'items' => [
        ['/admin/assignments', 'تخصیص آموزش', 'send', 'assignments.view'],
        ['/admin/rules', 'تخصیص خودکار (قوانین)', 'wand-sparkles', 'rules.view'],
        ['/admin/growth', 'نظام رشد تاجر', 'mountain', 'growth.view'],
        ['/admin/growth/traders', 'جایگاه تاجران', 'trophy', 'growth.view'],
        ['/admin/growth/reviews', 'بررسی معاملات و مدارک', 'file-check', ['growth.approve', 'growth.view'], 'growth_reviews'],
        ['/admin/growth/services', 'خدمات و سامانه فروش', 'package', 'growth.view'],
        ['/admin/needs', 'نیازسنجی آموزشی', 'lightbulb', 'needs.view'],
    ]],
    ['section' => 'گزارش و پایش', 'items' => [
        ['/admin/reports', 'گزارش‌ها', 'chart-column', 'reports.view'],
        ['/admin/notifications', 'ارسال اعلان', 'bell', 'notifications.view'],
        ['/admin/audit', 'Audit Log', 'scroll-text', 'audit.view'],
    ]],
    ['section' => 'سیستم', 'items' => [
        ['/admin/settings', 'تنظیمات', 'settings', 'settings.view'],
        ['/admin/sso', 'اتصال SSO', 'fingerprint', 'sso.view'],
        ['/admin/integrations/arad-contact', 'اتصال آراد کانتکت', 'handshake', 'settings.view'],
        ['/admin/system/health', 'سلامت سامانه', 'heart-pulse', 'health.view'],
        ['/admin/system/backups', 'پشتیبان‌گیری', 'hard-drive', 'backups.view'],
        ['/admin/system/migrations', 'Migrationها', 'database', 'migrations.view'],
        ['/admin/system/updates', 'بروزرسانی سامانه', 'refresh-cw', 'updates.view'],
        ['/admin/system/errors', 'خطاهای سامانه', 'triangle-alert', 'errors.view'],
    ]],
];
