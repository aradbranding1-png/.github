<?php
/**
 * Default data: roles with permission sets, the three main groups, levels, org unit types,
 * merchant/agent classifications, merchant learning topics and default growth stages.
 * All of it is editable from the admin panel afterwards.
 */
return [
    'description' => 'داده‌های پیش‌فرض: نقش‌ها و دسترسی‌ها، گروه‌های اصلی، سطوح، ساختار سازمانی، موضوعات آموزشی',
    'up' => function (PDO $db): void {
        $now = date('Y-m-d H:i:s');
        $ins = function (string $sql, array $p) use ($db): int {
            $st = $db->prepare($sql);
            $st->execute($p);
            return (int)$db->lastInsertId();
        };
        $exists = fn(string $sql, array $p) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();

        // ---------------------------------------------------------------- permissions registry
        $reg = require dirname(__DIR__, 2) . '/app/Config/permissions.php';
        $all = [];
        foreach ($reg['modules'] as $m => $def) foreach ($def['actions'] as $a) {
            $k = "$m.$a";
            $all[$k] = true;
            if (!$exists('SELECT 1 FROM permissions WHERE `key` = ?', [$k])) {
                $ins('INSERT INTO permissions (`key`, module, action, label, root_only) VALUES (?,?,?,?,?)', [$k, $m, $a, $def['label'] . ' — ' . ($reg['actions'][$a] ?? $a), in_array($k, $reg['root_only'], true) ? 1 : 0]);
            }
        }
        $grantable = array_keys(array_diff_key($all, array_flip($reg['root_only'])));
        $mod = function (array $modules, ?array $actions = null) use ($reg): array {
            $o = [];
            foreach ($modules as $m) foreach ($reg['modules'][$m]['actions'] as $a) {
                if ($actions === null || in_array($a, $actions, true)) $o[] = "$m.$a";
            }
            return $o;
        };
        $pick = fn(array $keys) => array_values(array_filter($keys, fn($k) => isset($all[$k]) && !in_array($k, $reg['root_only'], true)));

        // ---------------------------------------------------------------- roles
        $roles = [
            ['root_admin', 'مدیر کل', 'سازنده و مالک سامانه با بالاترین سطح دسترسی (تیک آبی). غیرقابل حذف و ویرایش توسط دیگران.', 'root', 'all', 1, 1, 0, []],
            ['system_admin', 'مدیر سیستم', 'مدیریت کامل سامانه به جز بروزرسانی، اجرای Migration، بازیابی پشتیبان و ورود به حساب کاربران.', 'danger', 'all', 1, 0, 0, $grantable],
            ['edu_manager', 'مدیر آموزش', 'مدیریت کامل ساختار آموزشی، دوره‌ها، آزمون‌ها، تخصیص، نظام رشد و گزارش‌ها.', 'purple', 'all', 1, 0, 0, $pick(array_merge(
                $mod(['dashboard', 'team', 'groups', 'levels', 'categories', 'courses', 'lessons', 'library', 'paths', 'exams', 'questions', 'reviews', 'evaluations', 'certificates', 'assignments', 'rules', 'growth', 'needs', 'reports', 'notifications']),
                ['users.view', 'users.create', 'users.edit', 'users.assign', 'users.report', 'users.export', 'org.view', 'org.assign', 'audit.view', 'roles.view']
            ))],
            ['edu_expert', 'کارشناس آموزش', 'تولید و ویرایش محتوا، آزمون و بانک سؤال، بررسی تمرین‌ها و تخصیص آموزش (بدون انتشار و حذف).', 'info', 'all', 1, 0, 0, $pick(array_merge(
                $mod(['courses', 'lessons', 'library', 'paths', 'exams', 'questions'], ['view', 'create', 'edit', 'report']),
                $mod(['reviews']), $mod(['evaluations'], ['view', 'create']), $mod(['needs'], ['view', 'create', 'edit']),
                ['dashboard.view', 'users.view', 'users.report', 'categories.view', 'certificates.view', 'assignments.view', 'assignments.assign', 'rules.view', 'growth.view', 'reports.view', 'reports.report', 'groups.view', 'levels.view']
            ))],
            ['edu_supervisor', 'مسئول آموزش', 'پایش و پیگیری آموزش افراد تحت مسئولیت؛ تخصیص آموزش، بررسی تمرین‌ها و ثبت ارزیابی عملی برای تیم خود.', 'warning', 'supervised', 1, 0, 0, $pick(array_merge(
                $mod(['team', 'reviews']), $mod(['evaluations'], ['view', 'create']), $mod(['needs'], ['view', 'create', 'edit']),
                ['users.view', 'users.report', 'assignments.view', 'assignments.assign', 'growth.view', 'growth.approve', 'reports.view', 'reports.report', 'reports.export', 'courses.view', 'certificates.view']
            ))],
            ['instructor', 'مدرس', 'مدیریت دوره‌های خود: درس‌ها، آزمون‌ها، بانک سؤال، تصحیح تمرین‌ها و ارزیابی عملی.', 'success', 'own', 1, 0, 0, $pick(array_merge(
                $mod(['lessons'], ['view', 'create', 'edit']), $mod(['exams'], ['view', 'create', 'edit', 'report']), $mod(['questions'], ['view', 'create', 'edit']),
                $mod(['reviews']), $mod(['evaluations'], ['view', 'create']),
                ['courses.view', 'courses.edit', 'courses.report', 'library.view', 'library.create', 'certificates.view', 'reports.view']
            ))],
            ['report_viewer', 'ناظر گزارش‌ها', 'مشاهده داشبورد، گزارش‌ها و خروجی Excel بدون امکان تغییر.', 'dark', 'all', 1, 0, 0, $pick(array_merge(
                $mod(['reports']), ['dashboard.view', 'users.view', 'users.report', 'courses.view', 'courses.report', 'exams.view', 'exams.report', 'audit.view', 'team.view', 'team.report']
            ))],
            ['support', 'پشتیبان کاربران', 'مدیریت حساب کاربران، عضویت در گروه‌ها، ثبت نیاز آموزشی و ارسال اعلان.', 'gray', 'all', 1, 0, 0, $pick(array_merge(
                ['dashboard.view', 'users.view', 'users.create', 'users.edit', 'users.assign', 'groups.view', 'groups.assign', 'org.view', 'org.assign'],
                $mod(['needs'], ['view', 'create', 'edit']), $mod(['notifications'])
            ))],
            ['merchant', 'تاجر', 'نقش فراگیر برای تاجران (پنل یادگیری).', 'primary', 'own', 1, 0, 1, []],
            ['employee', 'کارمند', 'نقش فراگیر برای کارمندان (پنل یادگیری).', 'primary', 'own', 1, 0, 1, []],
            ['agent', 'نماینده', 'نقش فراگیر برای نمایندگان (پنل یادگیری).', 'primary', 'own', 1, 0, 1, []],
        ];
        $sort = 0;
        foreach ($roles as [$slug, $name, $desc, $color, $scope, $sys, $root, $learner, $perms]) {
            $sort++;
            $rid = $exists('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if (!$rid) {
                $rid = $ins('INSERT INTO roles (slug, name, description, color, data_scope, is_system, is_root, is_learner, sort, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$slug, $name, $desc, $color, $scope, $sys, $root, $learner, $sort, $now]);
                foreach (array_unique($perms) as $p) $ins('INSERT INTO role_permissions (role_id, permission_key) VALUES (?,?)', [$rid, $p]);
            }
        }

        // ---------------------------------------------------------------- main groups & levels
        $groups = [
            ['merchant', 'تاجران', 'store', '#4f46e5', ['مبتدی', 'متوسط', 'پیشرفته', 'حرفه‌ای']],
            ['employee', 'کارمندان', 'briefcase', '#0891b2', ['کارآموز', 'کارشناس', 'کارشناس ارشد', 'مدیر']],
            ['agent', 'نمایندگان', 'globe', '#d97706', ['تازه‌کار', 'فعال', 'ارشد', 'ممتاز']],
        ];
        $colors = ['#94a3b8', '#0ea5e9', '#8b5cf6', '#f59e0b'];
        $gids = [];
        foreach ($groups as $i => [$seg, $name, $icon, $color, $levels]) {
            $gid = $exists('SELECT id FROM `groups` WHERE segment = ? AND is_system = 1 AND parent_id IS NULL', [$seg]);
            if (!$gid) {
                $gid = $ins('INSERT INTO `groups` (segment, name, icon, color, is_system, sort, created_at) VALUES (?,?,?,?,1,?,?)', [$seg, $name, $icon, $color, $i + 1, $now]);
                foreach ($levels as $r => $ln) $ins('INSERT INTO levels (group_id, name, rank_no, color, created_at) VALUES (?,?,?,?,?)', [$gid, $ln, $r + 1, $colors[$r] ?? '#0ea5e9', $now]);
            }
            $gids[$seg] = (int)$gid;
        }

        // ---------------------------------------------------------------- org structure types
        if (!$exists('SELECT COUNT(*) FROM org_unit_types', [])) {
            foreach ([['معاونت', 'building-2'], ['واحد', 'layers'], ['بخش', 'layout-grid'], ['سمت', 'user-cog']] as $i => [$n, $ic]) {
                $ins('INSERT INTO org_unit_types (name, sort, icon, created_at) VALUES (?,?,?,?)', [$n, $i + 1, $ic, $now]);
            }
        }

        // ---------------------------------------------------------------- classifications
        $tax = [
            'merchant' => [['stage', 'مرحله', ['شروع', 'در حال رشد', 'تثبیت‌شده']], ['activity', 'نوع فعالیت', ['تولیدی', 'بازرگانی', 'خدماتی']], ['market', 'بازار', ['داخلی', 'صادراتی']], ['product', 'محصول', []], ['edu_group', 'گروه آموزشی', []]],
            'agent' => [['country', 'کشور', []], ['region', 'منطقه', []], ['market', 'بازار', []], ['agency_type', 'نوع نمایندگی', ['انحصاری', 'عمومی']], ['agency_level', 'سطح نمایندگی', ['سطح یک', 'سطح دو', 'سطح سه']]],
        ];
        foreach ($tax as $seg => $list) foreach ($list as $i => [$slug, $name, $terms]) {
            if ($exists('SELECT 1 FROM taxonomies WHERE segment = ? AND slug = ?', [$seg, $slug])) continue;
            $tid = $ins('INSERT INTO taxonomies (segment, slug, name, sort, created_at) VALUES (?,?,?,?,?)', [$seg, $slug, $name, $i + 1, $now]);
            foreach ($terms as $j => $t) $ins('INSERT INTO taxonomy_terms (taxonomy_id, name, sort, created_at) VALUES (?,?,?,?)', [$tid, $t, $j + 1, $now]);
        }

        // ---------------------------------------------------------------- merchant learning topics
        if (!$exists('SELECT COUNT(*) FROM categories', [])) {
            $topics = [
                ['مبانی تجارت', 'store', '#4f46e5'], ['مذاکره', 'message-square', '#7c3aed'], ['مشتری‌یابی', 'target', '#db2777'],
                ['ارتباط با مشتری', 'users', '#0891b2'], ['فروش', 'trending-up', '#16a34a'], ['تولید محتوا', 'video', '#ea580c'],
                ['برندینگ', 'sparkles', '#9333ea'], ['صادرات', 'globe', '#0284c7'], ['لجستیک', 'package', '#65a30d'],
                ['بسته‌بندی', 'box', '#ca8a04'], ['توسعه بازار', 'rocket', '#e11d48'], ['امور مالی تجارت', 'chart-pie', '#0d9488'],
                ['سایر مهارت‌های تاجر', 'lightbulb', '#64748b'],
            ];
            foreach ($topics as $i => [$n, $ic, $c]) $ins('INSERT INTO categories (segment, name, icon, color, sort, created_at) VALUES (?,?,?,?,?,?)', ['merchant', $n, $ic, $c, $i + 1, $now]);
            $ins('INSERT INTO categories (segment, name, icon, color, sort, created_at) VALUES (?,?,?,?,?,?)', ['employee', 'آموزش‌های سازمانی', 'building-2', '#0891b2', 20, $now]);
            $ins('INSERT INTO categories (segment, name, icon, color, sort, created_at) VALUES (?,?,?,?,?,?)', ['agent', 'آموزش‌های نمایندگی', 'globe', '#d97706', 30, $now]);
        }

        if (!$exists('SELECT COUNT(*) FROM question_categories', [])) {
            $ins('INSERT INTO question_categories (name, created_at) VALUES (?,?)', ['عمومی', $now]);
        }

        // ---------------------------------------------------------------- default growth stages
        if (!$exists('SELECT COUNT(*) FROM growth_stages', [])) {
            $stages = [
                'merchant' => [['تاجر نوپا', null, null, 0, '#94a3b8', 'flag'], ['تاجر فعال', 40, null, 0, '#0ea5e9', 'trending-up'], ['تاجر حرفه‌ای', 70, 70, 0, '#8b5cf6', 'rocket'], ['تاجر برتر', 90, 80, 1, '#f59e0b', 'crown']],
                'employee' => [['آشنایی', null, null, 0, '#94a3b8', 'flag'], ['توانمند', 50, 60, 0, '#0ea5e9', 'zap'], ['متخصص', 85, 75, 1, '#8b5cf6', 'medal']],
                'agent' => [['نماینده جدید', null, null, 0, '#94a3b8', 'flag'], ['نماینده فعال', 50, 60, 0, '#0ea5e9', 'globe'], ['نماینده ممتاز', 85, 80, 1, '#f59e0b', 'crown']],
            ];
            foreach ($stages as $seg => $list) foreach ($list as $i => [$n, $prog, $score, $eval, $c, $ic]) {
                $ins('INSERT INTO growth_stages (group_id, name, sort, color, icon, req_min_progress, req_min_avg_score, req_evaluation, created_at) VALUES (?,?,?,?,?,?,?,?,?)', [$gids[$seg], $n, $i + 1, $c, $ic, $prog, $score, $eval, $now]);
            }
        }
    },
];
