<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  سیستمِ مرکزیِ نقش‌ها و مجوزها
 * ═══════════════════════════════════════════════════════════════════════
 *
 * این فایل «تنها منبعِ حقیقت» برای همه‌ی مجوزهای سامانه است:
 *   - فهرستِ مجوزها (گروه‌بندی‌شده) + توضیح + صفحاتی که هر مجوز کنترل می‌کند
 *   - نقش‌های سیستمی و مجوزهای پیش‌فرضِ هر نقش
 *   - نگاشتِ «صفحه ← مجوز لازم» که به‌صورتِ خودکار در require_login() اعمال می‌شود
 *   - همگام‌سازیِ یک‌باره‌ی جدولِ access_roles با نسخه‌ی جدیدِ مجوزها
 *
 * هر صفحه‌ای که در perm_page_map() آمده باشد، بدونِ نیاز به هیچ کدِ اضافه‌ای در خودِ صفحه،
 * فقط برای کاربرانی باز می‌شود که (از طریقِ نقشِ اصلی، نقشِ ویژه یا نقشِ تکمیلی) آن مجوز را دارند.
 * ادمین کل همیشه به همه‌چیز دسترسی دارد.
 */

if (!defined('PERM_BASELINE_VERSION')) {
    define('PERM_BASELINE_VERSION', 5);
}

/* ------------------------------------------------------------------ */
/*  فهرستِ مجوزها                                                      */
/* ------------------------------------------------------------------ */

