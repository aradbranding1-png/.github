<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  مدارکِ مشتری (کارت ملی، آدرس، کدپستی) + پرداخت‌ها، اقساط و مطالبات
 * ═══════════════════════════════════════════════════════════════════════
 *  - customer_kyc              : کارت ملی/کد ملی/آدرس/کدپستیِ هر مشتری (یک‌بار ثبت، قابل ویرایش)
 *  - sales_order_payments      : همه‌ی پرداخت‌های یک سفارش (پیش‌پرداخت + پرداخت‌های بعدی)
 *                                 هر پرداخت باید توسطِ واحد مالی «تأیید» شود تا از بدهی کم شود.
 *  - sales_order_installments  : سررسیدِ اقساطِ باقی‌مانده‌ی هر سفارش
 *
 *  مانده‌ی بدهی = مبلغِ فاکتور − جمعِ پرداخت‌های تأییدشده (فقط برای سفارش‌های تأییدشده).
 *  وضعیتِ هر قسط به‌صورتِ پویا محاسبه می‌شود: پرداخت‌های تأییدشده ابتدا «پیش‌پرداخت» را
 *  پوشش می‌دهند و مابقی به ترتیبِ سررسید روی اقساط می‌نشیند.
 */

if (!function_exists('finance_schema_ready')) {
    function finance_schema_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $flag = __DIR__ . '/../storage/.finance_schema_v2';
        if (is_file($flag)) {
            return $ready = true;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `customer_kyc` (
                `customer_id` INT UNSIGNED NOT NULL,
                `national_id` VARCHAR(10) DEFAULT NULL,
                `card_path` VARCHAR(255) DEFAULT NULL,
                `card_mime` VARCHAR(80) DEFAULT NULL,
                `card_original_name` VARCHAR(255) DEFAULT NULL,
                `card_uploaded_by` INT UNSIGNED DEFAULT NULL,
                `card_uploaded_at` DATETIME DEFAULT NULL,
                `card_verified_by` INT UNSIGNED DEFAULT NULL,
                `card_verified_at` DATETIME DEFAULT NULL,
                `address` TEXT,
                `postal_code` VARCHAR(10) DEFAULT NULL,
                `updated_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`customer_id`),
                KEY `idx_kyc_national` (`national_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_order_payments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `customer_id` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(20) NOT NULL DEFAULT 'extra',
                `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `paid_at` DATE DEFAULT NULL,
                `method` VARCHAR(30) DEFAULT NULL,
                `ref` VARCHAR(100) DEFAULT NULL,
                `note` VARCHAR(500) DEFAULT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `recorded_by` INT UNSIGNED DEFAULT NULL,
                `decided_by` INT UNSIGNED DEFAULT NULL,
                `decided_at` DATETIME DEFAULT NULL,
                `decision_note` VARCHAR(500) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_sop_order` (`order_id`),
                KEY `idx_sop_customer` (`customer_id`),
                KEY `idx_sop_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_order_installments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `customer_id` INT UNSIGNED NOT NULL,
                `seq` INT UNSIGNED NOT NULL DEFAULT 1,
                `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `due_date` DATE NOT NULL,
                `note` VARCHAR(255) DEFAULT NULL,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_soi2_order` (`order_id`),
                KEY `idx_soi2_customer` (`customer_id`),
                KEY `idx_soi2_due` (`due_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // فیش‌ها می‌توانند به یک پرداختِ مشخص وصل باشند
            try {
                $cols = $pdo->query('SHOW COLUMNS FROM sales_order_files')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                if ($cols && !in_array('payment_id', $cols, true)) {
                    $pdo->exec('ALTER TABLE sales_order_files ADD COLUMN payment_id INT UNSIGNED DEFAULT NULL');
                }
            } catch (Throwable $e) {
            }
            // سفارش‌هایی که قبل از این نسخه ثبت شده‌اند: پیش‌پرداختشان به جدولِ پرداخت‌ها منتقل می‌شود
            try {
                $pdo->exec("INSERT INTO sales_order_payments (order_id, customer_id, kind, amount, paid_at, method, ref, status, recorded_by, decided_by, decided_at, created_at)
                    SELECT o.id, o.customer_id, 'initial',
                           CASE WHEN o.status = 'approved' THEN COALESCE(o.confirmed_amount, o.paid_amount) ELSE o.paid_amount END,
                           o.payment_date, o.payment_method, o.payment_ref,
                           CASE o.status WHEN 'approved' THEN 'confirmed' WHEN 'rejected' THEN 'rejected' WHEN 'cancelled' THEN 'rejected' ELSE 'pending' END,
                           o.seller_user_id, o.finance_user_id, o.decided_at, o.created_at
                    FROM sales_orders o
                    WHERE NOT EXISTS (SELECT 1 FROM sales_order_payments p WHERE p.order_id = o.id AND p.kind = 'initial')");
            } catch (Throwable $e) {
            }
            if (!is_dir(dirname($flag))) {
                @mkdir(dirname($flag), 0755, true);
            }
            @file_put_contents($flag, (string) time());
            $ready = true;
        } catch (Throwable $e) {
            error_log('finance_schema_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

/* ------------------------------------------------------------------ */
/*  مدارکِ مشتری                                                        */
/* ------------------------------------------------------------------ */

if (!function_exists('kyc_valid_national_id')) {
    /** اعتبارسنجیِ کدِ ملیِ ایران (۱۰ رقم + رقمِ کنترل) */
    function kyc_valid_national_id(string $code): bool
    {
        $code = preg_replace('/\D/', '', normalize_digits($code));
        if (!preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $r = $sum % 11;
        $c = (int) $code[9];
        return ($r < 2 && $c === $r) || ($r >= 2 && $c === 11 - $r);
    }
}

if (!function_exists('kyc_id_types')) {
    /** نوعِ مدرکِ هویتی: ایرانی (کد ملی) یا اتباع (کد فراگیر / پاسپورت) */
    function kyc_id_types(): array
    {
        return ['national' => 'کد ملی', 'fida' => 'کد فراگیر اتباع', 'passport' => 'شماره پاسپورت'];
    }

    /** برچسبِ شماره‌ی هویتیِ یک پرونده (برای نمایش و متن‌ها) */
    function kyc_id_label(?array $kyc): string
    {
        $t = (string) ($kyc['id_type'] ?? 'national');
        return kyc_id_types()[$t] ?? 'کد ملی';
    }

    /** برچسبِ تصویرِ مدرک */
    function kyc_card_label(?array $kyc): string
    {
        $t = (string) ($kyc['id_type'] ?? 'national');
        return $t === 'passport' ? 'تصویر پاسپورت' : ($t === 'fida' ? 'تصویر کارت اقامت / برگه‌ی کد فراگیر' : 'تصویر کارت ملی');
    }

    /**
     * نرمال‌سازی و اعتبارسنجیِ شماره‌ی هویتی بر اساسِ نوع. $type خالی/auto ← تشخیصِ خودکار:
     * حرفِ لاتین دارد ← پاسپورت؛ ۱۲ رقم ← کد فراگیر اتباع؛ بقیه ← کد ملی.
     * @return array{type:string, value:string}|string  مقدار یا متنِ خطا
     */
    function kyc_normalize_id(string $raw, string $type = '')
    {
        $v = strtoupper((string) preg_replace('/[\s\-\/\.]+/u', '', normalize_digits(trim($raw))));
        if ($v === '') return ['type' => $type !== '' && $type !== 'auto' ? $type : 'national', 'value' => ''];
        if (!isset(kyc_id_types()[$type])) {
            $type = preg_match('/[A-Z]/', $v) ? 'passport' : (preg_match('/^\d{12}$/', $v) ? 'fida' : 'national');
        }
        if ($type === 'national') {
            $d = preg_replace('/\D/', '', $v);
            if (!kyc_valid_national_id($d)) {
                return 'کد ملیِ واردشده معتبر نیست (۱۰ رقم با رقمِ کنترلِ صحیح). اگر مشتری از اتباع است، نوعِ مدرک را «کد فراگیر اتباع» یا «شماره پاسپورت» انتخاب کنید.';
            }
            return ['type' => 'national', 'value' => $d];
        }
        if ($type === 'fida') {
            if (!preg_match('/^\d{12}$/', $v)) return 'کد فراگیرِ اتباع باید ۱۲ رقم باشد (روی کارتِ اقامت / برگه‌ی سرشماری). اگر ندارد، «شماره پاسپورت» را انتخاب کنید.';
            return ['type' => 'fida', 'value' => $v];
        }
        if (!preg_match('/^[A-Z0-9]{5,20}$/', $v)) return 'شماره‌ی پاسپورت باید ۵ تا ۲۰ حرف/رقمِ لاتین باشد (مثلاً P01234567).';
        return ['type' => 'passport', 'value' => $v];
    }
}

if (!function_exists('kyc_get')) {
    /** ستون‌های «نام پدر» و «عنوان» در مدارکِ مشتری (یک‌بار) */
    function kyc_ensure_identity_cols(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        // نسخه‌ی ۲: نوعِ مدرکِ هویتی (اتباع) + طولِ بیشترِ شماره (کد فراگیر ۱۲ رقم / پاسپورت حرف و رقم)
        $flag2 = __DIR__ . '/../storage/.kyc_id_type_v2';
        if (!is_file($flag2)) {
            try {
                try { $pdo->query('SELECT id_type FROM customer_kyc LIMIT 1'); }
                catch (Throwable $e) { $pdo->exec("ALTER TABLE customer_kyc ADD COLUMN id_type VARCHAR(12) NOT NULL DEFAULT 'national'"); }
                $pdo->exec('ALTER TABLE customer_kyc MODIFY national_id VARCHAR(30) DEFAULT NULL');
                @file_put_contents($flag2, (string) time());
            } catch (Throwable $e) {
                error_log('kyc id_type v2: ' . $e->getMessage());
            }
        }
        $flag = __DIR__ . '/../storage/.kyc_identity_cols_v1';
        if (is_file($flag)) return;
        $ok = true;
        foreach ([
            'father_name' => 'ALTER TABLE customer_kyc ADD COLUMN father_name VARCHAR(100) DEFAULT NULL',
            'title'       => 'ALTER TABLE customer_kyc ADD COLUMN title VARCHAR(10) DEFAULT NULL',
        ] as $col => $sql) {
            try {
                $pdo->query("SELECT `$col` FROM customer_kyc LIMIT 1");
            } catch (Throwable $e) {
                try { $pdo->exec($sql); } catch (Throwable $e2) { $ok = false; }
            }
        }
        if ($ok) @file_put_contents($flag, (string) time());
    }

    function kyc_get(PDO $pdo, int $customerId): array
    {
        $row = null;
        if (finance_schema_ready($pdo)) {
            kyc_ensure_identity_cols($pdo);
            try {
                $st = $pdo->prepare('SELECT k.*, u.full_name AS card_uploader_name, v.full_name AS card_verifier_name
                    FROM customer_kyc k LEFT JOIN users u ON u.id = k.card_uploaded_by LEFT JOIN users v ON v.id = k.card_verified_by
                    WHERE k.customer_id = ? LIMIT 1');
                $st->execute([$customerId]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
            }
        }
        $row = $row ?: ['customer_id' => $customerId, 'national_id' => null, 'card_path' => null, 'address' => null, 'postal_code' => null];
        $row['father_name'] = $row['father_name'] ?? null;
        $row['title'] = $row['title'] ?? null;
        $row['id_type'] = isset(kyc_id_types()[(string) ($row['id_type'] ?? '')]) ? (string) $row['id_type'] : 'national';
        $row['id_label'] = kyc_id_label($row);
        $row['is_foreign'] = $row['id_type'] !== 'national';
        $row['has_card'] = !empty($row['card_path']);
        $row['has_national_id'] = !empty($row['national_id']);
        $row['has_address'] = trim((string) ($row['address'] ?? '')) !== '';
        $row['has_postal'] = !empty($row['postal_code']);
        $row['has_father'] = trim((string) $row['father_name']) !== '';
        $row['has_title'] = in_array((string) $row['title'], ['آقای', 'خانم'], true);
        $row['complete'] = $row['has_card'] && $row['has_national_id'] && $row['has_address'] && $row['has_postal']
            && $row['has_father'] && $row['has_title'];
        return $row;
    }
}

if (!function_exists('kyc_missing_labels')) {
    function kyc_missing_labels(array $kyc): array
    {
        $m = [];
        if (!$kyc['has_card']) $m[] = kyc_card_label($kyc);
        if (!$kyc['has_national_id']) $m[] = ($kyc['id_type'] ?? 'national') === 'national' ? 'کد ملی (یا کد فراگیر/پاسپورت برای اتباع)' : kyc_id_label($kyc);
        if (!$kyc['has_address']) $m[] = 'آدرس';
        if (!$kyc['has_postal']) $m[] = 'کد پستی';
        if (empty($kyc['has_title'])) $m[] = 'عنوان (آقای/خانم)';
        if (empty($kyc['has_father'])) $m[] = 'نام پدر';
        return $m;
    }
}

if (!function_exists('kyc_id_fields_html')) {
    /**
     * فیلدهای «نوعِ مدرک + شماره» (کد ملی / کد فراگیر اتباع / پاسپورت) برای فرم‌های مدارک.
     * name="id_type" و name="national_id" — برچسب و راهنما با تغییرِ نوع عوض می‌شود.
     */
    function kyc_id_fields_html(array $kyc, ?string $value = null, bool $required = false, string $labelClass = 'form-label'): string
    {
        static $js = false;
        $type = (string) ($_POST['id_type'] ?? ($kyc['id_type'] ?? 'national'));
        if (!isset(kyc_id_types()[$type])) $type = 'national';
        $value = $value ?? (string) ($kyc['national_id'] ?? '');
        $hints = ['national' => '۱۰ رقم', 'fida' => '۱۲ رقم — روی کارتِ اقامت', 'passport' => 'مثلاً P01234567'];
        $h = '<div data-kyc-id><label class="' . e($labelClass) . '">نوع مدرک / شماره' . ($required ? ' *' : '') . '</label><div class="input-group input-group-sm">'
            . '<select name="id_type" class="form-select form-select-sm" style="max-width:150px">';
        foreach (kyc_id_types() as $k => $l) {
            $h .= '<option value="' . $k . '" data-hint="' . e($hints[$k]) . '"' . ($k === $type ? ' selected' : '') . '>' . e($k === 'national' ? 'کد ملی (ایرانی)' : $l . ' (اتباع)') . '</option>';
        }
        $h .= '</select><input name="national_id" class="form-control" dir="ltr" maxlength="30" autocomplete="off" value="' . e($value) . '" placeholder="' . e($hints[$type]) . '"'
            . ($type === 'passport' ? '' : ' inputmode="numeric"') . '></div>'
            . '<div class="form-text small">مشتریِ اتباع که کد ملی ندارد: «کد فراگیر اتباع» یا «شماره پاسپورت».</div></div>';
        if (!$js) {
            $js = true;
            $h .= '<script>document.addEventListener("change",function(e){var s=e.target;if(!s.matches||!s.matches("[data-kyc-id] select[name=id_type]"))return;'
                . 'var i=s.closest("[data-kyc-id]").querySelector("input[name=national_id]");var o=s.options[s.selectedIndex];'
                . 'i.placeholder=o.getAttribute("data-hint")||"";if(s.value==="passport")i.removeAttribute("inputmode");else i.setAttribute("inputmode","numeric");});</script>';
        }
        return $h;
    }
}

if (!function_exists('kyc_docs_dir')) {
    function kyc_docs_dir(): string
    {
        $dir = __DIR__ . '/../uploads/customer_docs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php http_response_code(403);");
        }
        return $dir;
    }
}

if (!function_exists('kyc_save')) {
    /**
     * ذخیره/ویرایشِ مدارکِ مشتری. فقط فیلدهایی که ارسال شده‌اند تغییر می‌کنند.
     * $data: national_id?, address?, postal_code?   $cardFile: یک آیتمِ $_FILES یا null
     * @return string[] خطاها
     */
    function kyc_save(PDO $pdo, int $customerId, array $data, ?array $cardFile, int $userId): array
    {
        if (!finance_schema_ready($pdo)) {
            return ['جدولِ مدارکِ مشتری آماده نیست.'];
        }
        $errors = [];
        $set = [];
        if (array_key_exists('national_id', $data)) {
            kyc_ensure_identity_cols($pdo);
            $nid = kyc_normalize_id((string) $data['national_id'], (string) ($data['id_type'] ?? ''));
            if (is_string($nid)) {
                $errors[] = $nid;
            } elseif ($nid['value'] !== '') {
                $set['national_id'] = $nid['value'];
                $set['id_type'] = $nid['type'];
            }
        }
        if (array_key_exists('postal_code', $data)) {
            $pc = preg_replace('/\D/', '', normalize_digits((string) $data['postal_code']));
            if ($pc !== '' && !preg_match('/^\d{10}$/', $pc)) {
                $errors[] = 'کد پستی باید ۱۰ رقم باشد.';
            } elseif ($pc !== '') {
                $set['postal_code'] = $pc;
            }
        }
        if (array_key_exists('address', $data)) {
            $ad = trim((string) $data['address']);
            if ($ad !== '' && mb_strlen($ad) < 10) {
                $errors[] = 'آدرس را کامل‌تر وارد کنید (استان، شهر، خیابان، پلاک).';
            } elseif ($ad !== '') {
                $set['address'] = mb_substr($ad, 0, 1000);
            }
        }
        if (array_key_exists('father_name', $data)) {
            kyc_ensure_identity_cols($pdo);
            $fn = trim(preg_replace('/\s+/u', ' ', (string) $data['father_name']));
            if ($fn !== '' && mb_strlen($fn) < 2) {
                $errors[] = 'نام پدر را درست وارد کنید.';
            } elseif ($fn !== '') {
                $set['father_name'] = mb_substr($fn, 0, 100);
            }
        }
        if (array_key_exists('title', $data) && in_array((string) $data['title'], ['آقای', 'خانم'], true)) {
            kyc_ensure_identity_cols($pdo);
            $set['title'] = (string) $data['title'];
        }
        $newCard = null;
        if ($cardFile && (int) ($cardFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $fErr = orders_validate_files([$cardFile]);
            if ($fErr) {
                $errors = array_merge($errors, $fErr);
            } else {
                $newCard = $cardFile;
            }
        }
        if ($errors) {
            return $errors;
        }
        if ($newCard) {
            $dir = kyc_docs_dir();
            $ext = strtolower(pathinfo((string) $newCard['name'], PATHINFO_EXTENSION));
            $rel = 'c' . $customerId . '_card_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!@move_uploaded_file($newCard['tmp_name'], $dir . '/' . $rel)) {
                return ['ذخیره‌ی تصویرِ کارت ملی ناموفق بود.'];
            }
            $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
            $set['card_path'] = $rel;
            $set['card_mime'] = $mime;
            $set['card_original_name'] = mb_substr((string) $newCard['name'], 0, 250);
            $set['card_uploaded_by'] = $userId;
            $set['card_uploaded_at'] = date('Y-m-d H:i:s');
            $set['card_verified_by'] = null;
            $set['card_verified_at'] = null;
        }
        if (!$set) {
            return [];
        }
        $set['updated_by'] = $userId;
        $cols = array_keys($set);
        $sql = 'INSERT INTO customer_kyc (customer_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
                ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $cols));
        $pdo->prepare($sql)->execute(array_merge([$customerId], array_values($set)));
        return [];
    }
}

if (!function_exists('kyc_can_view')) {
    function kyc_can_view(PDO $pdo, array $user, int $customerId): bool
    {
        if (is_super_admin($user) || user_can('finance_orders_view', $user) || can_manage_service_requests($user)) {
            return true;
        }
        $st = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ? LIMIT 1');
        $st->execute([$customerId]);
        $owner = (int) $st->fetchColumn();
        if ($owner === (int) $user['id'] || leader_supervises_owner($pdo, $user, $owner)) {
            return true;
        }
        try {
            $st = $pdo->prepare('SELECT 1 FROM sales_orders WHERE customer_id = ? AND seller_user_id = ? LIMIT 1');
            $st->execute([$customerId, (int) $user['id']]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

/* ------------------------------------------------------------------ */
/*  پرداخت‌ها و اقساط                                                   */
/* ------------------------------------------------------------------ */

if (!function_exists('fin_payment_statuses')) {
    function fin_payment_statuses(): array
    {
        return [
            'pending'   => ['label' => 'در انتظار تأیید مالی', 'color' => 'warning', 'icon' => 'fa-hourglass-half'],
            'confirmed' => ['label' => 'تأیید شد', 'color' => 'success', 'icon' => 'fa-circle-check'],
            'rejected'  => ['label' => 'رد شد', 'color' => 'danger', 'icon' => 'fa-circle-xmark'],
        ];
    }
}

if (!function_exists('fin_installment_statuses')) {
    function fin_installment_statuses(): array
    {
        return [
            'paid'     => ['label' => 'پرداخت شد', 'color' => 'success', 'icon' => 'fa-circle-check'],
            'partial'  => ['label' => 'بخشی پرداخت شده', 'color' => 'info', 'icon' => 'fa-circle-half-stroke'],
            'overdue'  => ['label' => 'سررسید گذشته', 'color' => 'danger', 'icon' => 'fa-triangle-exclamation'],
            'due_soon' => ['label' => 'نزدیکِ سررسید', 'color' => 'warning', 'icon' => 'fa-bell'],
            'upcoming' => ['label' => 'در پیش', 'color' => 'secondary', 'icon' => 'fa-calendar'],
            'bounced'  => ['label' => 'چک برگشت خورد', 'color' => 'danger', 'icon' => 'fa-rotate-left'],
        ];
    }
}

if (!function_exists('fin_ensure_payment_link_cols')) {
    /** ستون‌های «این پرداخت برای کدام قسط/چک است» و «وضعیتِ چک (وصول/برگشت)» (یک‌بار) */
    function fin_ensure_payment_link_cols(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        $flag = __DIR__ . '/../storage/.fin_payment_link_cols_v1';
        if (is_file($flag)) return $ok = true;
        if (function_exists('orders_ensure_settle_cols')) orders_ensure_settle_cols($pdo);
        $ok = true;
        foreach ([
            ['sales_order_payments', 'installment_id', 'ALTER TABLE sales_order_payments ADD COLUMN installment_id INT UNSIGNED DEFAULT NULL'],
            ['sales_order_installments', 'cheque_status', 'ALTER TABLE sales_order_installments ADD COLUMN cheque_status VARCHAR(12) DEFAULT NULL'],
            ['sales_order_installments', 'cheque_status_note', 'ALTER TABLE sales_order_installments ADD COLUMN cheque_status_note VARCHAR(300) DEFAULT NULL'],
            ['sales_order_installments', 'cheque_status_at', 'ALTER TABLE sales_order_installments ADD COLUMN cheque_status_at DATETIME DEFAULT NULL'],
        ] as [$t, $c, $sql]) {
            try {
                $pdo->query("SELECT `$c` FROM `$t` LIMIT 1");
            } catch (Throwable $e) {
                try { $pdo->exec($sql); } catch (Throwable $e2) { $ok = false; error_log('fin_ensure_payment_link_cols: ' . $e2->getMessage()); }
            }
        }
        if ($ok) @file_put_contents($flag, (string) time());
        return $ok;
    }
}

if (!function_exists('fin_installment_label')) {
    /** «قسط ۲» یا «چک ۱۲۳۴۵» */
    function fin_installment_label(array $i): string
    {
        return (($i['kind'] ?? '') === 'cheque')
            ? 'چک ' . to_persian_digits((string) ($i['cheque_no'] ?? $i['seq']))
            : 'قسط ' . to_persian_digits((string) $i['seq']);
    }
}

if (!function_exists('fin_badge')) {
    function fin_badge(array $map, string $code): string
    {
        $s = $map[$code] ?? ['label' => $code, 'color' => 'secondary', 'icon' => 'fa-circle'];
        return '<span class="badge text-bg-' . e($s['color']) . '"><i class="fa-solid ' . e($s['icon']) . '"></i> ' . e($s['label']) . '</span>';
    }
}

if (!function_exists('fin_payments')) {
    function fin_payments(PDO $pdo, int $orderId): array
    {
        if (!finance_schema_ready($pdo)) return [];
        $st = $pdo->prepare('SELECT p.*, r.full_name AS recorder_name, d.full_name AS decider_name,
                (SELECT COUNT(*) FROM sales_order_files f WHERE f.payment_id = p.id) AS files_cnt
            FROM sales_order_payments p LEFT JOIN users r ON r.id = p.recorded_by LEFT JOIN users d ON d.id = p.decided_by
            WHERE p.order_id = ? ORDER BY p.id');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fin_installments')) {
    function fin_installments(PDO $pdo, int $orderId): array
    {
        if (!finance_schema_ready($pdo)) return [];
        $st = $pdo->prepare('SELECT * FROM sales_order_installments WHERE order_id = ? ORDER BY due_date, seq, id');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fin_compute')) {
    /**
     * محاسبه‌ی وضعیتِ مالیِ یک سفارش.
     * خروجی: total, paid, pending_paid, balance, credit, installments[] (با paid/remaining/state), overdue, next_due, is_debt
     */
    function fin_compute(array $order, array $payments, array $installments): array
    {
        $total = (int) $order['total_amount'];
        $paid = 0;
        $pending = 0;
        $hasRows = false;
        foreach ($payments as $p) {
            $hasRows = true;
            if ($p['status'] === 'confirmed') $paid += (int) $p['amount'];
            elseif ($p['status'] === 'pending') $pending += (int) $p['amount'];
        }
        if (!$hasRows && ($order['status'] ?? '') === 'approved') {
            $paid = (int) ($order['confirmed_amount'] ?? $order['paid_amount']);
        }
        $balance = max(0, $total - $paid);
        $credit = max(0, $paid - $total);
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+7 days'));

        $instSum = 0;
        $instIds = [];
        foreach ($installments as $i) { $instSum += (int) $i['amount']; $instIds[(int) ($i['id'] ?? 0)] = (int) $i['amount']; }
        // پرداخت‌هایی که صریحاً برای یک قسط/چک ثبت شده‌اند، اول همان را پوشش می‌دهند
        $targeted = [];
        $targetedSum = 0;
        foreach ($payments as $p) {
            $iid = (int) ($p['installment_id'] ?? 0);
            if ($p['status'] === 'confirmed' && $iid > 0 && isset($instIds[$iid])) {
                $targeted[$iid] = ($targeted[$iid] ?? 0) + (int) $p['amount'];
                $targetedSum += (int) $p['amount'];
            }
        }
        $overflow = 0;
        foreach ($targeted as $iid => $amt) {
            if ($amt > $instIds[$iid]) { $overflow += $amt - $instIds[$iid]; $targeted[$iid] = $instIds[$iid]; }
        }
        $prepay = max(0, $total - $instSum);          // بخشی که قرار بوده نقدی/پیش‌پرداخت باشد
        $pool = max(0, $paid - $targetedSum - $prepay) + $overflow; // بقیه به ترتیبِ سررسید روی اقساط می‌نشیند
        $overdue = 0;
        $nextDue = null;
        $out = [];
        foreach ($installments as $i) {
            $amt = (int) $i['amount'];
            $cover = min($amt, $targeted[(int) ($i['id'] ?? 0)] ?? 0);
            $more = min($amt - $cover, $pool);
            $pool -= $more;
            $cover += $more;
            $rem = $amt - $cover;
            if ($rem <= 0) $state = 'paid';
            elseif (($i['cheque_status'] ?? '') === 'bounced') $state = 'bounced';
            elseif ($i['due_date'] < $today) $state = 'overdue';
            elseif ($cover > 0) $state = 'partial';
            elseif ($i['due_date'] <= $soon) $state = 'due_soon';
            else $state = 'upcoming';
            if ($state === 'overdue' || $state === 'bounced') $overdue += $rem;
            if ($rem > 0 && $nextDue === null) $nextDue = ['date' => $i['due_date'], 'amount' => $rem, 'seq' => (int) $i['seq']];
            $i['paid'] = $cover;
            $i['remaining'] = $rem;
            $i['state'] = $state;
            $out[] = $i;
        }
        // بدهیِ بدونِ قسط‌بندی هم «معوق» حساب می‌شود (از تاریخِ تأییدِ سفارش)
        $unscheduled = max(0, $balance - array_sum(array_column($out, 'remaining')));
        return [
            'total' => $total, 'paid' => $paid, 'pending_paid' => $pending, 'balance' => $balance, 'credit' => $credit,
            'installments' => $out, 'overdue' => $overdue, 'unscheduled' => $unscheduled, 'next_due' => $nextDue,
            'is_debt' => ($order['status'] ?? '') === 'approved' && $balance > 0,
        ];
    }
}

if (!function_exists('fin_leader_blocked')) {
    /** سرپرست فیش/پرداخت ثبت نمی‌کند (سهمِ سرپرست فقط از سهمِ اعضای تیمش می‌آید)؛ مگر دسترسیِ تأییدِ مالی داشته باشد */
    function fin_leader_blocked(array $user): bool
    {
        return ($user['role'] ?? '') === 'leader' && !(function_exists('user_can') && user_can('finance_orders_decide', $user));
    }
}

if (!function_exists('fin_add_payment')) {
    /** ثبتِ پرداختِ جدید برای یک سفارش (در انتظارِ تأییدِ مالی، مگر اینکه خودِ مالی ثبت کند) */
    function fin_add_payment(PDO $pdo, array $order, array $data, array $files, array $user, bool $autoConfirm = false): array
    {
        if (!finance_schema_ready($pdo)) return ['ok' => false, 'message' => 'جدول‌های مالی آماده نیستند.'];
        if (fin_leader_blocked($user)) return ['ok' => false, 'message' => 'سرپرست نمی‌تواند پرداخت/فیش ثبت کند؛ فیش را کارشناسِ مشتری (A/B/C) ثبت می‌کند.'];
        $amount = (int) ($data['amount'] ?? 0);
        if ($amount <= 0) return ['ok' => false, 'message' => 'مبلغِ پرداخت را وارد کنید.'];
        $errs = orders_validate_files($files);
        if ($errs) return ['ok' => false, 'message' => implode(' ', $errs)];
        if (!$files && !$autoConfirm) return ['ok' => false, 'message' => 'تصویرِ فیش/رسیدِ پرداخت را بارگذاری کنید.'];
        $status = $autoConfirm ? 'confirmed' : 'pending';
        $pdo->prepare('INSERT INTO sales_order_payments (order_id, customer_id, kind, amount, paid_at, method, ref, note, status, recorded_by, decided_by, decided_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int) $order['id'], (int) $order['customer_id'], 'extra', $amount, $data['paid_at'] ?? null, $data['method'] ?? null,
                ($data['ref'] ?? '') !== '' ? mb_substr((string) $data['ref'], 0, 100) : null,
                ($data['note'] ?? '') !== '' ? mb_substr((string) $data['note'], 0, 500) : null,
                $status, (int) $user['id'], $autoConfirm ? (int) $user['id'] : null, $autoConfirm ? date('Y-m-d H:i:s') : null]);
        $pid = (int) $pdo->lastInsertId();
        if (!empty($data['installment_id']) && fin_ensure_payment_link_cols($pdo)) {
            $pdo->prepare('UPDATE sales_order_payments SET installment_id = ? WHERE id = ? AND EXISTS (SELECT 1 FROM sales_order_installments i WHERE i.id = ? AND i.order_id = ?)')
                ->execute([(int) $data['installment_id'], $pid, (int) $data['installment_id'], (int) $order['id']]);
        }
        if ($files) {
            orders_store_files($pdo, (int) $order['id'], $files, (int) $user['id'], $pid);
        }
        orders_add_history($pdo, (int) $order['id'], (int) $user['id'], 'payment_added', null, null,
            'پرداختِ ' . number_format($amount) . ' تومان ثبت شد' . ($autoConfirm ? ' و توسطِ مالی تأیید شد.' : ' (در انتظارِ تأییدِ مالی).'));
        return ['ok' => true, 'id' => $pid, 'message' => $autoConfirm ? 'پرداخت ثبت و تأیید شد.' : 'پرداخت ثبت شد و پس از تأییدِ واحد مالی از بدهی کسر می‌شود.'];
    }
}

if (!function_exists('fin_decide_payment')) {
    function fin_decide_payment(PDO $pdo, int $paymentId, string $status, array $user, string $note = '', ?int $amount = null, ?string $paidAt = null): array
    {
        if (!in_array($status, ['confirmed', 'rejected', 'pending'], true)) return ['ok' => false, 'message' => 'وضعیت نامعتبر.'];
        $st = $pdo->prepare('SELECT * FROM sales_order_payments WHERE id = ?');
        $st->execute([$paymentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) return ['ok' => false, 'message' => 'پرداخت پیدا نشد.'];
        if ($status === 'rejected' && trim($note) === '') return ['ok' => false, 'message' => 'دلیلِ ردِ پرداخت را بنویسید.'];
        $newAmount = ($amount !== null && $amount > 0) ? $amount : (int) $p['amount'];
        // تاریخِ واریز «طبقِ فیش» — مالی هنگامِ تأیید کنترل/اصلاح می‌کند (مبنای «تاریخ عملکرد» در سهم عملکرد)
        if ($status === 'confirmed' && $paidAt !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidAt)) {
            if ($paidAt > date('Y-m-d')) return ['ok' => false, 'message' => 'تاریخِ واریز نمی‌تواند در آینده باشد.'];
            if ($paidAt !== (string) $p['paid_at']) {
                $pdo->prepare('UPDATE sales_order_payments SET paid_at = ? WHERE id = ?')->execute([$paidAt, $paymentId]);
                orders_add_history($pdo, (int) $p['order_id'], (int) $user['id'], 'note', null, null,
                    'تاریخِ واریز طبقِ فیش اصلاح شد: ' . ($p['paid_at'] ? to_jalali((string) $p['paid_at']) : '—') . ' ← ' . to_jalali($paidAt));
            }
        }
        $pdo->prepare('UPDATE sales_order_payments SET status = ?, amount = ?, decided_by = ?, decided_at = ' . ($status === 'pending' ? 'NULL' : 'NOW()') . ', decision_note = ? WHERE id = ?')
            ->execute([$status, $newAmount, (int) $user['id'], $note !== '' ? mb_substr($note, 0, 500) : null, $paymentId]);
        $labels = fin_payment_statuses();
        orders_add_history($pdo, (int) $p['order_id'], (int) $user['id'], 'payment_' . $status, null, null,
            'پرداختِ ' . number_format($newAmount) . ' تومان: ' . $labels[$status]['label'] . ($note !== '' ? ' — ' . $note : ''));
        $o = orders_get($pdo, (int) $p['order_id']);
        if ($o && $status !== 'pending') {
            orders_notify($pdo, (int) $user['id'], (int) $o['seller_user_id'],
                'پرداختِ ' . number_format($newAmount) . ' تومانِ مشتری ' . $o['customer_name'] . ' (فاکتور ' . $o['order_number'] . '): ' . $labels[$status]['label'] . ($note !== '' ? "\n" . $note : ''));
        }
        return ['ok' => true, 'message' => 'وضعیتِ پرداخت: ' . $labels[$status]['label']];
    }
}

if (!function_exists('fin_set_installments')) {
    /**
     * جایگزینیِ کاملِ برنامه‌ی اقساطِ یک سفارش.
     * $rows: [['amount'=>int,'due_date'=>'Y-m-d','note'=>?], ...]
     */
    function fin_set_installments(PDO $pdo, array $order, array $rows, int $userId): array
    {
        if (!finance_schema_ready($pdo)) return ['ok' => false, 'message' => 'جدول‌های مالی آماده نیستند.'];
        $clean = [];
        foreach ($rows as $r) {
            $amt = (int) ($r['amount'] ?? 0);
            $due = (string) ($r['due_date'] ?? '');
            if ($amt <= 0 && $due === '') continue;
            if ($amt <= 0) return ['ok' => false, 'message' => 'مبلغِ همه‌ی اقساط باید بزرگ‌تر از صفر باشد.'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) return ['ok' => false, 'message' => 'تاریخِ سررسیدِ یکی از اقساط معتبر نیست.'];
            $clean[] = ['amount' => $amt, 'due_date' => $due, 'note' => mb_substr(trim((string) ($r['note'] ?? '')), 0, 250),
                'kind' => ($r['kind'] ?? 'installment') === 'cheque' ? 'cheque' : 'installment',
                'cheque_no' => mb_substr(trim((string) ($r['cheque_no'] ?? '')), 0, 40), 'bank' => mb_substr(trim((string) ($r['bank'] ?? '')), 0, 80)];
        }
        $sum = array_sum(array_column($clean, 'amount'));
        if ($sum > (int) $order['total_amount']) {
            return ['ok' => false, 'message' => 'جمعِ اقساط (' . number_format($sum) . ') از مبلغِ فاکتور بیشتر است.'];
        }
        usort($clean, static fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
        $pdo->beginTransaction();
        try {
            // پرداخت‌هایی که به قسط/چکِ قبلی وصل بودند، آزاد می‌شوند (به ترتیبِ سررسید روی برنامه‌ی جدید می‌نشینند)
            if (fin_ensure_payment_link_cols($pdo)) {
                $pdo->prepare('UPDATE sales_order_payments SET installment_id = NULL WHERE order_id = ?')->execute([(int) $order['id']]);
            }
            $pdo->prepare('DELETE FROM sales_order_installments WHERE order_id = ?')->execute([(int) $order['id']]);
            $withKind = function_exists('orders_ensure_settle_cols') && orders_ensure_settle_cols($pdo);
            $ins = $withKind
                ? $pdo->prepare('INSERT INTO sales_order_installments (order_id, customer_id, seq, amount, due_date, note, created_by, kind, cheque_no, bank) VALUES (?,?,?,?,?,?,?,?,?,?)')
                : $pdo->prepare('INSERT INTO sales_order_installments (order_id, customer_id, seq, amount, due_date, note, created_by) VALUES (?,?,?,?,?,?,?)');
            foreach ($clean as $i => $r) {
                $vals = [(int) $order['id'], (int) $order['customer_id'], $i + 1, $r['amount'], $r['due_date'], $r['note'] !== '' ? $r['note'] : null, $userId];
                if ($withKind) {
                    $vals[] = $r['kind'];
                    $vals[] = $r['cheque_no'] !== '' ? $r['cheque_no'] : null;
                    $vals[] = $r['bank'] !== '' ? $r['bank'] : null;
                }
                $ins->execute($vals);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'خطا در ذخیره‌ی اقساط: ' . $e->getMessage()];
        }
        orders_add_history($pdo, (int) $order['id'], $userId, 'installments', null, null,
            $clean ? to_persian_digits((string) count($clean)) . ' قسط به جمعِ ' . number_format($sum) . ' تومان تنظیم شد.' : 'برنامه‌ی اقساط حذف شد.');
        return ['ok' => true, 'message' => $clean ? 'برنامه‌ی اقساط ذخیره شد.' : 'برنامه‌ی اقساط حذف شد.'];
    }
}

if (!function_exists('fin_parse_installment_post')) {
    /** خواندنِ آرایه‌های inst_amount[] و inst_due[] (تاریخِ شمسی) از فرم */
    function fin_parse_installment_post(array $post): array
    {
        $rows = [];
        $amts = (array) ($post['inst_amount'] ?? []);
        $dues = (array) ($post['inst_due'] ?? []);
        $notes = (array) ($post['inst_note'] ?? []);
        foreach ($amts as $i => $a) {
            $amount = orders_money($a);
            $dueRaw = trim((string) ($dues[$i] ?? ''));
            if ($amount <= 0 && $dueRaw === '') continue;
            $rows[] = ['amount' => $amount, 'due_date' => $dueRaw !== '' ? (string) (to_gregorian($dueRaw) ?? 'bad') : '', 'due_raw' => $dueRaw, 'note' => (string) ($notes[$i] ?? '')];
        }
        return $rows;
    }
}

if (!function_exists('fin_parse_cheque_post')) {
    /** خواندنِ چک‌ها از فرم: chq_amount[] chq_due[] chq_no[] chq_bank[] */
    function fin_parse_cheque_post(array $post): array
    {
        $rows = [];
        $amts = (array) ($post['chq_amount'] ?? []);
        foreach ($amts as $i => $a) {
            $amount = orders_money($a);
            $dueRaw = trim((string) (($post['chq_due'] ?? [])[$i] ?? ''));
            $no = trim(normalize_digits((string) (($post['chq_no'] ?? [])[$i] ?? '')));
            $bank = trim((string) (($post['chq_bank'] ?? [])[$i] ?? ''));
            if ($amount <= 0 && $dueRaw === '' && $no === '') continue;
            $rows[] = ['amount' => $amount, 'due_date' => $dueRaw !== '' ? (string) (to_gregorian($dueRaw) ?? 'bad') : '', 'due_raw' => $dueRaw,
                'note' => $bank !== '' ? 'چک ' . $no . ' — ' . $bank : 'چک ' . $no, 'kind' => 'cheque', 'cheque_no' => $no, 'bank' => $bank];
        }
        return $rows;
    }
}

/* ------------------------------------------------------------------ */
/*  مطالبات (بدهکاران)                                                  */
/* ------------------------------------------------------------------ */

if (!function_exists('fin_receivables')) {
    /**
     * همه‌ی سفارش‌های تأییدشده‌ای که مانده دارند (یا اقساطِ باز) + محاسبه‌ی کامل.
     * $scope: ['seller_ids' => int[]|null, 'customer_id' => int|null]
     */
    function fin_receivables(PDO $pdo, array $scope = []): array
    {
        if (!orders_ready($pdo) || !finance_schema_ready($pdo)) return [];
        $where = ["o.status = 'approved'"];
        $params = [];
        if (!empty($scope['seller_ids'])) {
            $ids = implode(',', array_map('intval', $scope['seller_ids']));
            // سفارش‌هایی که خودشان فروخته‌اند یا مشتریِ خودشان است
            $where[] = "(o.seller_user_id IN ($ids) OR o.customer_owner_id IN ($ids))";
        }
        if (!empty($scope['only_seller_ids'])) {
            $where[] = 'o.seller_user_id IN (' . implode(',', array_map('intval', $scope['only_seller_ids'])) . ')';
        }
        if (!empty($scope['customer_id'])) {
            $where[] = 'o.customer_id = ?';
            $params[] = (int) $scope['customer_id'];
        }
        $sql = "SELECT o.*, c.full_name AS customer_name, c.mobile AS customer_mobile, s.full_name AS seller_name,
                    COALESCE((SELECT SUM(p.amount) FROM sales_order_payments p WHERE p.order_id = o.id AND p.status = 'confirmed'), 0) AS paid_sum,
                    (SELECT COUNT(*) FROM sales_order_payments p WHERE p.order_id = o.id) AS pay_rows
                FROM sales_orders o
                LEFT JOIN customers c ON c.id = o.customer_id
                LEFT JOIN users s ON s.id = o.seller_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY o.id DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$orders) return [];
        $ids = implode(',', array_map('intval', array_column($orders, 'id')));
        $pays = [];
        foreach ($pdo->query("SELECT * FROM sales_order_payments WHERE order_id IN ($ids)") as $p) $pays[(int) $p['order_id']][] = $p;
        $insts = [];
        foreach ($pdo->query("SELECT * FROM sales_order_installments WHERE order_id IN ($ids) ORDER BY due_date, seq") as $i) $insts[(int) $i['order_id']][] = $i;
        foreach ($orders as &$o) {
            $o['fin'] = fin_compute($o, $pays[(int) $o['id']] ?? [], $insts[(int) $o['id']] ?? []);
        }
        unset($o);
        return array_values(array_filter($orders, static fn($o) => $o['fin']['balance'] > 0));
    }
}

if (!function_exists('fin_receivables_summary')) {
    function fin_receivables_summary(array $rows): array
    {
        $sum = ['balance' => 0, 'overdue' => 0, 'due_week' => 0, 'unscheduled' => 0, 'orders' => count($rows), 'customers' => 0];
        $cust = [];
        $week = date('Y-m-d', strtotime('+7 days'));
        foreach ($rows as $o) {
            $f = $o['fin'];
            $sum['balance'] += $f['balance'];
            $sum['overdue'] += $f['overdue'];
            $sum['unscheduled'] += $f['unscheduled'];
            foreach ($f['installments'] as $i) {
                if ($i['remaining'] > 0 && $i['state'] !== 'overdue' && $i['due_date'] <= $week) $sum['due_week'] += $i['remaining'];
            }
            $cust[(int) $o['customer_id']] = true;
        }
        $sum['customers'] = count($cust);
        return $sum;
    }
}

if (!function_exists('fin_group_by_customer')) {
    function fin_group_by_customer(array $rows): array
    {
        $g = [];
        foreach ($rows as $o) {
            $cid = (int) $o['customer_id'];
            if (!isset($g[$cid])) {
                $g[$cid] = ['customer_id' => $cid, 'customer_name' => $o['customer_name'], 'customer_mobile' => $o['customer_mobile'],
                    'sellers' => [], 'orders' => 0, 'total' => 0, 'paid' => 0, 'balance' => 0, 'overdue' => 0, 'next_due' => null];
            }
            $f = $o['fin'];
            $g[$cid]['orders']++;
            $g[$cid]['total'] += $f['total'];
            $g[$cid]['paid'] += $f['paid'];
            $g[$cid]['balance'] += $f['balance'];
            $g[$cid]['overdue'] += $f['overdue'] + $f['unscheduled'];
            $g[$cid]['sellers'][$o['seller_name'] ?? '—'] = true;
            if ($f['next_due'] && ($g[$cid]['next_due'] === null || $f['next_due']['date'] < $g[$cid]['next_due']['date'])) {
                $g[$cid]['next_due'] = $f['next_due'];
            }
        }
        usort($g, static fn($a, $b) => [$b['overdue'], $b['balance']] <=> [$a['overdue'], $a['balance']]);
        return $g;
    }
}

if (!function_exists('fin_open_installments')) {
    /** فهرستِ تختِ اقساطِ باز (برای پیگیریِ وصول)، مرتب بر اساسِ سررسید */
    function fin_open_installments(array $rows): array
    {
        $list = [];
        foreach ($rows as $o) {
            foreach ($o['fin']['installments'] as $i) {
                if ($i['remaining'] <= 0) continue;
                $i['order'] = $o;
                $list[] = $i;
            }
            if ($o['fin']['unscheduled'] > 0) {
                $list[] = ['seq' => 0, 'due_date' => substr((string) ($o['decided_at'] ?? $o['created_at']), 0, 10), 'amount' => $o['fin']['unscheduled'],
                    'remaining' => $o['fin']['unscheduled'], 'paid' => 0, 'state' => 'overdue', 'unscheduled' => true, 'order' => $o];
            }
        }
        usort($list, static fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
        return $list;
    }
}

if (!function_exists('fin_pending_payments')) {
    function fin_pending_payments(PDO $pdo, int $limit = 100): array
    {
        if (!finance_schema_ready($pdo)) return [];
        $st = $pdo->query("SELECT p.*, o.order_number, o.status AS order_status, c.full_name AS customer_name, r.full_name AS recorder_name,
                (SELECT COUNT(*) FROM sales_order_files f WHERE f.payment_id = p.id) AS files_cnt
            FROM sales_order_payments p JOIN sales_orders o ON o.id = p.order_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN users r ON r.id = p.recorded_by
            WHERE p.status = 'pending' AND p.kind <> 'initial' AND o.status = 'approved' ORDER BY p.id LIMIT " . (int) $limit);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('render_customer_finance_block')) {
    /**
     * کارتِ «وضعیتِ مالی، بدهی، بستانکاری و اقساط» در پرونده‌ی مشتری — برای کلِ «شخص» (پروفایل ۳۶۰):
     * همه‌ی پرونده‌هایی که شماره یا کدِ ملیِ مشترک دارند، با هر کارشناسی.
     */
    function render_customer_finance_block(PDO $pdo, int $customerId): void
    {
        if (!orders_ready($pdo) || !finance_schema_ready($pdo)) return;
        require_once __DIR__ . '/customer_credit.php';
        $pos = cc_position($pdo, $customerId);
        $orders = array_values(array_filter($pos['orders'], static fn($o) => in_array($o['status'], ['approved', 'pending', 'cancelled'], true)));
        if (!$orders && !$pos['entries']) return;
        $byId = [];
        foreach ($orders as $o) $byId[(int) $o['id']] = $o;

        $insts = [];
        $nextDue = null;
        foreach ($orders as $o) {
            if ($o['status'] !== 'approved') continue;
            $f = $o['fin'];
            foreach ($f['installments'] as $i) { $i['order_number'] = $o['order_number']; $i['order_id'] = $o['id']; $i['seller_name'] = $o['seller_name']; $insts[] = $i; }
            if ($f['next_due'] && ($nextDue === null || $f['next_due']['date'] < $nextDue['date'])) $nextDue = $f['next_due'];
        }
        usort($insts, static fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

        $payments = [];
        if ($byId) {
            $in = implode(',', array_map('intval', array_keys($byId)));
            $payments = $pdo->query("SELECT p.*, r.full_name AS recorder_name FROM sales_order_payments p LEFT JOIN users r ON r.id = p.recorded_by
                WHERE p.order_id IN ($in) ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $ist = fin_installment_statuses();
        $pst = fin_payment_statuses();
        $multi = count($pos['ids']) > 1;
        $credit = (int) $pos['credit'];
        $debt = (int) $pos['debt'];
        $state = $debt > 0 ? 'این مشتری بدهکار است' : ($credit > 0 ? 'این مشتری بستانکار است' : 'تسویه‌شده');
        ?>
        <div class="card p-3 p-md-4 mb-3 mt-4" id="customer-finance" style="border-top:3px solid #f87171">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
              <span style="width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#fee2e2,#f87171);color:#450a0a"><i class="fa-solid fa-scale-balanced"></i></span>
              <div><h6 class="mb-0 fw-bold">وضعیت مالی، بدهی، بستانکاری و اقساط</h6>
                <div class="small text-muted"><?= e($state) ?><?= $pos['pending_orders'] ? ' — ' . to_persian_digits((string) $pos['pending_orders']) . ' سفارش در انتظارِ تأییدِ مالی' : '' ?></div></div>
            </div>
            <?php if ($multi): ?>
              <span class="badge text-bg-light border small"><i class="fa-solid fa-users-viewfinder"></i> جامع — پروفایل ۳۶۰ (<?= to_persian_digits((string) count($pos['ids'])) ?> پرونده<?= $pos['sellers'] ? '، کارشناس‌ها: ' . e(implode('، ', array_keys($pos['sellers']))) : '' ?>)</span>
            <?php endif; ?>
          </div>
          <div class="row g-2 mb-3 text-center">
            <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><div class="small text-muted">جمع فاکتورها</div><div class="fw-bold"><?= format_toman((int) $pos['total']) ?></div></div></div>
            <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><div class="small text-muted">پرداختِ تأییدشده</div><div class="fw-bold text-success"><?= format_toman((int) $pos['paid']) ?></div></div></div>
            <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100" style="background:<?= $debt > 0 ? '#fef2f2' : '#f0fdf4' ?>"><div class="small text-muted">مانده‌ی بدهی</div><div class="fw-bold <?= $debt > 0 ? 'text-danger' : 'text-success' ?>"><?= format_toman($debt) ?></div></div></div>
            <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100" style="background:<?= $credit > 0 ? '#eff6ff' : '#fff' ?>"><div class="small text-muted">بستانکاری</div><div class="fw-bold <?= $credit > 0 ? 'text-primary' : '' ?>"><?= format_toman(max(0, $credit)) ?></div></div></div>
            <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><div class="small text-muted">معوق (سررسید گذشته)</div><div class="fw-bold <?= $pos['overdue'] > 0 ? 'text-danger' : '' ?>"><?= format_toman((int) $pos['overdue']) ?></div></div></div>
          </div>
          <?php if ($credit > 0 && $debt > 0): ?>
            <div class="alert alert-primary py-2 small mb-3"><i class="fa-solid fa-circle-info"></i> این مشتری هم‌زمان <?= format_toman($credit) ?> بستانکاری و <?= format_toman($debt) ?> بدهی دارد؛ واحد مالی می‌تواند از بخشِ «سفارش‌ها و خریدهای مشتری» بستانکاری را روی فاکتورِ بدهکار اعمال کند.</div>
          <?php endif; ?>
          <?php if ($nextDue): ?>
            <div class="alert alert-warning py-2 small mb-3"><i class="fa-solid fa-bell"></i> قسطِ بعدی: <b><?= format_toman((int) $nextDue['amount']) ?></b> — سررسید <b><?= to_jalali($nextDue['date']) ?></b></div>
          <?php endif; ?>
          <?php if ($pos['pending_paid'] > 0): ?>
            <div class="alert alert-info py-2 small mb-3"><i class="fa-solid fa-hourglass-half"></i> <?= format_toman((int) $pos['pending_paid']) ?> پرداختِ ثبت‌شده در انتظارِ تأییدِ واحد مالی است.</div>
          <?php endif; ?>
          <?php if ($insts): ?>
            <h6 class="fw-bold small mb-2"><i class="fa-solid fa-calendar-days text-warning"></i> اقساط</h6>
            <div class="table-responsive mb-3">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>فاکتور</th><th>کارشناس</th><th>قسط</th><th>سررسید</th><th>مبلغ</th><th>پرداخت‌شده</th><th>مانده</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($insts as $i): ?>
                  <tr>
                    <td><a href="order_view.php?id=<?= (int) $i['order_id'] ?>" class="text-decoration-none"><?= e(to_persian_digits($i['order_number'])) ?></a></td>
                    <td class="small"><?= e((string) ($i['seller_name'] ?? '—')) ?></td>
                    <td><?= to_persian_digits((string) $i['seq']) ?><?php if (($i['kind'] ?? '') === 'cheque'): ?> <span class="badge text-bg-primary" title="چک <?= e((string) ($i['cheque_no'] ?? '')) ?><?= !empty($i['bank']) ? ' — ' . e((string) $i['bank']) : '' ?>">چک</span><?php endif; ?></td>
                    <td><?= to_jalali($i['due_date']) ?></td>
                    <td><?= format_toman((int) $i['amount']) ?></td>
                    <td class="text-success"><?= format_toman((int) $i['paid']) ?></td>
                    <td class="<?= $i['remaining'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= format_toman((int) $i['remaining']) ?></td>
                    <td><?= fin_badge($ist, $i['state']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if ($payments): ?>
            <h6 class="fw-bold small mb-2"><i class="fa-solid fa-money-bill-transfer text-success"></i> پرداخت‌ها</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>فاکتور</th><th>کارشناس</th><th>تاریخ</th><th>مبلغ</th><th>نوع</th><th>ثبت‌کننده</th><th>وضعیت</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $p): $o = $byId[(int) $p['order_id']] ?? []; ?>
                  <tr>
                    <td><a href="order_view.php?id=<?= (int) $p['order_id'] ?>" class="text-decoration-none"><?= e(to_persian_digits((string) ($o['order_number'] ?? ''))) ?></a><?= ($o['status'] ?? '') === 'cancelled' ? ' <span class="badge text-bg-secondary">لغوشده</span>' : '' ?></td>
                    <td class="small"><?= e((string) ($o['seller_name'] ?? '—')) ?></td>
                    <td><?= $p['paid_at'] ? to_jalali($p['paid_at']) : to_jalali($p['created_at']) ?></td>
                    <td><?= format_toman((int) $p['amount']) ?></td>
                    <td class="small"><?= e(fin_payment_kind_label((string) $p['kind'])) ?></td>
                    <td class="small"><?= e($p['recorder_name'] ?? '—') ?></td>
                    <td><?= fin_badge($pst, (string) $p['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('render_customer_kyc_block')) {
    /** کارتِ «مدارک و اطلاعاتِ هویتی» در پرونده‌ی مشتری (کارت ملی، کد ملی، آدرس، کد پستی) */
    function render_customer_kyc_block(PDO $pdo, int $customerId, bool $canEdit): void
    {
        if (!finance_schema_ready($pdo)) return;
        $k = kyc_get($pdo, $customerId);
        $missing = kyc_missing_labels($k);
        $isImg = strpos((string) ($k['card_mime'] ?? ''), 'image/') === 0;
        $__ctrOn = true;
        ?>
        <div class="card p-3 p-md-4 mb-3 mt-4" id="customer-kyc" style="border-top:3px solid #38bdf8">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
              <span style="width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#e0f2fe,#38bdf8);color:#082f49"><i class="fa-solid fa-id-card"></i></span>
              <div><h6 class="mb-0 fw-bold">مدارک و اطلاعاتِ هویتیِ مشتری</h6>
                <div class="small <?= $k['complete'] ? 'text-success' : 'text-warning' ?>">
                  <?= $k['complete'] ? '<i class="fa-solid fa-circle-check"></i> کامل است — برای سفارش‌های بعدی دوباره پرسیده نمی‌شود' : '<i class="fa-solid fa-triangle-exclamation"></i> ناقص: ' . e(implode('، ', $missing)) ?>
                </div></div>
            </div>
            <?php if ($canEdit): ?><button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#kycEdit"><i class="fa-solid fa-pen"></i> <?= $k['complete'] ? 'ویرایش' : 'تکمیل اطلاعات' ?></button><?php endif; ?>
          </div>
          <div class="row g-3">
            <div class="col-md-4">
              <div class="small text-muted mb-1">کارت ملی</div>
              <?php if ($k['has_card']): ?>
                <a href="customer_doc.php?customer_id=<?= $customerId ?>" target="_blank" class="d-block border rounded-3 overflow-hidden text-center bg-light" style="height:130px">
                  <?php if ($isImg): ?><img src="customer_doc.php?customer_id=<?= $customerId ?>" alt="کارت ملی" style="width:100%;height:100%;object-fit:cover"><?php else: ?><div class="pt-4"><i class="fa-solid fa-file-pdf fs-2 text-danger"></i><div class="small">مشاهده فایل</div></div><?php endif; ?>
                </a>
                <div class="small mt-1 text-success"><i class="fa-solid fa-circle-check"></i> ثبت شده<?= !empty($k['card_uploader_name']) ? ' توسطِ ' . e($k['card_uploader_name']) : '' ?> — <?= to_jalali((string) $k['card_uploaded_at']) ?></div>
                <?php if (!empty($k['card_verified_at'])): ?><div class="small text-success"><i class="fa-solid fa-shield-halved"></i> تأییدِ مالی: <?= e((string) ($k['card_verifier_name'] ?? '')) ?></div><?php endif; ?>
              <?php else: ?>
                <div class="border rounded-3 text-center text-muted small p-4" style="border-style:dashed!important"><i class="fa-regular fa-id-card fs-3 d-block mb-1"></i>بارگذاری نشده</div>
              <?php endif; ?>
            </div>
            <div class="col-md-8">
              <table class="table table-sm mb-0">
                <tr><th class="text-muted fw-normal" style="width:110px">عنوان</th><td><?= $k['has_title'] ? e((string) $k['title']) : '<span class="text-warning">ثبت نشده</span>' ?></td></tr>
                <tr><th class="text-muted fw-normal"><?= e($k['id_label']) ?></th><td dir="ltr" class="text-end"><?= $k['has_national_id'] ? e($k['national_id']) : '<span class="text-warning">ثبت نشده</span>' ?><?= $k['is_foreign'] ? ' <span class="badge text-bg-info">اتباع</span>' : '' ?></td></tr>
                <tr><th class="text-muted fw-normal">کد پستی</th><td dir="ltr" class="text-end"><?= $k['has_postal'] ? e($k['postal_code']) : '<span class="text-warning">ثبت نشده</span>' ?></td></tr>
                <?php if ($__ctrOn): ?>
                <tr><th class="text-muted fw-normal">نام پدر</th><td><?= $k['has_father'] ? e((string) $k['father_name']) : '<span class="text-warning">ثبت نشده (برای قرارداد لازم است)</span>' ?></td></tr>
                <?php endif; ?>
                <tr><th class="text-muted fw-normal">آدرس</th><td><?= $k['has_address'] ? nl2br(e((string) $k['address'])) : '<span class="text-warning">ثبت نشده</span>' ?></td></tr>
              </table>
            </div>
          </div>
          <?php
          // بعد از ثبتِ کد ملی: دکمه‌ی کپیِ متنِ «پیامِ رضایتِ پرداخت» (نام + کد ملی + مبلغِ آخرین سفارش)
          if (!empty($k['has_national_id'])) {
              try {
                  require_once __DIR__ . '/consent_functions.php';
                  $__idn = consent_customer_identity($pdo, $customerId);
                  $__amt = 0;
                  try {
                      $__q = $pdo->prepare("SELECT paid_amount FROM sales_orders WHERE customer_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
                      $__q->execute([$customerId]);
                      $__amt = (int) $__q->fetchColumn();
                  } catch (Throwable $e) {}
                  $__aid = 'consentAmt' . $customerId;
                  echo '<div class="d-flex flex-wrap gap-2 align-items-center mt-3 pt-2 border-top">'
                      . '<span class="small text-muted"><i class="fa-solid fa-file-signature"></i> پیامِ رضایتِ پرداخت — مبلغ (تومان):</span>'
                      . '<input id="' . $__aid . '" class="form-control form-control-sm" style="max-width:150px" dir="ltr" inputmode="numeric" placeholder="مبلغِ واریزی" value="' . ($__amt > 0 ? number_format($__amt) : '') . '">'
                      . consent_copy_button($__idn['name'], (string) $k['national_id'], $__amt, $__aid, '', 'btn btn-sm btn-outline-success', (string) $k['id_label'])
                      . '</div>';
              } catch (Throwable $e) {
                  error_log('kyc consent button: ' . $e->getMessage());
              }
          }
          ?>
          <?php if ($canEdit): ?>
          <div class="collapse mt-3 <?= !$k['complete'] && isset($_GET['kyc']) ? 'show' : '' ?>" id="kycEdit">
            <form method="post" action="customer_doc.php" enctype="multipart/form-data" class="border rounded-3 p-3" style="background:#fdfbf5">
              <?= csrf_field() ?>
              <input type="hidden" name="customer_id" value="<?= $customerId ?>">
              <div class="row g-2">
                <div class="col-md-4"><?= kyc_id_fields_html($k) ?></div>
                <div class="col-md-4"><label class="form-label">کد پستی</label><input name="postal_code" class="form-control" dir="ltr" inputmode="numeric" maxlength="12" value="<?= e((string) ($k['postal_code'] ?? '')) ?>" placeholder="۱۰ رقم"></div>
                <div class="col-md-4"><label class="form-label"><?= e(kyc_card_label($k)) ?> <?= $k['has_card'] ? '(برای جایگزینی)' : '' ?></label><input type="file" name="national_card" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                <?php if ($__ctrOn): ?>
                <div class="col-md-4"><label class="form-label">عنوان <span class="text-danger">*</span></label><select name="title" class="form-select" required><option value="">انتخاب کنید</option><?php foreach (['آقای', 'خانم'] as $__t): ?><option value="<?= $__t ?>" <?= ($k['title'] ?? '') === $__t ? 'selected' : '' ?>><?= $__t ?></option><?php endforeach; ?></select></div>
                <div class="col-md-8"><label class="form-label">نام پدر <span class="text-danger">*</span></label><input name="father_name" class="form-control" maxlength="100" required value="<?= e((string) ($k['father_name'] ?? '')) ?>"></div>
                <?php endif; ?>
                <div class="col-12"><label class="form-label">آدرس کامل</label><textarea name="address" class="form-control" rows="2" placeholder="استان، شهر، خیابان، کوچه، پلاک، واحد"><?= e((string) ($k['address'] ?? '')) ?></textarea></div>
              </div>
              <div class="mt-2 text-start"><button class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> ذخیره‌ی مدارک</button></div>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('fin_cheque_clear')) {
    /**
     * «وصول شد» برای یک چک: یک پرداختِ تأییدشده به مبلغِ مانده‌ی همان چک ثبت می‌شود (متصل به همان چک)
     * و وضعیتِ چک «وصول‌شده» می‌شود. فقط واحد مالی.
     */
    function fin_cheque_clear(PDO $pdo, array $order, int $instId, array $user): array
    {
        if (!fin_ensure_payment_link_cols($pdo)) return ['ok' => false, 'message' => 'ستون‌های لازم ساخته نشدند.'];
        $fin = fin_compute($order, fin_payments($pdo, (int) $order['id']), fin_installments($pdo, (int) $order['id']));
        $inst = null;
        foreach ($fin['installments'] as $i) {
            if ((int) $i['id'] === $instId) { $inst = $i; break; }
        }
        if (!$inst || ($inst['kind'] ?? '') !== 'cheque') return ['ok' => false, 'message' => 'چک پیدا نشد.'];
        if ((int) $inst['remaining'] <= 0) return ['ok' => false, 'message' => 'این چک قبلاً کامل پرداخت شده است.'];
        $label = fin_installment_label($inst);
        $res = fin_add_payment($pdo, $order, [
            'amount' => (int) $inst['remaining'], 'paid_at' => date('Y-m-d'), 'method' => 'cheque_clear',
            'ref' => (string) ($inst['cheque_no'] ?? ''), 'note' => 'وصولِ ' . $label . (!empty($inst['bank']) ? ' — ' . $inst['bank'] : ''),
            'installment_id' => $instId,
        ], [], $user, true);
        if (!$res['ok']) return $res;
        $pdo->prepare("UPDATE sales_order_installments SET cheque_status = 'cleared', cheque_status_note = NULL, cheque_status_at = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $instId]);
        orders_add_history($pdo, (int) $order['id'], (int) $user['id'], 'payment_confirmed', null, null, $label . ' وصول شد (' . number_format((int) $inst['remaining']) . ' تومان).');
        return ['ok' => true, 'message' => $label . ' وصول شد و ' . number_format((int) $inst['remaining']) . ' تومان از بدهی کسر شد.'];
    }
}

if (!function_exists('fin_cheque_bounce')) {
    /** «برگشت خورد»: چک برگشتی علامت می‌خورد، بدهی سرِ جایش می‌ماند و کارشناس باخبر می‌شود. */
    function fin_cheque_bounce(PDO $pdo, array $order, int $instId, array $user, string $reason): array
    {
        if (!fin_ensure_payment_link_cols($pdo)) return ['ok' => false, 'message' => 'ستون‌های لازم ساخته نشدند.'];
        $st = $pdo->prepare("SELECT * FROM sales_order_installments WHERE id = ? AND order_id = ? AND kind = 'cheque'");
        $st->execute([$instId, (int) $order['id']]);
        $inst = $st->fetch(PDO::FETCH_ASSOC);
        if (!$inst) return ['ok' => false, 'message' => 'چک پیدا نشد.'];
        $reason = mb_substr(trim($reason), 0, 300);
        $pdo->prepare("UPDATE sales_order_installments SET cheque_status = 'bounced', cheque_status_note = ?, cheque_status_at = ? WHERE id = ?")
            ->execute([$reason !== '' ? $reason : null, date('Y-m-d H:i:s'), $instId]);
        $label = fin_installment_label($inst);
        orders_add_history($pdo, (int) $order['id'], (int) $user['id'], 'note', null, null, $label . ' برگشت خورد' . ($reason !== '' ? ' — ' . $reason : '') . '.');
        if (function_exists('orders_notify') && !empty($order['seller_user_id'])) {
            orders_notify($pdo, (int) $user['id'], (int) $order['seller_user_id'],
                $label . ' مشتری ' . ($order['customer_name'] ?? '') . ' (فاکتور ' . $order['order_number'] . ') برگشت خورد؛ لطفاً پیگیری و پرداختِ جایگزین را ثبت کنید.');
        }
        return ['ok' => true, 'message' => $label . ' «برگشت‌خورده» علامت خورد؛ مبلغش همچنان بدهیِ مشتری است و به کارشناس اطلاع داده شد.'];
    }
}
