<?php
/**
 * Course audience becomes multi-select:
 *  - course_audience : groups (main groups تاجران/کارمندان/نمایندگان and/or sub-groups) that may see the course; none = everyone
 *  - course_levels   : levels that may see the course; none = every level
 * Existing single values (target_segment / group_id / level_id) are carried over.
 */
return [
    'description' => 'مخاطبان و سطح چندانتخابی برای دوره‌ها',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $db->exec("CREATE TABLE IF NOT EXISTS course_audience (
            course_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (course_id, group_id),
            KEY idx_ca_group (group_id)
        )$T");
        $db->exec("CREATE TABLE IF NOT EXISTS course_levels (
            course_id INT UNSIGNED NOT NULL,
            level_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (course_id, level_id),
            KEY idx_cl_level (level_id)
        )$T");
        $main = [];
        foreach ($db->query('SELECT id, segment FROM `groups` WHERE is_system = 1 AND parent_id IS NULL ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) as $g) $main[$g['segment']] = (int)$g['id'];
        $ins = $db->prepare('INSERT IGNORE INTO course_audience (course_id, group_id) VALUES (?, ?)');
        $insL = $db->prepare('INSERT IGNORE INTO course_levels (course_id, level_id) VALUES (?, ?)');
        foreach ($db->query('SELECT id, target_segment, group_id, level_id FROM courses')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int)$c['id'];
            // the specific group is the narrower rule; the segment's main group only when no group was chosen
            if ($c['group_id']) $ins->execute([$id, (int)$c['group_id']]);
            elseif ($c['target_segment'] && isset($main[$c['target_segment']])) $ins->execute([$id, $main[$c['target_segment']]]);
            if ($c['level_id']) $insL->execute([$id, (int)$c['level_id']]);
        }
    },
];