if (!function_exists('perm_catalog')) {
    /**
     * هر گروه: title, icon, desc, admin_panel(bool), items
     * هر آیتم: label, desc, pages(array of basename), sensitive(bool)
     */
    function perm_catalog(): array
    {
        static $cat = null;
        if ($cat !== null) {
            return $cat;
        }
        $cat = [
            'general' => [
                'title' => 'عمومی',
                'icon'  => 'fa-house',
                'desc'  => 'صفحاتِ پایه‌ای که تقریباً همه‌ی نقش‌ها لازم دارند.',
                'items' => [
                    'dashboard_view' => ['label' => 'داشبورد', 'desc' => 'مشاهده‌ی داشبورد و خلاصه‌ی عملکرد روزانه.', 'pages' => ['dashboard.php']],
                    'help_view'      => ['label' => 'راهنما', 'desc' => 'مشاهده‌ی راهنمای سامانه و راهنمای وضعیت‌ها.', 'pages' => ['help.php', 'status_guide.php']],
                    'chat_view'      => ['label' => 'گفتگو', 'desc' => 'استفاده از گفتگوی داخلی بین همکاران.', 'pages' => ['chat.php']],
                ],
            ],
            'customers' => [
                'title' => 'مشتریان و پیگیری',
                'icon'  => 'fa-list-check',
                'desc'  => 'دسترسی به پرونده‌ی مشتریان، ثبت و ویرایش، ارجاع و پیش‌فاکتور.',
                'items' => [
                    'customer_list_view'      => ['label' => 'پیگیری مشتریان (لیست)', 'desc' => 'مشاهده‌ی فهرستِ مشتریان و سررسیدهای پیگیری.', 'pages' => ['customer_list.php']],
                    'customer_view'           => ['label' => 'پرونده‌ی مشتری', 'desc' => 'باز کردنِ جزئیاتِ هر مشتری و ثبتِ پیگیری.', 'pages' => ['customer_view.php']],
                    'customer_new_view'       => ['label' => 'ثبت مشتری جدید', 'desc' => 'افزودنِ مشتریِ جدید به سامانه.', 'pages' => ['customer_new.php']],
                    'customer_edit'           => ['label' => 'ویرایش اطلاعات مشتری', 'desc' => 'ویرایشِ نام، شماره و سایر مشخصاتِ مشتری.', 'pages' => ['customer_edit.php']],
                    'customer_merge'          => ['label' => 'ادغام مشتریان تکراری', 'desc' => 'ادغامِ دو پرونده‌ی تکراری.', 'pages' => ['customer_merge.php']],
                    'customer_refer'          => ['label' => 'ارجاع مشتری به همکار', 'desc' => 'ارجاعِ مشتری به کارشناسِ دیگر.', 'pages' => ['customer_refer.php']],
                    'customer_referrals_view' => ['label' => 'ارجاع‌های دریافتی', 'desc' => 'مشاهده‌ی مشتریانی که به شما ارجاع شده‌اند.', 'pages' => ['customer_referrals.php']],
                    'phone_history_view'      => ['label' => 'بررسیِ سابقه (سابقه‌ی یک شماره)', 'desc' => 'دکمه‌ی «بررسیِ سابقه» در لیستِ مشتریان: جستجوی سابقه‌ی یک شماره در کلِ سامانه (پروفایل ۳۶۰).', 'pages' => ['phone_history.php']],
                    'service_requests_view'   => ['label' => 'درخواست‌های خدمات', 'desc' => 'مشاهده و پیگیریِ درخواست‌های خدمات.', 'pages' => ['service_requests.php']],
                    'service_requests_import' => ['label' => 'ورود اکسل درخواست خدمات', 'desc' => 'بارگذاریِ اکسلِ درخواست‌های خدمات.', 'pages' => ['service_requests_import.php']],
                    'service_leads_view'      => ['label' => 'لیدهای خدمات (واحد C)', 'desc' => 'مشاهده‌ی لیدهای خدماتِ اختصاص‌یافته.', 'pages' => ['service_leads.php']],
                    'quotes_manage'           => ['label' => 'پیش‌فاکتور', 'desc' => 'ساخت، ویرایش، قفل و چاپِ پیش‌فاکتور برای مشتریانِ خود.', 'pages' => ['quote_edit.php']],
                    'customer_profile_view'   => ['label' => 'پروفایل ۳۶۰ مشتری', 'desc' => 'مشاهده‌ی پروفایلِ یکپارچه‌ی مشتری.', 'pages' => ['customer_profile.php']],
                    'customer_profile_related_employees_view' => ['label' => 'پروفایل ۳۶۰: کارشناسان مرتبط', 'desc' => 'نمایشِ کارشناسانِ مرتبط در پروفایل ۳۶۰.', 'pages' => []],
                    'customer_profile_phones_view'            => ['label' => 'پروفایل ۳۶۰: شماره‌ها', 'desc' => 'نمایشِ همه‌ی شماره‌های ثبت‌شده‌ی مشتری.', 'pages' => []],
                    'customer_profile_timeline_view'          => ['label' => 'پروفایل ۳۶۰: تایم‌لاین', 'desc' => 'نمایشِ تایم‌لاینِ یکپارچه‌ی فعالیت‌ها.', 'pages' => []],
                    'customer_profile_calls_by_unit_view'     => ['label' => 'پروفایل ۳۶۰: تماس به تفکیک واحد', 'desc' => 'جمعِ تماس‌ها به تفکیکِ واحد.', 'pages' => []],
                    'customer_profile_calls_by_employee_view' => ['label' => 'پروفایل ۳۶۰: تماس به تفکیک کارشناس', 'desc' => 'جمعِ تماس‌ها به تفکیکِ کارشناس.', 'pages' => []],
                    'customer_others_followups_view' => ['label' => 'پیگیری‌ها و روند زمانیِ سایر کارشناسان', 'desc' => 'در پرونده‌ی مشتری، تاریخچه‌ی پیگیری‌ها و روند زمانیِ کارشناس‌های دیگر هم دیده شود. بدونِ این مجوز، هر کس فقط پیگیری‌ها و رویدادهای خودش را می‌بیند.', 'pages' => []],
                    'customer_view_all' => ['label' => 'مشاهده‌ی پرونده‌ی همه‌ی مشتریان (فقط خواندنی)', 'desc' => 'باز کردن و دیدنِ پرونده‌ی هر مشتری و دیدنِ همه در لیستِ مشتریان، بدونِ امکانِ ثبتِ پیگیری/ویرایش/ارجاع.', 'pages' => ['customer_view.php', 'customer_list.php', 'customer_profile.php'], 'sensitive' => true],
                    'customer_contact_type_change' => ['label' => 'تغییرِ نوعِ مخاطبِ قفل‌شده (همکار/خانواده)', 'desc' => 'شماره‌ای که همکار یا خانواده ثبت شده، برای همه قفل می‌شود؛ دارنده‌ی این مجوز می‌تواند نوعش را عوض کند.', 'pages' => [], 'sensitive' => true],
                    'customer_conflict_warning_view' => ['label' => 'هشدار تداخل مشتری', 'desc' => 'نمایشِ کادرِ «هشدار تداخل مشتری» (پرونده‌های دیگرِ همین شماره نزدِ کارشناس‌های دیگر) و تعیینِ مسئولِ اصلی.', 'pages' => []],
                ],
            ],
            'sales' => [
                'title' => 'ثبت سفارش',
                'icon'  => 'fa-cart-shopping',
                'desc'  => 'تبدیلِ پیش‌فاکتور به فاکتور، ارسالِ فیشِ واریزی و ثبتِ سفارش برای واحد مالی.',
                'items' => [
                    'orders_create'   => ['label' => 'ثبت سفارش از پیش‌فاکتور', 'desc' => 'تبدیلِ پیش‌فاکتورِ قفل‌شده به فاکتور و ارسالِ سفارش + فیش برای مالی.', 'pages' => ['order_submit.php']],
                    'quotes_delete'   => ['label' => 'حذفِ پیش‌فاکتور', 'desc' => 'حذفِ کاملِ پیش‌فاکتور (فقط وقتی سفارشی برایش ثبت نشده). پیش‌فرض: ادمین و مالی.', 'pages' => [], 'sensitive' => true],
                    'orders_delete'   => ['label' => 'حذف / لغوِ فاکتور (سفارش)', 'desc' => 'لغوِ سفارشِ در انتظار یا ردشده. پیش‌فرض: ادمین و مالی.', 'pages' => [], 'sensitive' => true],
                    'orders_view_own' => ['label' => 'سفارش‌های من', 'desc' => 'مشاهده‌ی وضعیتِ سفارش‌هایی که خودتان (یا تیمتان) ثبت کرده‌اید.', 'pages' => ['my_orders.php']],
                ],
            ],
            'imports' => [
                'title' => 'ورودی‌ها',
                'icon'  => 'fa-file-import',
                'desc'  => 'بارگذاریِ گزارش تماس، اکسل و فایل‌های ورودی.',
                'items' => [
                    'imports_view'         => ['label' => 'صفحه‌ی ورودی‌ها', 'desc' => 'دسترسی به صفحه‌ی اصلیِ ورودی‌ها.', 'pages' => ['imports.php']],
                    'call_log_import'      => ['label' => 'ورود گزارش تماس', 'desc' => 'بارگذاریِ فایلِ گزارشِ تماس.', 'pages' => ['call_log_import.php']],
                    'manual_log_import'    => ['label' => 'ثبت دستی/اکسلی گزارش', 'desc' => 'ثبتِ دستیِ گزارشِ تماس یا اکسلِ دستی.', 'pages' => ['manual_log_import.php', 'manual_import_create_chunk.php', 'manual_import_finalize.php']],
                    'vcf_import'           => ['label' => 'ورود فایل VCF', 'desc' => 'بارگذاریِ مخاطبین از فایلِ VCF.', 'pages' => ['vcf_import.php']],
                    'service_leads_import' => ['label' => 'ورود لید خدمات', 'desc' => 'بارگذاریِ لیدهای خدمات.', 'pages' => ['service_leads_import.php']],
                    'novatel_import'       => ['label' => 'ورود اکسل نواتل', 'desc' => 'بارگذاریِ اکسلِ نواتل.', 'pages' => ['admin_novatel_import.php'], 'sensitive' => true],
                ],
            ],
            'meetings' => [
                'title' => 'جلسات',
                'icon'  => 'fa-calendar-check',
                'desc'  => 'رزرو، تأیید و مدیریتِ جلسات.',
                'items' => [
                    'meetings_hub_view'            => ['label' => 'صفحه‌ی جلسات', 'desc' => 'دسترسی به هابِ جلسات.', 'pages' => ['meetings_hub.php']],
                    'meeting_verifications_view'   => ['label' => 'تأیید جلسات', 'desc' => 'مشاهده و ثبتِ تأییدِ جلسات.', 'pages' => ['meeting_verifications.php']],
                    'meeting_bookings_a_view'      => ['label' => 'رزروهای واحد A', 'desc' => 'مشاهده‌ی رزروهای ثبت‌شده‌ی واحد A.', 'pages' => ['meeting_bookings_a.php']],
                    'meeting_bookings_bc_view'     => ['label' => 'رزروهای واحد B/C', 'desc' => 'مشاهده‌ی رزروهای ثبت‌شده برای واحد B و C.', 'pages' => ['meeting_bookings_bc.php']],
                    'calendar_book_view'           => ['label' => 'رزرو از تقویم', 'desc' => 'رزروِ جلسه از تقویمِ همکاران.', 'pages' => ['calendar_book.php']],
                    'calendar_manage'              => ['label' => 'مدیریت تقویم من', 'desc' => 'تعریف و مدیریتِ زمان‌های خالیِ تقویم.', 'pages' => ['calendar_manage.php']],
                    'meeting_booking_history_view' => ['label' => 'تاریخچه‌ی رزرو', 'desc' => 'مشاهده‌ی تاریخچه‌ی یک رزرو.', 'pages' => ['meeting_booking_history_view.php']],
                    'reception_supervisor_meetings_view' => ['label' => 'جلسات پذیرشِ سرپرست', 'desc' => 'مشاهده‌ی جلساتی که نیروهای پذیرش برای سرپرست رزرو کرده‌اند.', 'pages' => ['reception_supervisor_meetings.php']],
                ],
            ],
            'my_reports' => [
                'title' => 'گزارش‌های من',
                'icon'  => 'fa-chart-pie',
                'desc'  => 'گزارش‌های شخصی و تیمی.',
                'items' => [
                    'personal_reports_view'           => ['label' => 'گزارش‌های من', 'desc' => 'صفحه‌ی اصلیِ گزارش‌های شخصی.', 'pages' => ['reports.php']],
                    'funnel_report_view'              => ['label' => 'گزارش قیف', 'desc' => 'قیفِ فروشِ شخصی.', 'pages' => ['funnel_report.php']],
                    'referral_report_view'            => ['label' => 'گزارش ارجاع', 'desc' => 'گزارشِ ارجاع‌ها.', 'pages' => ['referral_report.php']],
                    'geo_report_view'                 => ['label' => 'گزارش جغرافیایی', 'desc' => 'پراکندگیِ جغرافیایی.', 'pages' => ['geo_report.php']],
                    'meeting_report_view'             => ['label' => 'گزارش جلسات', 'desc' => 'گزارشِ جلساتِ شخصی.', 'pages' => ['meeting_report.php']],
                    'team_report_view'                => ['label' => 'گزارش تیم', 'desc' => 'گزارشِ تیمِ سرپرست.', 'pages' => ['team_report.php']],
                    'teams_report_view'               => ['label' => 'گزارش تیم‌ها', 'desc' => 'گزارشِ مقایسه‌ای تیم‌ها.', 'pages' => ['teams_report.php']],
                    'staff_report_view'               => ['label' => 'گزارش نیروها', 'desc' => 'گزارشِ عملکردِ نیروها.', 'pages' => ['staff_report.php', 'staff_detail_report.php']],
                    'my_calls_detail_view'            => ['label' => 'جزئیات تماس‌های من', 'desc' => 'ریزِ تماس‌های ثبت‌شده.', 'pages' => ['my_calls_detail.php']],
                    'my_meeting_bookings_report_view' => ['label' => 'گزارش رزرو جلسات من', 'desc' => 'رزروهایی که ثبت کرده‌اید.', 'pages' => ['my_meeting_bookings_report.php']],
                ],
            ],
            'reception' => [
                'title' => 'پذیرش کارشناس',
                'icon'  => 'fa-user-plus',
                'desc'  => 'پنلِ نیروهای پذیرش و بخش‌های مدیریتیِ پذیرش.',
                'items' => [
                    'reception_agent_panel'      => ['label' => 'پنل کارشناس پذیرش', 'desc' => 'درخواستِ متقاضی، تماس، پیگیری، رزرو جلسه‌ی آنلاین و ثبت مصاحبه‌ی حضوری، و «مسیر پیگیری» (یادآوری، ثبت حضور، تعیین تکلیف).', 'pages' => ['reception_dashboard.php']],
                    'admin_reception_hub'        => ['label' => 'هاب مدیریت پذیرش', 'desc' => 'صفحه‌ی اصلیِ مدیریتِ پذیرش در پنل مدیریت.', 'pages' => ['admin_reception_hub.php'], 'sensitive' => true],
                    'admin_reception_import'     => ['label' => 'اکسل ورودی متقاضیان', 'desc' => 'بارگذاریِ اکسلِ متقاضیان.', 'pages' => ['admin_reception_excel.php'], 'sensitive' => true],
                    'admin_reception_staff'      => ['label' => 'کارکنان پذیرش', 'desc' => 'افزودن (تکی/گروهی)، ویرایش و فعال/معلق‌کردنِ نیروهای پذیرش.', 'pages' => ['admin_reception_staff.php', 'admin_reception_user_create.php', 'admin_reception_agents_excel.php'], 'sensitive' => true],
                    'admin_reception_candidates' => ['label' => 'بانک متقاضیان', 'desc' => 'مشاهده‌ی همه‌ی متقاضیان و پرونده‌هایشان.', 'pages' => ['admin_reception_applicants_bank.php'], 'sensitive' => true],
                    'admin_reception_inperson'   => ['label' => 'مصاحبه‌های حضوری (همه)', 'desc' => 'فهرست و جستجوی همه‌ی متقاضیانی که برای مصاحبه‌ی حضوری ثبت شده‌اند.', 'pages' => [], 'sensitive' => true],
                    'admin_reception_supervisors'=> ['label' => 'سرپرست‌ها و لینک میتینگ', 'desc' => 'مدیریتِ سرپرست‌ها، لینکِ میتینگ و تایم‌های خالی.', 'pages' => ['admin_reception_supervisors.php'], 'sensitive' => true],
                    'admin_reception_reports'    => ['label' => 'گزارش آماری و قیف پذیرش', 'desc' => 'شاخص‌ها، عملکردِ کارشناسان، جلسات آنلاین و حضوری، قیفِ تماس تا حضور و واگذاریِ مجددِ پرونده‌ها.', 'pages' => ['admin_reception_reports.php'], 'sensitive' => true],
                    'admin_reception_settings'   => ['label' => 'تنظیمات پذیرش', 'desc' => 'وضعیت‌ها و شبکه‌های اجتماعیِ پذیرش.', 'pages' => ['admin_reception_settings.php'], 'sensitive' => true],
                ],
            ],
            'automation' => [
                'title' => 'اتوماسیون اداری',
                'icon'  => 'fa-file-signature',
                'desc'  => 'سقفِ دسترسیِ این نقش در اتوماسیون. دسترسیِ نهاییِ هر کاربر = «سطح اجازه‌ی اتوماسیونِ» خودش، محدود به همین سقف.',
                'items' => [
                    'letter_view'                => ['label' => 'مشاهده‌ی نامه‌ها', 'desc' => 'ورود به اتوماسیون و دیدنِ نامه‌های مربوط به خود.', 'pages' => []],
                    'letter_create'              => ['label' => 'ایجاد و ارسال نامه', 'desc' => 'نوشتن و ارسالِ نامه‌ی جدید.', 'pages' => []],
                    'letter_forward'             => ['label' => 'ارجاع نامه', 'desc' => 'ارجاعِ نامه به همکارِ دیگر.', 'pages' => []],
                    'letter_reply'               => ['label' => 'پاسخ به نامه', 'desc' => 'ثبتِ پاسخ برای نامه.', 'pages' => []],
                    'letter_approve'             => ['label' => 'تأیید نامه', 'desc' => 'تأییدِ نامه در مراحلِ تأیید.', 'pages' => []],
                    'letter_reject'              => ['label' => 'رد نامه', 'desc' => 'ردِ نامه در مراحلِ تأیید.', 'pages' => []],
                    'letter_return'              => ['label' => 'برگشت برای اصلاح', 'desc' => 'برگرداندنِ نامه برای اصلاح.', 'pages' => []],
                    'letter_view_attachment'     => ['label' => 'مشاهده‌ی پیوست', 'desc' => 'دیدنِ پیوست‌های نامه.', 'pages' => []],
                    'letter_download_attachment' => ['label' => 'دانلود پیوست', 'desc' => 'دریافتِ فایلِ پیوست.', 'pages' => []],
                    'letter_archive'             => ['label' => 'بایگانی نامه', 'desc' => 'انتقالِ نامه به بایگانی.', 'pages' => []],
                    'letter_delete'              => ['label' => 'حذف نامه', 'desc' => 'حذفِ نامه از اتوماسیون.', 'pages' => [], 'sensitive' => true],
                    'letter_view_all'            => ['label' => 'مشاهده‌ی مکاتبات زیرمجموعه/همه', 'desc' => 'دیدنِ مکاتباتِ کارکنانِ دیگر.', 'pages' => [], 'sensitive' => true],
                    'letter_view_reports'        => ['label' => 'گزارش‌های اتوماسیون', 'desc' => 'گزارش‌های آماریِ اتوماسیون.', 'pages' => ['automation_reports.php'], 'sensitive' => true],
                    'audit_log_view'             => ['label' => 'لاگ رویدادهای اتوماسیون', 'desc' => 'مشاهده‌ی Audit Log اتوماسیون.', 'pages' => ['automation_audit_log.php'], 'sensitive' => true],
                    'org_structure_manage'       => ['label' => 'مدیریت ساختار سازمانی', 'desc' => 'واحدها، رده‌ها و اعضای واحد.', 'pages' => ['admin_automation_org.php', 'admin_automation_unit_members.php'], 'sensitive' => true],
                    'org_members_manage'         => ['label' => 'اعضای سازمان', 'desc' => 'جایگاهِ سازمانیِ کاربران.', 'pages' => ['admin_automation_org_members.php'], 'sensitive' => true],
                    'permission_levels_manage'   => ['label' => 'تعریف سطوح اجازه', 'desc' => 'تعریف و ویرایشِ سطوحِ اجازه‌ی اتوماسیون.', 'pages' => ['admin_automation_permissions.php'], 'sensitive' => true],
                    'user_permission_level_manage' => ['label' => 'تخصیص سطح به کاربران', 'desc' => 'تعیینِ سطحِ اجازه‌ی اتوماسیون برای کاربران.', 'pages' => ['admin_automation_user_permissions.php', 'admin_automation_access_users.php'], 'sensitive' => true],
                ],
            ],
            'finance' => [
                'title' => 'امور مالی و سفارشات',
                'icon'  => 'fa-sack-dollar',
                'desc'  => 'دریافت، بررسی، تأیید یا ردِ سفارش‌ها و تنظیماتِ مالی.',
                'admin_panel' => true,
                'items' => [
                    'finance_orders_view'   => ['label' => 'مشاهده‌ی سفارشات (مالی)', 'desc' => 'فهرستِ همه‌ی سفارش‌ها، فیش‌ها و گزارشِ فروش.', 'pages' => ['admin_orders.php'], 'sensitive' => true],
                    'finance_orders_decide' => ['label' => 'تأیید / رد / در انتظار', 'desc' => 'تعیینِ وضعیتِ سفارش و ثبتِ نهاییِ آن.', 'pages' => [], 'sensitive' => true],
                    'finance_settings'      => ['label' => 'تنظیمات مالی و فاکتور', 'desc' => 'درصدِ مالیات، لوگو، سربرگ و رنگ‌های فاکتور.', 'pages' => ['admin_financial_settings.php', 'admin_invoice_settings.php'], 'sensitive' => true],
                ],
            ],
            'performance' => [
                'title' => 'سهم عملکرد کارشناسان',
                'icon'  => 'fa-trophy',
                'desc'  => 'محاسبه‌ی سهمِ A/B/C/D، قوانین و تارگت‌ها، پرداختِ سهم و حقوق.',
                'items' => [
                    'perf_view_own'       => ['label' => 'مشاهده‌ی سهمِ خود', 'desc' => '«سهم عملکرد من»: فقط سهم، عملکرد، طلب و حقوقِ خودش (سرپرست: اعضای تیمِ خودش).', 'pages' => ['my_performance.php']],
                    'perf_view_all'         => ['label' => 'مشاهده‌ی سهمِ همه', 'desc' => 'داشبورد، Performance Breakdownِ هر سفارش و سهمِ همه‌ی کارشناسان.', 'pages' => ['admin_perf.php', 'my_performance.php'], 'sensitive' => true],
                    'perf_rules_manage'     => ['label' => 'تنظیمِ سهمِ پایه (نسخه‌دار)', 'desc' => 'ثبتِ نسخه‌ی جدیدِ درصدِ سهمِ پایه برای آینده.', 'pages' => ['admin_perf.php'], 'sensitive' => true],
                    'perf_ownership_manage' => ['label' => 'تعیینِ دستیِ مالکیتِ مشتری (A/B/C)', 'desc' => 'تعیین/جایگزینی/حذفِ دستیِ A، B، C برای مشتری (هر جایگاه یک نفر؛ با دلیل).', 'pages' => ['customer_ownership.php'], 'sensitive' => true],
                    'perf_calc_edit'        => ['label' => 'ویرایشِ سهمِ سفارش', 'desc' => 'تغییرِ دریافت‌کنندگانِ سهمِ یک سفارش (A/B/C/سرپرست) با دلیل؛ محاسبه‌ی قبلی باطل و دوباره حساب می‌شود.', 'pages' => ['admin_perf.php'], 'sensitive' => true],
                    'perf_payouts_manage'   => ['label' => 'ثبتِ پرداختِ سهم و حقوق', 'desc' => 'ثبت و ابطالِ پرداختِ سهمِ عملکرد و حقوقِ ماهانه.', 'pages' => ['admin_perf.php'], 'sensitive' => true],
                    'box_a_access'          => ['label' => 'Box A (دریافتِ مشتری)', 'desc' => 'فقط نقشِ A: دیدنِ Box A و «دریافتِ مشتری».', 'pages' => ['box.php', 'customer_ownership.php']],
                    'box_b_access'          => ['label' => 'Box B (دریافتِ مشتری)', 'desc' => 'فقط نقشِ B: دیدنِ Box B و «دریافتِ مشتری» (= B مشتری).', 'pages' => ['box.php', 'customer_ownership.php']],
                    'box_c_access'          => ['label' => 'Box C (دریافتِ مشتری)', 'desc' => 'فقط نقشِ C: دیدنِ Box C و «دریافتِ مشتری».', 'pages' => ['box.php']],
                    'box_manage'            => ['label' => 'مدیریتِ Boxها', 'desc' => 'ورودِ لید (اکسل) به Box A، دیدن/خارج کردنِ موارد، ارجاعِ هر مشتری به Boxها.', 'pages' => ['box.php', 'customer_ownership.php'], 'sensitive' => true],
                ],
            ],
            'contracts' => [
                'title' => 'قراردادها',
                'icon'  => 'fa-file-signature',
                'desc'  => 'عملیاتِ حساس روی قراردادهای ساخته‌شده در پرونده‌ی مشتری.',
                'items' => [
                    'contracts_view_all'  => ['label' => 'مشاهده و چاپِ همه‌ی قراردادها', 'desc' => 'دیدن و چاپ/ارسالِ قراردادِ هر مشتری (نه فقط مشتریانِ خودش).', 'pages' => ['contract_view.php', 'contract_print.php']],
                    'contracts_manage'    => ['label' => 'تنظیم، ویرایش و صدورِ قرارداد', 'desc' => 'ساختِ قرارداد، تکمیل/ویرایشِ اطلاعات و متن، تأیید، صدور و بازگشایی — بدونِ دسترسی به تأیید/ردِ سفارش‌های مالی.', 'pages' => ['contract_view.php', 'contract_print.php']],
                    'contracts_dashboard' => ['label' => 'مدیریتِ قراردادها (پیگیریِ ساخت، صدور و ارسال)', 'desc' => 'صفحه‌ی «مدیریت قراردادها»: همه‌ی سفارش‌های تأییدشده با وضعیتِ قرارداد و ارسالِ تیکت؛ ساختن، باز کردن و ارسالِ قراردادهای جامانده.', 'pages' => ['contracts_manage.php']],
                    'contracts_delete' => ['label' => 'حذف قرارداد', 'desc' => 'حذفِ کاملِ قراردادِ ایجادشده (همراه با لینک‌ها و سابقه‌ی ارسالش) از پرونده‌ی مشتری. برگشت‌پذیر نیست.', 'pages' => [], 'sensitive' => true],
                ],
            ],
            'admin_users' => [
                'title' => 'مدیریت: کاربران و نقش‌ها',
                'icon'  => 'fa-users-gear',
                'desc'  => 'بخش‌های مدیریتیِ کاربران، کارمندان، تیم‌ها و نقش‌ها.',
                'admin_panel' => true,
                'items' => [
                    'admin_dashboard'         => ['label' => 'آمار کلی پنل مدیریت', 'desc' => 'نمایشِ کارت‌های آماریِ بالای پنل مدیریت.', 'pages' => [], 'sensitive' => true],
                    'admin_users_view'        => ['label' => 'مشاهده‌ی کاربران', 'desc' => 'فهرستِ کاربران و کاربرانِ آنلاین.', 'pages' => ['admin_users.php', 'admin_online_users.php'], 'sensitive' => true],
                    'admin_users_create'      => ['label' => 'ایجاد کاربر', 'desc' => 'ساختِ کاربرِ جدید.', 'pages' => ['admin_user_create.php'], 'sensitive' => true],
                    'admin_users_edit'        => ['label' => 'ویرایش کاربر', 'desc' => 'ویرایشِ مشخصات، تیم و گروهِ شغلی.', 'pages' => ['admin_user_edit.php'], 'sensitive' => true],
                    'admin_users_approve'     => ['label' => 'تأیید / رد عضویت', 'desc' => 'تأیید یا ردِ درخواستِ عضویتِ کاربرانِ جدید.', 'pages' => [], 'sensitive' => true],
                    'admin_users_activate'    => ['label' => 'فعال / تعلیق کاربر', 'desc' => 'فعال یا معلق‌کردنِ حسابِ کاربری.', 'pages' => [], 'sensitive' => true],
                    'admin_users_role'        => ['label' => 'تغییر نقش کاربر', 'desc' => 'تغییرِ نقشِ کاربر از فهرستِ کاربران یا صفحه‌ی نقش‌ها.', 'pages' => [], 'sensitive' => true],
                    'admin_users_delete'      => ['label' => 'حذف کاربر', 'desc' => 'حذفِ کاملِ حسابِ کاربری.', 'pages' => [], 'sensitive' => true],
                    'admin_users_impersonate' => ['label' => 'ورود به حساب کاربر', 'desc' => 'مشاهده و کار با سامانه از دیدِ کاربرِ دیگر (از فهرستِ کاربران). ورود به حسابِ ادمینِ کل و ادمین‌های دیگر فقط برای ادمینِ کل ممکن است.', 'pages' => ['admin_impersonate.php', 'admin_users.php']],
                    'admin_roles'             => ['label' => 'نقش‌ها و دسترسی‌ها', 'desc' => 'همین صفحه: تعریفِ نقش و تعیینِ مجوزها.', 'pages' => ['admin_roles.php', 'admin_role_permissions.php'], 'sensitive' => true],
                    'admin_employees'         => ['label' => 'کارمندان', 'desc' => 'مدیریت و اکسلِ کارمندان و مشخصاتِ پرسنل.', 'pages' => ['admin_employees.php', 'admin_employees_import.php', 'admin_users_attributes_import.php'], 'sensitive' => true],
                    'admin_teams_manage'      => ['label' => 'مدیریت تیم‌ها', 'desc' => 'ساخت و ویرایشِ تیم‌ها و سرپرست‌ها.', 'pages' => ['admin_teams_manage.php'], 'sensitive' => true],
                    'admin_customers'         => ['label' => 'مدیریت مشتریان (کل)', 'desc' => 'مدیریتِ کلیِ جدولِ مشتریان.', 'pages' => ['admin_customers.php'], 'sensitive' => true],
                ],
            ],
            'admin_reports' => [
                'title' => 'مدیریت: گزارش‌ها',
                'icon'  => 'fa-chart-line',
                'desc'  => 'گزارش‌های مدیریتی و عملکردِ همه‌ی نیروها.',
                'admin_panel' => true,
                'items' => [
                    'reports_view' => ['label' => 'گزارش‌های مدیریتی', 'desc' => 'همه‌ی گزارش‌های مدیریتی (تماس، قیف، جلسات، ارجاع، نیروها، تیم‌ها).', 'pages' => [
                        'admin_reports.php', 'admin_reports_calls.php', 'admin_reports_calls_detail.php', 'admin_reports_funnel.php',
                        'admin_reports_geo.php', 'admin_reports_meetings.php', 'admin_reports_referrals.php', 'admin_reports_staff_growth.php',
                        'admin_staff_view.php', 'admin_staff_report.php', 'admin_staff_stat_detail.php', 'admin_today_status_detail.php',
                        'admin_team_leader_report.php', 'admin_teams_report.php', 'admin_meeting_bookings_report.php',
                    ], 'sensitive' => true],
                    'supervisor_report_all' => ['label' => 'گزارشِ سرپرستِ همه‌ی تیم‌ها', 'desc' => 'فعالیتِ هر سرپرست، تماسش با نیروها، پوشش، راندمانِ نیروها و تیم‌ها.', 'pages' => ['supervisor_report.php', 'supervisor_daily_report.php'], 'sensitive' => true],
                    'supervisor_report_view' => ['label' => 'گزارشِ سرپرست (تیمِ خودش)', 'desc' => 'سرپرست: فعالیتِ خودش، نیروهایی که با آن‌ها صحبت کرده/نکرده، و راندمانِ نیروهای تیمِ خودش.', 'pages' => ['supervisor_report.php', 'supervisor_daily_report.php']],
                ],
            ],
            'admin_services' => [
                'title' => 'مدیریت: خدمات',
                'icon'  => 'fa-briefcase',
                'desc'  => 'فهرستِ خدمات، قیمت‌ها و طرح‌های آماده.',
                'admin_panel' => true,
                'items' => [
                    'admin_services_hub'            => ['label' => 'فهرست خدمات', 'desc' => 'خدمات، قیمت‌ها، طرح‌ها، وابستگی‌ها و آرشیو.', 'pages' => ['admin_services_hub.php', 'admin_services_list.php', 'admin_service_packages.php', 'admin_service_dependencies.php', 'admin_services_archive.php', 'admin_services_import.php'], 'sensitive' => true],
                    'admin_traders_services_import' => ['label' => 'خدمات تاجران', 'desc' => 'بارگذاریِ اکسلِ خدماتِ تاجران.', 'pages' => ['admin_traders_services_import.php'], 'sensitive' => true],
                ],
            ],
            'admin_data' => [
                'title' => 'مدیریت: ابزارهای داده',
                'icon'  => 'fa-screwdriver-wrench',
                'desc'  => 'ابزارهای اصلاح و نگهداریِ داده‌ها.',
                'admin_panel' => true,
                'items' => [
                    'admin_redistribute_followups'   => ['label' => 'توزیع سررسیدهای پیگیری', 'desc' => '', 'pages' => ['admin_redistribute_followups.php'], 'sensitive' => true],
                    'admin_advisor_transfer_import'  => ['label' => 'تعیین کارشناس اول', 'desc' => '', 'pages' => ['admin_advisor_transfer_import.php'], 'sensitive' => true],
                    'admin_fix_invalid_statuses'     => ['label' => 'اصلاح وضعیت‌های نامعتبر', 'desc' => '', 'pages' => ['admin_fix_invalid_statuses.php'], 'sensitive' => true],
                    'admin_fix_future_dates'         => ['label' => 'اصلاح تاریخ‌های آینده', 'desc' => '', 'pages' => ['admin_fix_future_dates.php', 'admin_bad_dates.php'], 'sensitive' => true],
                    'admin_phone_conflicts'          => ['label' => 'تداخل شماره‌ها', 'desc' => '', 'pages' => ['admin_phone_conflicts.php'], 'sensitive' => true],
                    'admin_colleague_mismatch_check' => ['label' => 'مغایرت شماره همکار/مشتری', 'desc' => '', 'pages' => ['admin_colleague_mismatch_check.php'], 'sensitive' => true],
                    'admin_phone_diagnostic'         => ['label' => 'بررسی سابقه‌ی یک شماره (مدیریتی)', 'desc' => '', 'pages' => ['admin_phone_diagnostic.php'], 'sensitive' => true],
                    'customer_name_lock'             => ['label' => 'قفل کردنِ «نام ثابتِ» یک شماره', 'desc' => 'در «بررسی سابقه‌ی یک شماره (مدیریتی)»: تعیین/تغییرِ اسمِ ثابت برای یک شماره؛ اسمِ همه‌ی پرونده‌های آن شماره (زیرِ هر کارشناسی) یکسان و غیرقابلِ ویرایش می‌شود.', 'pages' => ['admin_phone_diagnostic.php'], 'sensitive' => true],
                    'admin_call_conferences'         => ['label' => 'تماس‌های کنفرانسی', 'desc' => 'تشخیص و اصلاحِ تماس‌های کنفرانسی.', 'pages' => ['admin_call_conferences.php', 'admin_fix_conference_calls.php'], 'sensitive' => true],
                    'admin_clear_call_import'        => ['label' => 'پاکسازی گزارش تماس', 'desc' => '', 'pages' => ['admin_clear_call_import.php'], 'sensitive' => true],
                    'admin_ai_name_cleanup'          => ['label' => 'پاکسازی نام‌ها با AI', 'desc' => '', 'pages' => ['admin_ai_name_cleanup.php'], 'sensitive' => true],
                    'admin_acquaintances_manage'     => ['label' => 'مدیریت آشنایان', 'desc' => '', 'pages' => ['admin_acquaintances_manage.php'], 'sensitive' => true],
                    'admin_bulk_referral'            => ['label' => 'ارجاع دسته‌جمعی', 'desc' => '', 'pages' => ['admin_bulk_referral.php'], 'sensitive' => true],
                    'admin_complainants_import'      => ['label' => 'مشتریان شاکی', 'desc' => '', 'pages' => ['admin_complainants_import.php'], 'sensitive' => true],
                    'admin_cities_manage'            => ['label' => 'استان‌ها و شهرها', 'desc' => '', 'pages' => ['admin_cities_manage.php'], 'sensitive' => true],
                ],
            ],
            'admin_system' => [
                'title' => 'مدیریت: سیستم',
                'icon'  => 'fa-server',
                'desc'  => 'بخش‌های فنی و زیرساختی.',
                'admin_panel' => true,
                'items' => [
                    'admin_system_update' => ['label' => 'بروزرسانی سیستم', 'desc' => 'فقط ادمین کل.', 'pages' => ['admin_system_update.php'], 'sensitive' => true],
                    'admin_api_keys'      => ['label' => 'کلیدهای API و اتصال‌ها', 'desc' => 'کلیدهای API و اتصال‌های بیرونی.', 'pages' => ['admin_api_keys.php', 'admin_external_apis.php'], 'sensitive' => true],
                    'admin_system_tools'  => ['label' => 'ابزارهای فنی', 'desc' => 'وضعیت مایگریشن، اعلان Push، عیب‌یابی سرپرست‌ها و اسکریپت‌های انتقال داده.', 'pages' => ['admin_migration_status.php', 'admin_vapid_setup.php', 'admin_leader_diagnostic.php', 'admin_migrate_customer_relations.php', 'migrate_phone_normalized.php', 'who_phpsessid.php'], 'sensitive' => true],
                ],
            ],
        ];
        return $cat;
    }
}

