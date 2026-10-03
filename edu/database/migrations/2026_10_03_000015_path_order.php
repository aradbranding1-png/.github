<?php
/** learning_paths.sort — the order paths are shown in (admin drag & drop, learner roadmap). Backfilled oldest first. */
return [
    'description' => 'ترتیب مسیرهای آموزشی (کشیدن و رها کردن) و نمایش نقشه راه',
    'up' => function (PDO $db): void {
        if (!$db->query("SHOW COLUMNS FROM learning_paths LIKE 'sort'")->fetch()) {
            $db->exec('ALTER TABLE learning_paths ADD COLUMN sort INT NOT NULL DEFAULT 0, ADD KEY idx_lp_sort (sort, id)');
            $db->exec('SET @n := 0');
            $db->exec('UPDATE learning_paths SET sort = (@n := @n + 1) ORDER BY id');
        }
    },
];
