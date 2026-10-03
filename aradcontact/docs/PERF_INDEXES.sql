-- ایندکس‌های سرعت (آراد کانتکت، نسخه‌ی ۵۰)
-- معمولاً لازم نیست این فایل را اجرا کنید: سامانه بارِ اول خودش پشتِ صحنه این‌ها را می‌سازد
-- (includes/perf_indexes.php). اگر خواستید دستی و زودتر بسازید، در phpMyAdmin اجرا کنید.
-- بعد از اجرای دستی، فایلِ خالیِ storage/.perf_indexes_v1 را بسازید.

ALTER TABLE customers ADD COLUMN cl_sort BIGINT AS ((CASE `status` WHEN 'در انتظار پرداخت' THEN 1 WHEN 'در انتظار تصمیم' THEN 2 WHEN 'جلسه برگزار شد' THEN 3 WHEN 'در حال پیگیری' THEN 4 WHEN 'تعویق' THEN 5 WHEN 'جدید' THEN 6 WHEN 'عدم پاسخ' THEN 7 WHEN 'مشتری قدیمی' THEN 8 WHEN 'خرید کرده' THEN 9 ELSE 10 END) * 100000000000000000 + LEAST(GREATEST(IFNULL(TO_DAYS(`next_followup_date`) - 693961, 999999), 0), 999999) * 100000000000 + (99999999999 - TO_SECONDS(`created_at`))) VIRTUAL INVISIBLE;

ALTER TABLE customers ADD INDEX idx_cust_owner_scope (owner_user_id, contact_type, status, next_followup_date, cl_sort), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE customers ADD INDEX idx_cust_type_sort (contact_type, cl_sort, status, owner_user_id, next_followup_date), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE customers ADD INDEX idx_cust_type_status (contact_type, status, next_followup_date, created_at), ALGORITHM=INPLACE, LOCK=NONE;
ALTER TABLE followups ADD INDEX idx_followups_day_calls (followup_date, source, call_duration_seconds, created_by, customer_id), ALGORITHM=INPLACE, LOCK=NONE;
-- نسخه‌ی ۲ (اولین ارتباطِ هر مشتری برای «لید»):
ALTER TABLE followups ADD INDEX idx_followups_cust_date (customer_id, followup_date, created_by), ALGORITHM=INPLACE, LOCK=NONE;
-- بعد از اجرای دستی، فایلِ خالیِ storage/.perf_indexes_v2 را بسازید.