if (!function_exists('perm_all_items')) {
    /** فهرستِ تخت: key => item + group */
    function perm_all_items(): array
    {
        static $flat = null;
        if ($flat !== null) {
            return $flat;
        }
        $flat = [];
        foreach (perm_catalog() as $gk => $g) {
            foreach ($g['items'] as $k => $it) {
                $it['group'] = $gk;
                $it['sensitive'] = !empty($it['sensitive']);
                $flat[$k] = $it;
            }
        }
        return $flat;
    }
}

if (!function_exists('perm_label')) {
    function perm_label(string $key): string
    {
        $all = perm_all_items();
        return $all[$key]['label'] ?? $key;
    }
}

/* ------------------------------------------------------------------ */
/*  نقش‌ها                                                             */
/* ------------------------------------------------------------------ */

if (!function_exists('perm_role_row_kind')) {
    /**
     * نوعِ یک ردیفِ access_roles: base (اصلی) / service (ویژه) / custom (مکمل) / super
     * نقش‌های سیستمی نوعِ ثابت دارند، مگر نقش‌های ویژه‌ی قابلِ‌تغییر (مثلِ واحد قرارداد) که kind_override دارند.
     */
    function perm_role_row_kind(array $row): string
    {
        $sys = perm_system_roles();
        $k = (string) ($row['role_key'] ?? '');
        if (isset($sys[$k])) {
            $ov = (string) ($row['kind_override'] ?? '');
            $locked = ['supervisor', 'financial_liaison', 'reception_admin', 'reception_agent'];
            if ($sys[$k]['kind'] === 'service' && !in_array($k, $locked, true) && in_array($ov, ['service', 'custom'], true)) return $ov;
            return $sys[$k]['kind'];
        }
        $kind = (string) ($row['role_kind'] ?? 'custom');
        return in_array($kind, ['base', 'service', 'custom'], true) ? $kind : 'custom';
    }
}

