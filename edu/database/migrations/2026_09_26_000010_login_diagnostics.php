<?php
/** Sign-in diagnostics: reason of each failed login + one stored form (09…) for Iranian mobile numbers */
return [
    'description' => 'ثبت علت ورود ناموفق و یکسان‌سازی قالب شماره موبایل کاربران',
    'up' => function (PDO $db): void {
        if (!$db->query("SHOW COLUMNS FROM `login_history` LIKE 'reason'")->fetch()) {
            $db->exec("ALTER TABLE login_history ADD COLUMN reason VARCHAR(30) NULL AFTER success");
        }
        // 912…, +98912…, 98912…, 0098912… → 0912…  (skipped when another account already has that number)
        $rows = $db->query("SELECT id, mobile FROM users WHERE mobile IS NOT NULL AND mobile <> '' AND mobile NOT REGEXP '^09[0-9]{9}$'")->fetchAll(PDO::FETCH_ASSOC);
        $chk = $db->prepare('SELECT 1 FROM users WHERE mobile = ? AND id <> ?');
        $upd = $db->prepare('UPDATE users SET mobile = ? WHERE id = ?');
        foreach ($rows as $r) {
            $d = preg_replace('/[\s\-\(\)]/', '', strtr((string)$r['mobile'], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
            $n = ltrim($d, '+');
            if (str_starts_with($n, '0098')) $n = substr($n, 4);
            elseif (str_starts_with($n, '98') && strlen($n) === 12) $n = substr($n, 2);
            elseif (str_starts_with($n, '0')) $n = substr($n, 1);
            if (!preg_match('/^9\d{9}$/', $n)) continue;
            $c = '0' . $n;
            $chk->execute([$c, (int)$r['id']]);
            if ($chk->fetchColumn()) continue;
            $upd->execute([$c, (int)$r['id']]);
        }
    },
];
