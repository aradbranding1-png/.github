<?php
$cards = [
    ['/admin/reports/users', 'user', 'primary', 'گزارش کامل هر کاربر', 'جست‌وجوی کاربر و مشاهده ورودها، تقویم فعالیت، دوره‌ها، آزمون‌ها، تمرین‌ها و مرحله رشد', ['reports.report', 'users.report']],
    ['/admin/reports/groups', 'layers', 'info', 'گزارش گروهی', 'گروه، واحد، بخش، سمت، سطح و طبقه‌بندی — فعال/غیرفعال، میانگین پیشرفت، عقب‌مانده‌ها', ['reports.report']],
    ['/admin/reports/progress', 'gauge', 'success', 'گزارش پیشرفت و عملکرد', 'همه ثبت‌نام‌ها با فیلتر وضعیت، دوره، گروه و نوع آموزش', ['reports.report']],
    ['/admin/reports/courses', 'book-open', 'purple', 'گزارش دوره‌ها', 'فراگیران، نرخ تکمیل، میانگین پیشرفت و نمره هر دوره', ['reports.report', 'courses.report']],
    ['/admin/reports/exams', 'clipboard-check', 'warning', 'گزارش آزمون‌ها', 'شرکت‌کنندگان، میانگین نمره و نرخ قبولی', ['reports.report', 'exams.report']],
    ['/admin/reports/inactive', 'user-x', 'danger', 'افراد غیرفعال', 'کاربرانی که مدتی وارد نشده‌اند + ارسال یادآوری', ['reports.report']],
    ['/admin/reports/needs', 'lightbulb', 'warning', 'گزارش نیاز آموزشی', 'نیازهای باز، برنامه‌ریزی‌شده و رفع‌شده به تفکیک موضوع', ['reports.report']],
    ['/admin/reports/content', 'eye', 'info', 'استفاده از محتوا', 'پربازدیدترین درس‌ها و فایل‌ها، زمان مطالعه', ['reports.report']],
];
?>
<div class="page-head"><div><h1>گزارش‌ها</h1><div class="sub">گزارش‌های مدیریتی — همه گزارش‌های مهم قابلیت خروجی Excel دارند</div></div></div>
<div class="grid g-auto">
<?php foreach ($cards as [$href, $ic, $tone, $t, $d, $perms]): if (!can_any($perms)) continue; ?>
    <a class="card stat tone-<?= $tone ?>" href="<?= url($href) ?>" style="align-items:flex-start"><div class="bubble"><?= icon($ic) ?></div><div><div class="fw-b" style="color:var(--text)"><?= e($t) ?></div><div class="l"><?= e($d) ?></div></div></a>
<?php endforeach; ?>
</div>