if (!function_exists('perm_system_roles')) {
    /**
     * نقش‌های سیستمی. kind:
     *   base    → در ستونِ users.role ذخیره می‌شود (نقشِ اصلی)
     *   service → در ستونِ users.service_access_role ذخیره می‌شود (نقشِ ویژه، مکمل)
     *   super   → با users.is_super_admin
     */
    function perm_system_roles(): array
    {
        return [
            'super_admin'       => ['label' => 'ادمین کل', 'kind' => 'super', 'desc' => 'دسترسیِ کامل به همه‌چیز؛ قابل محدودسازی نیست.'],
            'admin'             => ['label' => 'ادمین', 'kind' => 'base', 'desc' => 'مدیرِ سیستم؛ همه‌چیز به‌جز بخش‌های مخصوصِ ادمین کل.'],
            'leader'            => ['label' => 'سرپرست', 'kind' => 'base', 'desc' => 'سرپرستِ تیم؛ کارهای فروش + گزارش‌های تیم.'],
            'A'                 => ['label' => 'واحد A', 'kind' => 'base', 'desc' => 'کارمندِ عادی؛ بدونِ دسترسی به بخش‌های حساس.'],
            'B'                 => ['label' => 'واحد B', 'kind' => 'base', 'desc' => 'کارمندِ عادی؛ بدونِ دسترسی به بخش‌های حساس.'],
            'C'                 => ['label' => 'واحد C', 'kind' => 'base', 'desc' => 'کارمندِ عادی؛ بدونِ دسترسی به بخش‌های حساس.'],
            'nonsales'          => ['label' => 'ستادی', 'kind' => 'base', 'desc' => 'نیروی ستادی.'],
            'supervisor'        => ['label' => 'نظارت', 'kind' => 'service', 'desc' => 'نقشِ ویژه‌ی نظارت؛ گزارش‌ها و پایش.'],
            'financial_liaison' => ['label' => 'رابط مالی (واحد مالی)', 'kind' => 'service', 'desc' => 'نقشِ ویژه‌ی واحد مالی؛ بررسی و تأییدِ سفارش‌ها.'],
            'reception_admin'   => ['label' => 'ادمین پذیرش', 'kind' => 'service', 'desc' => 'مدیریتِ کاملِ بخشِ پذیرش کارشناس.'],
            'reception_agent'   => ['label' => 'کارشناس پذیرش', 'kind' => 'service', 'desc' => 'نیروی پذیرش؛ تماس با متقاضیان و ثبت جلسه.'],
            'contract_unit'     => ['label' => 'واحد قرارداد', 'kind' => 'service', 'desc' => 'مشاهده‌ی اطلاعاتِ مشتریان و پرداخت‌ها/اقساط؛ تنظیم، ویرایش، صدور و چاپِ قراردادها.'],
        ];
    }
}

