<?php
/**
 * Repair for 1.0.55–1.0.58: lessons cancelled as «completed without watching» left the course itself marked
 * completed (100 %), with its certificate and learning-path step. Reopen those courses now.
 */
return [
    'description' => 'بازگشایی دوره‌هایی که درس آن‌ها به‌دلیل تکمیل مشکوک لغو شده بود',
    'up' => function (PDO $db): void {
        if (!$db->query("SHOW TABLES LIKE 'lesson_completion_flags'")->fetch()) return;
        // the scan (nightly / «بررسی همه تکمیل‌ها») repeats this repair, so an old class still loaded in this request is harmless
        if (method_exists(\App\Services\CompletionAudit::class, 'repairResets')) \App\Services\CompletionAudit::repairResets();
    },
];
