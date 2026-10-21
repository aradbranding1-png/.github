<?php
/**
 * New permission «دوره‌ها — مشاهده همه» (courses.viewall): sees every course regardless of its audience/level,
 * in the learner catalog too. Granted to the management / education roles; editable per role afterwards.
 */
return [
    'description' => 'مجوز «دوره‌ها — مشاهده همه» برای نقش‌های مدیریتی و آموزشی',
    'up' => function (PDO $db): void {
        $db->exec("INSERT IGNORE INTO permissions (`key`, module, action, label, root_only) VALUES ('courses.viewall', 'courses', 'viewall', 'دوره‌ها — مشاهده همه', 0)");
        $slugs = ['system_admin', 'edu_manager', 'edu_expert', 'edu_supervisor', 'report_viewer', 'support'];
        $names = ['مدیر سیستم', 'مدیر آموزش', 'کارشناس آموزش', 'مسئول آموزش', 'ناظر گزارش‌ها', 'پشتیبان کاربران'];
        $norm = fn(string $s): string => preg_replace('/[\s\x{200C}]+/u', ' ', trim(strtr($s, ['ي' => 'ی', 'ك' => 'ک'])));
        $want = array_map($norm, $names);
        $ins = $db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_key) VALUES (?, 'courses.viewall')");
        foreach ($db->query('SELECT id, slug, name FROM roles')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array($r['slug'], $slugs, true) || in_array($norm((string)$r['name']), $want, true)) $ins->execute([(int)$r['id']]);
        }
    },
];