if (!function_exists('perm_restricted_roles')) {
    /** نقش‌های «کارمند عادی» که هرگز مجوزِ حساس نمی‌گیرند. */
    function perm_restricted_roles(): array
    {
        return ['A', 'B', 'C'];
    }
}

if (!function_exists('perm_super_only_keys')) {
    function perm_super_only_keys(): array
    {
        return ['admin_system_update', 'admin_cities_manage'];
    }
}

if (!function_exists('perm_sensitive_keys')) {
    function perm_sensitive_keys(): array
    {
        $out = [];
        foreach (perm_all_items() as $k => $it) {
            if ($it['sensitive']) {
                $out[] = $k;
            }
        }
        return $out;
    }
}

if (!function_exists('perm_admin_panel_keys')) {
    /** هر مجوزی که یک کارت در پنل مدیریت دارد (برای باز شدنِ پنل کافی است). */
    function perm_admin_panel_keys(): array
    {
        $out = [];
        foreach (perm_catalog() as $g) {
            if (!empty($g['admin_panel'])) {
                $out = array_merge($out, array_keys($g['items']));
            }
        }
        foreach (['admin_reception_hub', 'admin_reception_import', 'admin_reception_staff', 'admin_reception_candidates',
                  'admin_reception_inperson', 'admin_reception_supervisors', 'admin_reception_reports', 'admin_reception_settings',
                  'org_structure_manage', 'org_members_manage', 'permission_levels_manage', 'user_permission_level_manage'] as $k) {
            $out[] = $k;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('perm_automation_basic')) {
    function perm_automation_basic(): array
    {
        return ['letter_view', 'letter_create', 'letter_forward', 'letter_reply', 'letter_approve', 'letter_reject',
                'letter_return', 'letter_view_attachment', 'letter_download_attachment', 'letter_archive'];
    }
}

if (!function_exists('perm_automation_keys')) {
    function perm_automation_keys(): array
    {
        return array_keys(perm_catalog()['automation']['items']);
    }
}

if (!function_exists('perm_role_defaults')) {
    function perm_role_defaults(string $roleKey): array
    {
        $base = [
            'perf_view_own', 'box_a_access', 'box_b_access', 'box_c_access',
            'dashboard_view', 'help_view', 'chat_view',
            'customer_list_view', 'customer_view', 'customer_new_view', 'customer_edit', 'customer_merge', 'customer_refer',
            'customer_referrals_view', 'phone_history_view', 'service_requests_view', 'service_leads_view', 'quotes_manage',
            'orders_create', 'orders_view_own',
            'imports_view', 'call_log_import', 'manual_log_import', 'vcf_import', 'service_leads_import',
            'meetings_hub_view', 'meeting_verifications_view', 'meeting_bookings_a_view', 'meeting_bookings_bc_view',
            'calendar_book_view', 'calendar_manage', 'meeting_booking_history_view',
            'personal_reports_view', 'funnel_report_view', 'referral_report_view', 'geo_report_view', 'meeting_report_view',
            'team_report_view', 'teams_report_view', 'staff_report_view', 'my_calls_detail_view', 'my_meeting_bookings_report_view',
        ];
        $auto = perm_automation_basic();
        $profile360 = ['customer_profile_view', 'customer_profile_related_employees_view', 'customer_profile_phones_view',
                       'customer_profile_timeline_view', 'customer_profile_calls_by_unit_view', 'customer_profile_calls_by_employee_view'];
        $receptionAdmin = ['admin_reception_hub', 'admin_reception_import', 'admin_reception_staff', 'admin_reception_candidates',
                           'admin_reception_inperson', 'admin_reception_supervisors', 'admin_reception_reports', 'admin_reception_settings'];

        switch ($roleKey) {
            case 'super_admin':
                return array_keys(perm_all_items());
            case 'admin':
                return array_values(array_diff(array_keys(perm_all_items()), perm_super_only_keys()));
            case 'A':
            case 'B':
            case 'nonsales':
                return array_merge($base, $auto);
            case 'C':
                return array_merge($base, $auto, ['service_requests_import']);
            case 'leader':
                return array_merge($base, $auto, $profile360, ['service_requests_import', 'reception_supervisor_meetings_view',
                    'letter_view_all', 'letter_view_reports']);
            case 'supervisor':
                return array_merge($base, $auto, $profile360, ['customer_others_followups_view', 'customer_conflict_warning_view',
                    'service_requests_import', 'reports_view', 'admin_dashboard',
                    'novatel_import', 'admin_fix_future_dates', 'letter_view_all', 'letter_view_reports', 'finance_orders_view']);
            case 'financial_liaison':
                return array_merge(['dashboard_view', 'help_view', 'chat_view', 'customer_list_view', 'customer_view',
                    'phone_history_view', 'service_requests_view', 'orders_view_own', 'reports_view', 'admin_dashboard',
                    'finance_orders_view', 'finance_orders_decide', 'finance_settings', 'admin_fix_future_dates', 'contracts_dashboard'], $auto);
            case 'contract_unit':
                // واحد قرارداد: اطلاعاتِ مشتری (فقط خواندنی) + پرداخت‌ها/اقساط (مشاهده) + قراردادها (تنظیم/ویرایش/صدور/چاپ)
                return ['dashboard_view', 'help_view', 'chat_view', 'customer_list_view', 'customer_view', 'customer_view_all',
                    'customer_profile_view', 'customer_profile_phones_view', 'phone_history_view',
                    'finance_orders_view', 'contracts_view_all', 'contracts_manage', 'contracts_dashboard'];
            case 'reception_agent':
                return ['dashboard_view', 'help_view', 'chat_view', 'reception_agent_panel',
                        'letter_view', 'letter_create', 'letter_reply', 'letter_view_attachment', 'letter_download_attachment', 'letter_archive'];
            case 'reception_admin':
                return array_merge(['dashboard_view', 'help_view', 'chat_view', 'reception_agent_panel', 'admin_dashboard'], $receptionAdmin, $auto);
        }
        return [];
    }
}

if (!function_exists('perm_legacy_map')) {
    /** کلیدهای قدیمی/تکراری → کلیدهای جدید (برای مهاجرتِ یک‌باره) */
    function perm_legacy_map(): array
    {
        return [
            'conversations_view' => ['chat_view'], 'conversations_create' => ['chat_view'], 'conversations_edit' => ['chat_view'],
            'conversations_delete' => [], 'chat_create' => ['chat_view'], 'chat_edit' => ['chat_view'],
            'inputs_view' => ['imports_view'], 'inputs_create' => ['imports_view'], 'inputs_edit' => [], 'inputs_delete' => [],
            'meetings_view' => ['meetings_hub_view'], 'meetings_create' => ['meetings_hub_view'], 'meetings_edit' => [], 'meetings_delete' => [],
            'meetings_hub_view' => ['meetings_hub_view'],
            'contacts_view' => ['customer_list_view'], 'contacts_search' => ['customer_list_view'], 'contacts_create' => ['customer_new_view'],
            'contacts_edit' => ['customer_edit'], 'contacts_delete' => [], 'contacts_history' => ['customer_view'],
            'contacts_followups' => ['customer_view'], 'contacts_referral' => ['customer_refer'],
            'admin_users_search' => ['admin_users_view'], 'admin_users_reject' => ['admin_users_approve'],
            'reports_detailed' => ['reports_view'], 'reports_export' => [], 'audit_logs_view' => [],
            'letter_cc' => [], 'letter_send' => ['letter_create'], 'letter_search_all' => ['letter_view_all'],
            'letter_attachment' => ['letter_view_attachment', 'letter_download_attachment'],
            'admin_reception_staff_create' => ['admin_reception_staff'], 'admin_reception_staff_edit' => ['admin_reception_staff'],
            'admin_reception_staff_toggle' => ['admin_reception_staff'],
            'notifications_view' => [], 'profile_view' => [],
        ];
    }
}

if (!function_exists('perm_derived_on_upgrade')) {
    /** اگر نقشی X را داشت، Y هم به او داده شود (تا رفتارِ قبلی حفظ شود؛ فقط هنگامِ ارتقای نسخه) */
    function perm_derived_on_upgrade(): array
    {
        return [
            'customer_view'         => ['quotes_manage', 'orders_create', 'orders_view_own'],
            'service_requests_view' => ['service_leads_view'],
            'personal_reports_view' => ['my_calls_detail_view', 'staff_report_view', 'teams_report_view'],
            'meetings_hub_view'     => ['reception_supervisor_meetings_view'],
            'call_log_import'       => ['service_leads_import'],
            'letter_view'           => ['letter_view_attachment', 'letter_download_attachment'],
            'admin_reception_hub'   => ['admin_reception_inperson'],
            'admin_users_view'      => ['admin_customers'],
            'admin_phone_conflicts' => ['admin_colleague_mismatch_check'],
            'admin_api_keys'        => ['admin_system_tools'],
        ];
    }
}

if (!function_exists('perm_normalize')) {
    /** لیستِ کلیدها (یا map key=>bool) را به کلیدهای معتبرِ فعلی تبدیل می‌کند. */
    function perm_normalize(array $perms, bool $applyLegacy = true): array
    {
        $all = perm_all_items();
        $legacy = perm_legacy_map();
        $out = [];
        foreach ($perms as $k => $v) {
            if (is_int($k)) {
                $k = (string) $v;
                $v = true;
            }
            if (!$v) {
                continue;
            }
            $k = (string) $k;
            if (isset($all[$k])) {
                $out[$k] = true;
            } elseif ($applyLegacy && isset($legacy[$k])) {
                foreach ($legacy[$k] as $nk) {
                    if (isset($all[$nk])) {
                        $out[$nk] = true;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('perm_apply_role_restrictions')) {
    /** قیدهای اجباری: کارمندانِ عادی هیچ مجوزِ حساسی ندارند. */
    function perm_apply_role_restrictions(string $roleKey, array $perms): array
    {
        if (in_array($roleKey, perm_restricted_roles(), true)) {
            foreach (perm_sensitive_keys() as $k) {
                unset($perms[$k]);
            }
        }
        if ($roleKey !== 'super_admin') {
            // بخش‌های مخصوصِ ادمین کل همیشه در خودِ صفحه هم دوباره چک می‌شوند
        }
        return $perms;
    }
}

/* ------------------------------------------------------------------ */
/*  همگام‌سازیِ یک‌باره‌ی access_roles                                 */
/* ------------------------------------------------------------------ */

if (!function_exists('perm_table_columns')) {
    function perm_table_columns(PDO $pdo, string $table): array
    {
        try {
            $st = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('perm_ensure_tables')) {
    function perm_ensure_tables(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS access_roles (
            id INT NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            permissions_json LONGTEXT NULL,
            role_key VARCHAR(100) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            permissions_initialized TINYINT(1) NOT NULL DEFAULT 0,
            permissions_baseline_version INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_access_roles_name (name),
            KEY idx_access_roles_key (role_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = perm_table_columns($pdo, 'access_roles');
        foreach ([
            'permissions_json' => 'ALTER TABLE access_roles ADD COLUMN permissions_json LONGTEXT NULL',
            'role_key' => 'ALTER TABLE access_roles ADD COLUMN role_key VARCHAR(100) NULL',
            'is_system' => 'ALTER TABLE access_roles ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0',
            'description' => 'ALTER TABLE access_roles ADD COLUMN description VARCHAR(255) NULL',
            'permissions_initialized' => 'ALTER TABLE access_roles ADD COLUMN permissions_initialized TINYINT(1) NOT NULL DEFAULT 0',
            'permissions_baseline_version' => 'ALTER TABLE access_roles ADD COLUMN permissions_baseline_version INT NOT NULL DEFAULT 0',
        ] as $col => $sql) {
            if ($cols && !in_array($col, $cols, true)) {
                try { $pdo->exec($sql); } catch (Throwable $e) {}
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_access_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            KEY idx_uar_role (role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('perm_sync_flag_file')) {
    function perm_sync_flag_file(): string
    {
        return __DIR__ . '/../storage/.perm_baseline_v' . PERM_BASELINE_VERSION;
    }
}

if (!function_exists('perm_one_time_grants')) {
    /**
     * مجوزهای تازه‌ای که فقط یک‌بار به نقش‌های مشخص داده می‌شوند (بدونِ بالا بردنِ PERM_BASELINE_VERSION
     * و دست‌زدن به بقیه‌ی مجوزهای نقش‌ها). بعد از اعمال، هر تغییری از صفحه‌ی «نقش‌ها و دسترسی‌ها» حفظ می‌شود.
     * key => [role_key, ...]
     */
    function perm_one_time_grants(): array
    {
        return [
            // حذفِ قرارداد: فعلاً فقط ادمین (ادمین کل همیشه همه‌چیز را دارد)
            'contracts_delete' => ['admin'],
            // مدیریتِ قراردادها: ادمین، واحدِ قرارداد و واحدِ مالی (ادمین کل همیشه دارد)
            'contracts_dashboard' => ['admin', 'contract_unit', 'financial_liaison'],
            // حذفِ پیش‌فاکتور و حذف/لغوِ فاکتور: فقط ادمین و مالی (بقیه از «نقش‌ها و دسترسی‌ها» قابلِ تنظیم)
            'quotes_delete' => ['admin', 'financial_liaison'],
            'orders_delete' => ['admin', 'financial_liaison'],
            // دیدنِ پیگیری‌های کارشناس‌های دیگر و هشدارِ تداخل: فقط مدیران و نظارت (کارشناس‌ها نه)
            'customer_others_followups_view' => ['admin', 'supervisor'],
            'customer_conflict_warning_view' => ['admin', 'supervisor'],
            'customer_contact_type_change' => ['admin', 'supervisor'],
            // «ورود به حساب کاربر»: علاوه بر ادمینِ کل، ادمین هم (از نقش‌ها برای بقیه قابلِ تنظیم است)
            'admin_users_impersonate' => ['admin'],
            'customer_name_lock' => ['admin'],
            // سهم عملکرد: هر کارشناس سهمِ خودش؛ ادمین همه‌ی بخش‌ها؛ رابط مالی محاسبه/پرداخت
            'perf_view_own' => ['A', 'B', 'C', 'leader', 'admin', 'financial_liaison', 'supervisor'],
            'perf_view_all' => ['admin', 'financial_liaison'],
            'perf_rules_manage' => ['admin'],
            'perf_ownership_manage' => ['admin'],
            'box_a_access' => ['A'],
            'box_b_access' => ['B'],
            'box_c_access' => ['C'],
            'box_manage' => ['admin'],
            'supervisor_report_view' => ['leader'],
            'supervisor_report_all' => ['admin', 'supervisor'],
            'perf_payouts_manage' => ['admin', 'financial_liaison'],
            'perf_calc_edit' => ['admin', 'financial_liaison'],
        ];
    }
}

if (!function_exists('perm_apply_one_time_grants')) {
    function perm_apply_one_time_grants(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach (perm_one_time_grants() as $permKey => $roleKeys) {
            $flag = __DIR__ . '/../storage/.perm_grant_' . preg_replace('/[^a-z0-9_]/', '', $permKey);
            if (is_file($flag)) {
                continue;
            }
            try {
                perm_ensure_tables($pdo);
                $st = $pdo->prepare('SELECT id, permissions_json FROM access_roles WHERE role_key = ? LIMIT 1');
                $ok = true;
                foreach ($roleKeys as $rk) {
                    $st->execute([$rk]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        // ردیفِ نقش هنوز ساخته نشده؛ perm_sync_roles آن را با پیش‌فرض‌ها (که این مجوز را دارد) می‌سازد
                        continue;
                    }
                    $perms = json_decode((string) ($row['permissions_json'] ?? ''), true);
                    $perms = is_array($perms) ? $perms : [];
                    if ($perms && array_keys($perms) === range(0, count($perms) - 1)) {
                        $perms = array_fill_keys(array_map('strval', $perms), true);
                    }
                    if (empty($perms[$permKey])) {
                        $perms[$permKey] = true;
                        $ok = $pdo->prepare('UPDATE access_roles SET permissions_json = ? WHERE id = ?')
                            ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), (int) $row['id']]) && $ok;
                    }
                }
                if ($ok) {
                    if (!is_dir(dirname($flag))) {
                        @mkdir(dirname($flag), 0755, true);
                    }
                    @file_put_contents($flag, (string) time());
                }
            } catch (Throwable $e) {
                error_log('perm_apply_one_time_grants: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('perm_sync_roles')) {
    /**
     * نقش‌های سیستمی را می‌سازد/به‌روز می‌کند و (فقط یک‌بار برای هر نسخه) کلیدهای قدیمی
     * را به کلیدهای جدید مهاجرت می‌دهد. مجوزهایی که ادمین دستی تنظیم کرده حفظ می‌شوند؛
     * فقط مجوزهای «تازه‌اضافه‌شده در این نسخه» از روی پیش‌فرض‌ها اضافه می‌شوند.
     */
    function perm_sync_roles(PDO $pdo, bool $force = false): void
    {
        static $done = false;
        if ($done && !$force) {
            return;
        }
        $done = true;
        perm_apply_one_time_grants($pdo);
        if (!$force) {
            if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['__perm_synced_v']) && (int) $_SESSION['__perm_synced_v'] === PERM_BASELINE_VERSION) {
                return;
            }
            if (is_file(perm_sync_flag_file())) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['__perm_synced_v'] = PERM_BASELINE_VERSION;
                }
                return;
            }
        }

        try {
            perm_ensure_tables($pdo);

            // کلیدهایی که در نسخه‌ی قبلی وجود نداشتند (یا کنترل نمی‌شدند)
            $newInThisVersion = [
                'customer_view_all', 'contracts_view_all', 'contracts_manage',
                'service_leads_view', 'quotes_manage', 'service_leads_import', 'reception_supervisor_meetings_view',
                'teams_report_view', 'staff_report_view', 'my_calls_detail_view', 'orders_create', 'orders_view_own',
                'finance_orders_view', 'finance_orders_decide', 'finance_settings', 'reception_agent_panel',
                'admin_reception_inperson', 'admin_customers', 'admin_colleague_mismatch_check', 'admin_system_tools',
                'letter_view_attachment', 'letter_download_attachment', 'letter_view_reports', 'audit_log_view',
                'org_structure_manage', 'org_members_manage', 'permission_levels_manage', 'user_permission_level_manage',
                'chat_view', 'help_view', 'dashboard_view',
                // این‌ها قبلاً فقط از «سطح اجازه‌ی اتوماسیون» می‌آمدند و در نقش اثری نداشتند
                'letter_approve', 'letter_reject', 'letter_return', 'letter_archive', 'letter_forward', 'letter_reply', 'letter_create', 'letter_view',
            ];

            $systemRoles = perm_system_roles();
            $derived = perm_derived_on_upgrade();
            $autoKeys = perm_automation_keys();

            $rows = $pdo->query('SELECT * FROM access_roles')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $byKey = [];
            $byName = [];
            foreach ($rows as $r) {
                if ((string) ($r['role_key'] ?? '') !== '') {
                    $byKey[(string) $r['role_key']] = $r;
                }
                $byName[(string) $r['name']] = $r;
            }

            // ۱) نقش‌های سیستمی: ساخت یا تطبیق
            foreach ($systemRoles as $key => $meta) {
                $row = $byKey[$key] ?? null;
                if (!$row && isset($byName[$meta['label']])) {
                    $candidate = $byName[$meta['label']];
                    $ck = (string) ($candidate['role_key'] ?? '');
                    if ($ck === '' || !isset($systemRoles[$ck])) {
                        // نقشِ سفارشی با همین نام → به نقشِ سیستمی تبدیل می‌شود (کاربرانش هم منتقل می‌شوند)
                        $row = $candidate;
                        $pdo->prepare('UPDATE access_roles SET role_key = ?, is_system = 1 WHERE id = ?')->execute([$key, (int) $row['id']]);
                        if ($ck !== '') {
                            try {
                                $col = $meta['kind'] === 'service' ? 'service_access_role' : 'role';
                                $pdo->prepare("UPDATE users SET `$col` = ? WHERE role = ?")->execute([$key, $ck]);
                            } catch (Throwable $e) {
                            }
                        }
                        $row['role_key'] = $key;
                        $row['permissions_baseline_version'] = 0;
                    }
                }
                if (!$row) {
                    $name = $meta['label'];
                    if (isset($byName[$name])) {
                        $name .= ' (سیستمی)';
                    }
                    $perms = perm_apply_role_restrictions($key, array_fill_keys(perm_role_defaults($key), true));
                    $pdo->prepare('INSERT INTO access_roles (name, description, permissions_json, role_key, is_system, permissions_initialized, permissions_baseline_version) VALUES (?,?,?,?,1,1,?)')
                        ->execute([$name, $meta['desc'], json_encode($perms, JSON_UNESCAPED_UNICODE), $key, PERM_BASELINE_VERSION]);
                    continue;
                }
                if ((int) ($row['is_system'] ?? 0) !== 1) {
                    $pdo->prepare('UPDATE access_roles SET is_system = 1 WHERE id = ?')->execute([(int) $row['id']]);
                }
                if ((int) ($row['permissions_baseline_version'] ?? 0) < PERM_BASELINE_VERSION) {
                    $old = json_decode((string) ($row['permissions_json'] ?? ''), true);
                    $old = is_array($old) ? $old : [];
                    $hadAny = false;
                    foreach ($old as $v) { if ($v) { $hadAny = true; break; } }

                    $perms = perm_normalize($old, true);
                    if (!$hadAny) {
                        $perms = array_fill_keys(perm_role_defaults($key), true);
                    } else {
                        foreach (perm_role_defaults($key) as $dk) {
                            if (in_array($dk, $newInThisVersion, true)) {
                                $perms[$dk] = true;
                            }
                        }
                    }
                    if ($key === 'leader') {
                        // گزارش‌های مدیریتیِ کلِ سازمان هیچ‌وقت برای سرپرست باز نبود؛ فقط با تیکِ دستی فعال شود
                        unset($perms['reports_view']);
                    }
                    if ($key === 'reception_agent') {
                        // مجوزهای مدیریتیِ پذیرش هیچ‌وقت برای نیروی پذیرش کار نمی‌کردند
                        foreach (array_keys($perms) as $pk) {
                            if (strpos($pk, 'admin_reception_') === 0) unset($perms[$pk]);
                        }
                    }
                    if ($key === 'super_admin') {
                        $perms = array_fill_keys(array_keys(perm_all_items()), true);
                    }
                    $perms = perm_apply_role_restrictions($key, $perms);
                    $pdo->prepare('UPDATE access_roles SET permissions_json = ?, permissions_initialized = 1, permissions_baseline_version = ? WHERE id = ?')
                        ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), PERM_BASELINE_VERSION, (int) $row['id']]);
                }
            }

            // ۲) نقش‌های سفارشی: مهاجرتِ کلیدها
            $rows = $pdo->query('SELECT * FROM access_roles WHERE COALESCE(is_system,0) = 0')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                if ((int) ($row['permissions_baseline_version'] ?? 0) >= PERM_BASELINE_VERSION) {
                    continue;
                }
                $old = json_decode((string) ($row['permissions_json'] ?? ''), true);
                $old = is_array($old) ? $old : [];
                $perms = perm_normalize($old, true);
                foreach ($derived as $if => $adds) {
                    if (!empty($perms[$if])) {
                        foreach ($adds as $a) $perms[$a] = true;
                    }
                }
                // قبلاً اتوماسیون مستقل از نقش بود؛ برای حفظِ رفتار، سقفِ پایه‌ی اتوماسیون داده می‌شود.
                foreach (perm_automation_basic() as $ak) {
                    $perms[$ak] = true;
                }
                if ($perms && empty($perms['help_view'])) {
                    $perms['help_view'] = true;
                }
                if (!empty($perms['customer_list_view']) || !empty($perms['customer_view'])) {
                    $perms['chat_view'] = true;
                }
                $pdo->prepare('UPDATE access_roles SET permissions_json = ?, permissions_initialized = 1, permissions_baseline_version = ? WHERE id = ?')
                    ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), PERM_BASELINE_VERSION, (int) $row['id']]);
            }

            $dir = dirname(perm_sync_flag_file());
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents(perm_sync_flag_file(), (string) time());
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['__perm_synced_v'] = PERM_BASELINE_VERSION;
            }
        } catch (Throwable $e) {
            error_log('perm_sync_roles: ' . $e->getMessage());
        }
    }
}

/* ------------------------------------------------------------------ */
/*  نگاشتِ صفحه ← مجوز                                                  */
/* ------------------------------------------------------------------ */

if (!function_exists('perm_page_map')) {
    function perm_page_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (perm_all_items() as $k => $it) {
            foreach ($it['pages'] as $p) {
                $map[$p][] = $k;
            }
        }
        // صفحاتی که با «هر یک از» چند مجوز باز می‌شوند
        $map['admin_dashboard.php']       = perm_admin_panel_keys();
        $map['reception_applicant.php']   = ['reception_agent_panel', 'admin_reception_candidates', 'admin_reception_inperson'];
        $map['reception_meetings.php']    = ['reception_agent_panel', 'admin_reception_hub', 'admin_reception_reports'];
        $map['reception_inperson.php']    = ['admin_reception_inperson', 'reception_agent_panel'];
        // مسیرِ پیگیریِ متقاضیان (قیفِ پذیرش)
        $map['reception_pipeline.php']        = ['reception_agent_panel', 'admin_reception_candidates', 'admin_reception_reports', 'admin_reception_staff'];
        $map['reception_pipeline_action.php'] = ['reception_agent_panel', 'reception_supervisor_meetings_view', 'admin_reception_candidates', 'admin_reception_reports', 'admin_reception_staff'];
        $map['admin_reception_funnel.php']    = ['admin_reception_reports'];
        $map['admin_reception_overview.php']  = ['admin_reception_reports']; // داشبوردِ واحدِ استخدام
        $map['reception_leaderboard.php']     = ['reception_agent_panel', 'admin_reception_reports', 'admin_reception_candidates', 'admin_reception_staff'];
        $map['quote_pdf.php']             = ['quotes_manage', 'finance_orders_view', 'orders_view_own'];
        // قرارداد
        $map['contract_view.php']         = ['quotes_manage', 'finance_orders_view', 'finance_orders_decide', 'orders_view_own'];
        $map['contract_print.php']        = ['quotes_manage', 'finance_orders_view', 'finance_orders_decide', 'orders_view_own'];
        $map['admin_contract_template.php'] = ['finance_settings'];
        $map['admin_aradbranding_ticket.php'] = ['finance_settings'];
        $map['admin_aradbranding_send.php'] = ['finance_orders_decide', 'finance_settings'];
        $map['admin_sales_import.php']      = ['finance_orders_decide'];
        $map['customer_credit.php']       = ['finance_orders_decide'];
        $map['admin_error_log.php']       = ['admin_system_update'];
        return $map;
    }
}

if (!function_exists('perm_current_script')) {
    function perm_current_script(): string
    {
        return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
    }
}

if (!function_exists('perm_page_required')) {
    /** null = صفحه‌ی آزاد (برای همه‌ی کاربرانِ واردشده) */
    function perm_page_required(?string $script = null): ?array
    {
        $script = $script ?? perm_current_script();
        $map = perm_page_map();
        return $map[$script] ?? null;
    }
}

if (!function_exists('user_can_any')) {
    function user_can_any(array $perms, ?array $u = null): bool
    {
        foreach ($perms as $p) {
            if (user_can((string) $p, $u)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('perm_page_allowed')) {
    function perm_page_allowed(?array $u = null, ?string $script = null): bool
    {
        $u = $u ?? current_user();
        if (!$u) {
            return false;
        }
        if (is_super_admin($u)) {
            return true;
        }
        $req = perm_page_required($script);
        if ($req === null) {
            return true;
        }
        return user_can_any($req, $u);
    }
}

if (!function_exists('perm_base_path')) {
    function perm_base_path(): string
    {
        return (strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false) ? '../' : '';
    }
}

if (!function_exists('perm_home_url')) {
    function perm_home_url(array $u): string
    {
        $b = perm_base_path();
        if (user_can('dashboard_view', $u)) return $b . 'dashboard.php';
        if (user_can('reception_agent_panel', $u)) return $b . 'reception_dashboard.php';
        if (user_can_any(perm_admin_panel_keys(), $u)) return $b . 'admin/admin_dashboard.php';
        if (user_can('customer_list_view', $u)) return $b . 'customer_list.php';
        return $b . 'profile.php';
    }
}

if (!function_exists('perm_deny')) {
    function perm_deny(string $message = '', ?array $u = null): void
    {
        $u = $u ?? current_user();
        http_response_code(403);
        $msg = $message !== '' ? $message : 'شما به این بخش دسترسی ندارید.';
        $req = perm_page_required();
        $need = '';
        if ($req) {
            $labels = array_map('perm_label', $req);
            $need = count($labels) > 3 ? 'یکی از مجوزهای بخشِ مدیریت' : implode(' / ', $labels);
        }
        $home = $u ? perm_home_url($u) : 'login.php';
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>دسترسی غیرمجاز</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css">'
            . '<style>body{margin:0;font-family:Vazirmatn,Tahoma,sans-serif;background:#f7f5ef;color:#1c1917;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}'
            . '.box{max-width:460px;width:100%;background:#fff;border:1px solid #e7e2d3;border-radius:18px;padding:28px;text-align:center;box-shadow:0 12px 30px -20px rgba(0,0,0,.35)}'
            . '.ic{width:58px;height:58px;border-radius:16px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:26px;background:linear-gradient(135deg,#0b0f1a,#3d3220 55%,#c9a24b 130%);color:#f1dfa8}'
            . 'h1{font-size:18px;margin:0 0 8px}p{color:#78716c;font-size:13px;line-height:2;margin:0 0 6px}.need{display:inline-block;background:#faf5e6;border:1px solid #efe1b5;border-radius:10px;padding:4px 10px;font-size:12px;color:#7a5e24;margin:6px 0 14px}'
            . 'a{display:inline-block;text-decoration:none;background:linear-gradient(135deg,#f1dfa8,#c9a24b);color:#241708;font-weight:700;border-radius:10px;padding:9px 18px;font-size:13px}</style></head><body>'
            . '<div class="box"><div class="ic">&#128274;</div><h1>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>اگر فکر می‌کنید باید به این بخش دسترسی داشته باشید، از مدیرِ سیستم بخواهید مجوزِ مربوطه را در «نقش‌ها و دسترسی‌ها» برای نقشِ شما فعال کند.</p>'
            . ($need !== '' ? '<div class="need">مجوزِ لازم: ' . htmlspecialchars($need, ENT_QUOTES, 'UTF-8') . '</div><br>' : '')
            . '<a href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">بازگشت</a></div></body></html>';
        exit;
    }
}

if (!function_exists('perm_enforce_page')) {
    function perm_enforce_page(array $u): void
    {
        if (perm_page_allowed($u)) {
            return;
        }
        // اگر داشبورد مجاز نیست، به اولین صفحه‌ی مجاز هدایت شود (مثلاً نیروی پذیرش)
        if (perm_current_script() === 'dashboard.php') {
            $home = perm_home_url($u);
            if (basename($home) !== 'dashboard.php' && !headers_sent()) {
                header('Location: ' . $home);
                exit;
            }
        }
        perm_deny('', $u);
    }
}

/* ------------------------------------------------------------------ */
/*  سازگاری با کدهای قدیمی که تابعِ مجوزِ اختصاصیِ خودشان را داشتند      */
/* ------------------------------------------------------------------ */

if (!function_exists('cp_permission_allowed')) {
    function cp_permission_allowed(array $user, string $permission): bool
    {
        return user_can($permission, $user);
    }
}
if (!function_exists('cl_permission_allowed')) {
    function cl_permission_allowed(array $user, string $permission): bool
    {
        return user_can($permission, $user);
    }
}

/* ------------------------------------------------------------------ */
/*  توضیحِ نقشِ کاربر (برای نمایش)                                     */
/* ------------------------------------------------------------------ */

if (!function_exists('perm_role_label')) {
    function perm_role_label(?string $key): string
    {
        $key = (string) $key;
        if ($key === '') return '—';
        $sys = perm_system_roles();
        if (isset($sys[$key])) return $sys[$key]['label'];
        static $custom = null;
        if ($custom === null) {
            $custom = [];
            try {
                foreach (db()->query("SELECT role_key, name FROM access_roles WHERE role_key IS NOT NULL AND role_key <> ''") as $r) {
                    $custom[(string) $r['role_key']] = (string) $r['name'];
                }
            } catch (Throwable $e) {
            }
        }
        return $custom[$key] ?? $key;
    }
}

// =====================================================================
// حفاظت از ادمینِ کل و نقشِ «ادمین»
//   - فقط ادمینِ کل می‌تواند کاربرِ با نقشِ ادمین بسازد یا نقشِ ادمین بدهد.
//   - ادمین‌های دیگر ادمینِ کل (و نقشِ «ادمین کل») را اصلاً نمی‌بینند.
//   - ادمین‌های دیگر نمی‌توانند حسابِ ادمین‌های دیگر را تغییر دهند.
// =====================================================================
if (!function_exists('user_is_super_target')) {
    function user_is_super_target(array $u): bool
    {
        return !empty($u['is_super_admin']) || ($u['role'] ?? '') === 'super_admin';
    }
}
if (!function_exists('user_is_admin_level')) {
    function user_is_admin_level(array $u): bool
    {
        return user_is_super_target($u) || in_array((string) ($u['role'] ?? ''), ['admin', 'super_admin'], true);
    }
}
if (!function_exists('actor_is_super')) {
    function actor_is_super(array $actor): bool
    {
        return function_exists('is_super_admin') && is_super_admin($actor);
    }
}
/** نقش‌هایی که فقط ادمینِ کل می‌تواند بدهد */
if (!function_exists('actor_can_assign_role')) {
    function actor_can_assign_role(array $actor, string $role): bool
    {
        return actor_is_super($actor) || !in_array($role, ['admin', 'super_admin'], true);
    }
}
/** آیا این کاربر می‌تواند حسابِ کاربرِ هدف را ببیند/تغییر دهد؟ */
if (!function_exists('actor_can_manage_user')) {
    function actor_can_manage_user(array $actor, array $target): bool
    {
        if (actor_is_super($actor)) {
            return true;
        }
        if (user_is_super_target($target)) {
            return false;
        }
        if (user_is_admin_level($target) && (int) ($target['id'] ?? 0) !== (int) ($actor['id'] ?? -1)) {
            return false;
        }
        return true;
    }
}
if (!function_exists('actor_can_see_user')) {
    function actor_can_see_user(array $actor, array $target): bool
    {
        return actor_is_super($actor) || !user_is_super_target($target);
    }
}
/** شرطِ SQL برای پنهان‌کردنِ ادمینِ کل از دیدِ بقیه */
if (!function_exists('users_visibility_sql')) {
    function users_visibility_sql(array $actor, string $alias = ''): string
    {
        if (actor_is_super($actor)) {
            return '1=1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "(COALESCE({$p}is_super_admin, 0) = 0 AND COALESCE({$p}role, '') <> 'super_admin')";
    }
}
