<?php
/** Exam attempt limit is now per day: nobody stays "failed" because of the old lifetime limit. */
return [
    'description' => 'محدودیت دفعات آزمون به صورت روزانه (رفع وضعیت «مردود» ناشی از سقف قبلی)',
    'up' => function (PDO $db): void {
        $db->exec("UPDATE enrollments SET status = 'needs_retake', updated_at = NOW() WHERE status = 'failed'");
        $db->exec("CREATE INDEX idx_att_started ON exam_attempts (exam_id, user_id, started_at)");
    },
];
