<?php
/**
 * Questions can target a whole group (all of its levels), not only one level: questions.group_id.
 * Existing questions with a level get the group of that level.
 */
return [
    'description' => 'سطح سؤال: امکان انتخاب کل گروه (همه سطوح آن گروه)',
    'up' => function (PDO $db): void {
        $has = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND COLUMN_NAME = 'group_id'")->fetchColumn();
        if (!$has) $db->exec('ALTER TABLE questions ADD COLUMN group_id INT UNSIGNED NULL AFTER level_id, ADD KEY idx_q_group (group_id)');
        $db->exec('UPDATE questions q JOIN levels l ON l.id = q.level_id SET q.group_id = l.group_id WHERE q.group_id IS NULL');
    },
];
