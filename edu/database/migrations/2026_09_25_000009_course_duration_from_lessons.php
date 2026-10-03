<?php
/** Course duration = sum of its published lessons' durations (existing courses recalculated once) */
return [
    'description' => 'محاسبه خودکار مدت دوره‌ها از مجموع مدت درس‌های منتشرشده',
    'up' => function (PDO $db): void {
        $db->exec("UPDATE courses c JOIN (SELECT course_id, SUM(duration_minutes) m FROM lessons WHERE deleted_at IS NULL AND status = 'published' AND duration_minutes > 0 GROUP BY course_id) t ON t.course_id = c.id SET c.duration_minutes = t.m");
    },
];
