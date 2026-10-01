<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  ماژولِ «ثبت سفارش و بررسی مالی»
 * ═══════════════════════════════════════════════════════════════════════
 *  جریان کار:
 *   ۱) کارشناس (واحد A/B/C، سرپرست، نظارت) در پرونده‌ی مشتری پیش‌فاکتور می‌سازد و قفل می‌کند.
 *   ۲) «تبدیل به فاکتور و ثبت سفارش» → اطلاعاتِ پرداخت + تصویرِ فیشِ واریزی ثبت می‌شود.
 *      سفارش با وضعیتِ «در انتظار بررسی مالی» برای واحد مالی ارسال می‌شود.
 *   ۳) واحد مالی سفارش را «تأیید و ثبت»، «رد» یا دوباره «در انتظار بررسی» می‌کند.
 *   ۴) سفارش‌ها (و خدماتِ خریداری‌شده) در پرونده‌ی مشتری باقی می‌مانند.
 */

if (!function_exists('orders_ready')) {
    function orders_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        require_once __DIR__ . '/finance_functions.php';
        $flag = __DIR__ . '/../storage/.orders_schema_v1';
        if (is_file($flag)) {
            $ready = true;
            finance_schema_ready($pdo);
            return $ready;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_number` VARCHAR(40) NOT NULL,
                `quote_id` INT UNSIGNED NOT NULL,
                `customer_id` INT UNSIGNED NOT NULL,
                `seller_user_id` INT UNSIGNED NOT NULL,
                `customer_owner_id` INT UNSIGNED DEFAULT NULL,
                `subtotal` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
                `discount_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `free_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
                `tax_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `total_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `paid_amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `payment_method` VARCHAR(30) DEFAULT NULL,
                `payment_date` DATE DEFAULT NULL,
                `payment_ref` VARCHAR(100) DEFAULT NULL,
                `payer_name` VARCHAR(150) DEFAULT NULL,
                `seller_note` TEXT,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `finance_user_id` INT UNSIGNED DEFAULT NULL,
                `finance_note` TEXT,
                `confirmed_amount` BIGINT UNSIGNED DEFAULT NULL,
                `decided_at` DATETIME DEFAULT NULL,
                `submitted_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_sales_orders_number` (`order_number`),
                KEY `idx_so_quote` (`quote_id`),
                KEY `idx_so_customer` (`customer_id`),
                KEY `idx_so_seller` (`seller_user_id`),
                KEY `idx_so_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_order_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `service_id` INT UNSIGNED DEFAULT NULL,
                `title` VARCHAR(255) NOT NULL,
                `unit` VARCHAR(50) DEFAULT NULL,
                `unit_price` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 1,
                `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_soi_order` (`order_id`),
                KEY `idx_soi_service` (`service_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_order_files` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `file_path` VARCHAR(255) NOT NULL,
                `original_name` VARCHAR(255) DEFAULT NULL,
                `mime` VARCHAR(80) DEFAULT NULL,
                `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
                `uploaded_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_sof_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS `sales_order_history` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `action` VARCHAR(30) NOT NULL,
                `from_status` VARCHAR(20) DEFAULT NULL,
                `to_status` VARCHAR(20) DEFAULT NULL,
                `note` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_soh_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (!is_dir(dirname($flag))) {
                @mkdir(dirname($flag), 0755, true);
            }
            @file_put_contents($flag, (string) time());
            $ready = true;
            finance_schema_ready($pdo);
        } catch (Throwable $e) {
            error_log('orders_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('orders_statuses')) {
    function orders_statuses(): array
    {
        return [
            'pending'   => ['label' => 'در انتظار بررسی مالی', 'color' => 'warning', 'icon' => 'fa-hourglass-half'],
            'approved'  => ['label' => 'تأیید و ثبت شد', 'color' => 'success', 'icon' => 'fa-circle-check'],
            'rejected'  => ['label' => 'رد شد', 'color' => 'danger', 'icon' => 'fa-circle-xmark'],
            'cancelled' => ['label' => 'لغو شده', 'color' => 'secondary', 'icon' => 'fa-ban'],
        ];
    }
}

if (!function_exists('orders_status_badge')) {
    function orders_status_badge(string $code): string
    {
        $s = orders_statuses()[$code] ?? ['label' => $code, 'color' => 'secondary', 'icon' => 'fa-circle'];
        return '<span class="badge text-bg-' . e($s['color']) . '"><i class="fa-solid ' . e($s['icon']) . '"></i> ' . e($s['label']) . '</span>';
    }
}

if (!function_exists('orders_payment_methods')) {
    /**
     * روشِ پرداختِ همین مبلغ (چطور پول/ارزش رسید). «چک» و «اقساطی» روش نیستند، «شیوه‌ی تسویه» هستند
     * (orders_settle_types) — فقط برای نمایشِ سفارش‌های قدیمی در این فهرست مانده‌اند.
     * $forSelect = true → فقط گزینه‌های قابلِ انتخاب در فرمِ ثبتِ سفارش
     */
    function orders_payment_methods(bool $forSelect = false): array
    {
        $m = [
            'card_to_card' => 'کارت به کارت',
            'bank_deposit' => 'واریز / پایا / ساتنا',
            'pos'          => 'کارتخوان (POS)',
            'online'       => 'درگاه اینترنتی',
            'cash'         => 'وجه نقد',
            'barter'       => 'تهاتر',
        ];
        if (!$forSelect) {
            $m['cheque_clear'] = 'وصول چک';
            $m['cheque'] = 'چک';
            $m['installment'] = 'اقساطی / پیش‌پرداخت';
        }
        return $m;
    }
}

if (!function_exists('orders_settle_types')) {
    /** شیوه‌ی تسویه‌ی کلِ فاکتور */
    function orders_settle_types(): array
    {
        return [
            'full'        => 'تسویه‌ی کامل',
            'installment' => 'اقساطی',
            'cheque'      => 'چکی',
        ];
    }
}

if (!function_exists('orders_ensure_settle_cols')) {
    /** ستون‌های شیوه‌ی تسویه، تهاتر و اطلاعاتِ چک (یک‌بار) */
    function orders_ensure_settle_cols(PDO $pdo): bool
    {
        static $ok = null;
        if ($ok !== null) return $ok;
        $flag = __DIR__ . '/../storage/.orders_settle_cols_v1';
        if (is_file($flag)) return $ok = true;
        $ok = true;
        foreach ([
            ['sales_orders', 'settle_type', 'ALTER TABLE sales_orders ADD COLUMN settle_type VARCHAR(20) DEFAULT NULL'],
            ['sales_orders', 'barter_desc', 'ALTER TABLE sales_orders ADD COLUMN barter_desc VARCHAR(500) DEFAULT NULL'],
            ['sales_order_installments', 'kind', "ALTER TABLE sales_order_installments ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'installment'"],
            ['sales_order_installments', 'cheque_no', 'ALTER TABLE sales_order_installments ADD COLUMN cheque_no VARCHAR(40) DEFAULT NULL'],
            ['sales_order_installments', 'bank', 'ALTER TABLE sales_order_installments ADD COLUMN bank VARCHAR(80) DEFAULT NULL'],
        ] as [$t, $c, $sql]) {
            try {
                $pdo->query("SELECT `$c` FROM `$t` LIMIT 1");
            } catch (Throwable $e) {
                try { $pdo->exec($sql); } catch (Throwable $e2) { $ok = false; error_log('orders_ensure_settle_cols: ' . $e2->getMessage()); }
            }
        }
        if ($ok) @file_put_contents($flag, (string) time());
        return $ok;
    }
}

if (!function_exists('orders_settle_label')) {
    /** برچسبِ شیوه‌ی تسویه‌ی یک سفارش (سفارش‌های قدیمی: از روی اقساط حدس زده می‌شود) */
    function orders_settle_label(array $order, array $installments = []): string
    {
        $t = (string) ($order['settle_type'] ?? '');
        if ($t === '') {
            $t = $installments ? ((($installments[0]['kind'] ?? '') === 'cheque') ? 'cheque' : 'installment') : 'full';
        }
        return orders_settle_types()[$t] ?? $t;
    }
}

if (!function_exists('orders_money')) {
    /** ورودیِ مبلغ (با ارقام فارسی و جداکننده) → عدد صحیح */
    function orders_money($raw): int
    {
        $d = preg_replace('/\D+/', '', normalize_digits((string) $raw));
        return $d === '' ? 0 : (int) $d;
    }
}

if (!function_exists('orders_generate_number')) {
    function orders_generate_number(PDO $pdo): string
    {
        [$jy, $jm, $jd] = gregorian_to_jalali_arr((int) date('Y'), (int) date('m'), (int) date('d'));
        $prefix = sprintf('F%04d%02d%02d-', $jy, $jm, $jd);
        $st = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE order_number LIKE ?');
        $st->execute([$prefix . '%']);
        $seq = (int) $st->fetchColumn() + 1;
        do {
            $num = $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE order_number = ?');
            $chk->execute([$num]);
            $seq++;
        } while ((int) $chk->fetchColumn() > 0);
        return $num;
    }
}

if (!function_exists('orders_get')) {
    function orders_get(PDO $pdo, int $orderId): ?array
    {
        $st = $pdo->prepare('SELECT o.*, c.full_name AS customer_name, c.mobile AS customer_mobile, c.owner_user_id,
                s.full_name AS seller_name, s.role AS seller_role, f.full_name AS finance_name, q.quote_number
            FROM sales_orders o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN users s ON s.id = o.seller_user_id
            LEFT JOIN users f ON f.id = o.finance_user_id
            LEFT JOIN quotes q ON q.id = o.quote_id
            WHERE o.id = ? LIMIT 1');
        $st->execute([$orderId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('orders_items')) {
    function orders_items(PDO $pdo, int $orderId): array
    {
        $st = $pdo->prepare('SELECT * FROM sales_order_items WHERE order_id = ? ORDER BY id');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('orders_files')) {
    function orders_files(PDO $pdo, int $orderId): array
    {
        $st = $pdo->prepare('SELECT f.*, u.full_name AS uploader_name FROM sales_order_files f LEFT JOIN users u ON u.id = f.uploaded_by WHERE f.order_id = ? ORDER BY f.id');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('orders_history')) {
    function orders_history(PDO $pdo, int $orderId): array
    {
        $st = $pdo->prepare('SELECT h.*, u.full_name AS user_name FROM sales_order_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.order_id = ? ORDER BY h.id DESC');
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('orders_add_history')) {
    function orders_add_history(PDO $pdo, int $orderId, ?int $userId, string $action, ?string $from, ?string $to, string $note = ''): void
    {
        try {
            $pdo->prepare('INSERT INTO sales_order_history (order_id, user_id, action, from_status, to_status, note) VALUES (?,?,?,?,?,?)')
                ->execute([$orderId, $userId, $action, $from, $to, $note !== '' ? $note : null]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('orders_active_for_quote')) {
    /** آخرین سفارشِ «غیرِ لغوشده»ی یک پیش‌فاکتور */
    function orders_active_for_quote(PDO $pdo, int $quoteId): ?array
    {
        if (!orders_ready($pdo)) {
            return null;
        }
        $st = $pdo->prepare("SELECT * FROM sales_orders WHERE quote_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1");
        $st->execute([$quoteId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('orders_can_view')) {
    function orders_can_view(PDO $pdo, array $user, array $order): bool
    {
        if (is_super_admin($user) || user_can('finance_orders_view', $user)) {
            return true;
        }
        $uid = (int) $user['id'];
        if ((int) $order['seller_user_id'] === $uid || (int) ($order['owner_user_id'] ?? 0) === $uid) {
            return true;
        }
        if (function_exists('can_manage_service_requests') && can_manage_service_requests($user)) {
            return true;
        }
        if (function_exists('leader_supervises_owner')
            && (leader_supervises_owner($pdo, $user, (int) $order['seller_user_id']) || leader_supervises_owner($pdo, $user, (int) ($order['owner_user_id'] ?? 0)))) {
            return true;
        }
        return false;
    }
}

if (!function_exists('orders_upload_dir')) {
    function orders_upload_dir(): string
    {
        $dir = __DIR__ . '/../uploads/order_receipts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "# دسترسیِ مستقیم ممنوع؛ فایل‌ها فقط از طریق order_file.php نمایش داده می‌شوند\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php http_response_code(403);");
        }
        return $dir;
    }
}

if (!function_exists('orders_normalize_files')) {
    /** $_FILES['receipts'] (چندتایی) → آرایه‌ای از فایل‌ها */
    function orders_normalize_files($f): array
    {
        $out = [];
        if (!is_array($f) || !isset($f['name'])) {
            return $out;
        }
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $n) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
            }
        } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $out[] = $f;
        }
        return $out;
    }
}

if (!function_exists('orders_validate_files')) {
    /** @return string[] خطاها */
    function orders_validate_files(array $files): array
    {
        $errors = [];
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        if (count($files) > 6) {
            $errors[] = 'حداکثر ۶ فایل قابل بارگذاری است.';
        }
        foreach ($files as $f) {
            if ((int) $f['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'بارگذاریِ «' . $f['name'] . '» ناموفق بود.';
                continue;
            }
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext])) {
                $errors[] = 'فرمتِ «' . $f['name'] . '» مجاز نیست (فقط JPG، PNG، WEBP یا PDF).';
                continue;
            }
            if ((int) $f['size'] > 8 * 1024 * 1024) {
                $errors[] = 'حجمِ «' . $f['name'] . '» بیش از ۸ مگابایت است.';
                continue;
            }
            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = (string) finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
            }
            if ($mime !== '' && !in_array($mime, array_values($allowed), true)) {
                $errors[] = 'محتوای «' . $f['name'] . '» تصویر یا PDF معتبر نیست.';
            }
        }
        return $errors;
    }
}

if (!function_exists('orders_store_files')) {
    function orders_store_files(PDO $pdo, int $orderId, array $files, int $userId, ?int $paymentId = null): int
    {
        $dir = orders_upload_dir();
        $sub = date('Y/m');
        if (!is_dir($dir . '/' . $sub)) {
            @mkdir($dir . '/' . $sub, 0755, true);
        }
        $n = 0;
        foreach ($files as $f) {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            $name = 'o' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $rel = $sub . '/' . $name;
            if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $rel)) {
                continue;
            }
            $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
            try {
                $pdo->prepare('INSERT INTO sales_order_files (order_id, file_path, original_name, mime, size_bytes, uploaded_by, payment_id) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$orderId, $rel, mb_substr((string) $f['name'], 0, 250), $mime, (int) $f['size'], $userId, $paymentId]);
            } catch (Throwable $e) {
                $pdo->prepare('INSERT INTO sales_order_files (order_id, file_path, original_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$orderId, $rel, mb_substr((string) $f['name'], 0, 250), $mime, (int) $f['size'], $userId]);
            }
            $n++;
        }
        return $n;
    }
}

if (!function_exists('orders_create_from_quote')) {
    /**
     * @return array{ok:bool, id:?int, message:string}
     */
    function orders_create_from_quote(PDO $pdo, array $quote, array $user, array $payment, array $files): array
    {
        if (!orders_ready($pdo)) {
            return ['ok' => false, 'id' => null, 'message' => 'جدول‌های سفارش آماده نیستند.'];
        }
        if (($user['role'] ?? '') === 'leader' && !(function_exists('user_can') && user_can('finance_orders_decide', $user))) {
            return ['ok' => false, 'id' => null, 'message' => 'سرپرست نمی‌تواند سفارش/فیش ثبت کند؛ فیش را کارشناسِ مشتری (A/B/C) ثبت می‌کند.'];
        }
        if (($quote['status'] ?? '') !== 'locked') {
            return ['ok' => false, 'id' => null, 'message' => 'فقط پیش‌فاکتورِ قفل‌شده قابل تبدیل به فاکتور است.'];
        }
        if (orders_active_for_quote($pdo, (int) $quote['id'])) {
            return ['ok' => false, 'id' => null, 'message' => 'برای این پیش‌فاکتور قبلاً سفارش ثبت شده است.'];
        }
        $itemsSt = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id');
        $itemsSt->execute([(int) $quote['id']]);
        $items = $itemsSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$items) {
            return ['ok' => false, 'id' => null, 'message' => 'پیش‌فاکتور هیچ ردیفی ندارد.'];
        }

        $pdo->beginTransaction();
        try {
            $number = orders_generate_number($pdo);
            $pdo->prepare('INSERT INTO sales_orders
                (order_number, quote_id, customer_id, seller_user_id, customer_owner_id, subtotal, discount_percent, discount_amount, free_amount,
                 tax_percent, tax_amount, total_amount, paid_amount, payment_method, payment_date, payment_ref, payer_name, seller_note, status, submitted_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\',NOW())')
                ->execute([
                    $number, (int) $quote['id'], (int) $quote['customer_id'], (int) $user['id'], $quote['owner_user_id'] ?? null,
                    (int) $quote['subtotal'], (float) $quote['discount_percent'], (int) $quote['discount_amount'], (int) $quote['free_amount'],
                    (float) $quote['tax_percent'], (int) $quote['tax_amount'], (int) $quote['total_amount'],
                    (int) $payment['paid_amount'], $payment['payment_method'], $payment['payment_date'], $payment['payment_ref'] ?: null,
                    $payment['payer_name'] ?: null, $payment['seller_note'] ?: null,
                ]);
            $orderId = (int) $pdo->lastInsertId();
            if (orders_ensure_settle_cols($pdo)) {
                $pdo->prepare('UPDATE sales_orders SET settle_type = ?, barter_desc = ? WHERE id = ?')
                    ->execute([$payment['settle_type'] ?? null, ($payment['barter_desc'] ?? '') !== '' ? $payment['barter_desc'] : null, $orderId]);
            }
            $ins = $pdo->prepare('INSERT INTO sales_order_items (order_id, service_id, title, unit, unit_price, quantity, amount) VALUES (?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $ins->execute([$orderId, $it['service_id'], $it['title_snapshot'], $it['unit_snapshot'], (int) $it['unit_price_snapshot'], (float) $it['quantity'], (int) $it['amount']]);
            }
            // پیش‌پرداخت به‌عنوانِ اولین ردیفِ پرداخت (تا تأییدِ مالی «در انتظار» است)
            $pdo->prepare("INSERT INTO sales_order_payments (order_id, customer_id, kind, amount, paid_at, method, ref, status, recorded_by) VALUES (?,?,'initial',?,?,?,?,'pending',?)")
                ->execute([$orderId, (int) $quote['customer_id'], (int) $payment['paid_amount'], $payment['payment_date'], $payment['payment_method'], $payment['payment_ref'] ?: null, (int) $user['id']]);
            $initialPaymentId = (int) $pdo->lastInsertId();
            orders_store_files($pdo, $orderId, $files, (int) $user['id'], $initialPaymentId);
            if (!empty($payment['installments'])) {
                $withKind = orders_ensure_settle_cols($pdo);
                $insI = $withKind
                    ? $pdo->prepare('INSERT INTO sales_order_installments (order_id, customer_id, seq, amount, due_date, note, created_by, kind, cheque_no, bank) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    : $pdo->prepare('INSERT INTO sales_order_installments (order_id, customer_id, seq, amount, due_date, note, created_by) VALUES (?,?,?,?,?,?,?)');
                foreach (array_values($payment['installments']) as $n => $r) {
                    $vals = [$orderId, (int) $quote['customer_id'], $n + 1, (int) $r['amount'], $r['due_date'], ($r['note'] ?? '') !== '' ? $r['note'] : null, (int) $user['id']];
                    if ($withKind) {
                        $vals[] = ($r['kind'] ?? 'installment') === 'cheque' ? 'cheque' : 'installment';
                        $vals[] = ($r['cheque_no'] ?? '') !== '' ? mb_substr((string) $r['cheque_no'], 0, 40) : null;
                        $vals[] = ($r['bank'] ?? '') !== '' ? mb_substr((string) $r['bank'], 0, 80) : null;
                    }
                    $insI->execute($vals);
                }
            }
            orders_add_history($pdo, $orderId, (int) $user['id'], 'submitted', null, 'pending', 'فاکتور صادر و سفارش برای بررسیِ مالی ارسال شد.'
                . (!empty($payment['installments']) ? ' (' . to_persian_digits((string) count($payment['installments'])) . (($payment['settle_type'] ?? '') === 'cheque' ? ' چک' : ' قسط') . ' برای مانده)' : ''));
            $pdo->commit();
            return ['ok' => true, 'id' => $orderId, 'message' => 'سفارش ' . $number . ' ثبت و برای واحد مالی ارسال شد.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('orders_create_from_quote: ' . $e->getMessage());
            return ['ok' => false, 'id' => null, 'message' => 'خطا در ثبتِ سفارش: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('orders_notify')) {
    /** پیامِ گفتگوی داخلی (best-effort) */
    function orders_notify(PDO $pdo, int $fromUserId, int $toUserId, string $text): void
    {
        if ($toUserId <= 0 || $toUserId === $fromUserId || !function_exists('chat_thread_key')) {
            return;
        }
        try {
            static $col = null;
            if ($col === null) {
                $col = '';
                $cols = $pdo->query('SHOW COLUMNS FROM chat_messages')->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach (['body', 'message', 'content', 'text_body', 'msg'] as $c) {
                    if (in_array($c, $cols, true)) { $col = $c; break; }
                }
            }
            if ($col === '') {
                return;
            }
            $pdo->prepare("INSERT INTO chat_messages (sender_id, recipient_id, thread_key, `$col`, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute([$fromUserId, $toUserId, chat_thread_key($fromUserId, $toUserId), $text]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('orders_decide')) {
    /** تصمیمِ واحد مالی: approved / rejected / pending */
    function orders_decide(PDO $pdo, int $orderId, string $status, array $user, string $note, ?int $confirmedAmount): array
    {
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return ['ok' => false, 'message' => 'وضعیت نامعتبر است.'];
        }
        $order = orders_get($pdo, $orderId);
        if (!$order) {
            return ['ok' => false, 'message' => 'سفارش پیدا نشد.'];
        }
        if ($order['status'] === 'cancelled') {
            return ['ok' => false, 'message' => 'این سفارش توسطِ کارشناس لغو شده است.'];
        }
        if ($status === 'rejected' && trim($note) === '') {
            return ['ok' => false, 'message' => 'برای رد کردن، دلیل را بنویسید تا کارشناس بتواند اصلاح کند.'];
        }
        $from = (string) $order['status'];
        $pdo->prepare('UPDATE sales_orders SET status = ?, finance_user_id = ?, finance_note = ?, confirmed_amount = ?, decided_at = ' . ($status === 'pending' ? 'NULL' : 'NOW()') . ' WHERE id = ?')
            ->execute([$status, (int) $user['id'], $note !== '' ? $note : null, $status === 'approved' ? ($confirmedAmount ?? (int) $order['paid_amount']) : null, $orderId]);
        // پیش‌پرداخت هم‌گام با تصمیمِ مالی
        try {
            $ps = ['approved' => 'confirmed', 'rejected' => 'rejected', 'pending' => 'pending'][$status];
            $amt = $status === 'approved' ? ($confirmedAmount ?? (int) $order['paid_amount']) : (int) $order['paid_amount'];
            $pdo->prepare("UPDATE sales_order_payments SET status = ?, amount = ?, decided_by = ?, decided_at = " . ($status === 'pending' ? 'NULL' : 'NOW()') . " WHERE order_id = ? AND kind = 'initial'")
                ->execute([$ps, $amt, (int) $user['id'], $orderId]);
        } catch (Throwable $e) {
        }
        $actions = ['approved' => 'approved', 'rejected' => 'rejected', 'pending' => 'set_pending'];
        orders_add_history($pdo, $orderId, (int) $user['id'], $actions[$status], $from, $status, $note);

        $label = orders_statuses()[$status]['label'];
        $msg = 'سفارش ' . $order['order_number'] . ' (مشتری: ' . $order['customer_name'] . ') توسط واحد مالی: «' . $label . '»';
        if ($note !== '') {
            $msg .= "\nتوضیح: " . $note;
        }
        orders_notify($pdo, (int) $user['id'], (int) $order['seller_user_id'], $msg);
        return ['ok' => true, 'message' => 'وضعیتِ سفارش به «' . $label . '» تغییر کرد.'];
    }
}

if (!function_exists('orders_count_by_status')) {
    function orders_count_by_status(PDO $pdo, string $status): int
    {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM sales_orders WHERE status = ?');
            $st->execute([$status]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('orders_for_customer')) {
    function orders_for_customer(PDO $pdo, int $customerId): array
    {
        if (!orders_ready($pdo)) {
            return [];
        }
        $st = $pdo->prepare('SELECT o.*, s.full_name AS seller_name FROM sales_orders o LEFT JOIN users s ON s.id = o.seller_user_id
            WHERE o.customer_id = ? ORDER BY o.id DESC');
        $st->execute([$customerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $in = implode(',', array_map('intval', $ids));
            $items = [];
            foreach ($pdo->query("SELECT order_id, title, quantity, unit FROM sales_order_items WHERE order_id IN ($in) ORDER BY id") as $it) {
                $items[(int) $it['order_id']][] = $it;
            }
            foreach ($rows as &$r) {
                $r['items'] = $items[(int) $r['id']] ?? [];
            }
            unset($r);
        }
        return $rows;
    }
}

if (!function_exists('orders_purchased_titles')) {
    /** عنوانِ خدماتی که در سفارش‌های تأییدشده خریداری شده‌اند (برای «خدمات دریافت‌نشده») */
    function orders_purchased_titles(PDO $pdo, int $customerId): array
    {
        if (!orders_ready($pdo)) {
            return [];
        }
        try {
            $st = $pdo->prepare("SELECT DISTINCT i.title FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id WHERE o.customer_id = ? AND o.status = 'approved'");
            $st->execute([$customerId]);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('render_customer_orders_block')) {
    /** کارتِ «سفارش‌ها و خریدهای مشتری» در پرونده‌ی مشتری */
    /**
     * «سفارش‌ها و خریدهای مشتری» — جامع برای کلِ شخص (پروفایل ۳۶۰): فاکتورهای همه‌ی پرونده‌های
     * این شخص با هر کارشناسی، پرداختی و مانده‌ی هر فاکتور، بستانکاری و دفترِ آن.
     */
    function render_customer_orders_block(PDO $pdo, int $customerId, string $base = ''): void
    {
        require_once __DIR__ . '/customer_credit.php';
        $user = current_user() ?: [];
        $pos = cc_position($pdo, $customerId);
        $orders = $pos['orders'];
        $approvedCnt = 0;
        foreach ($orders as $o) {
            if ($o['status'] === 'approved') $approvedCnt++;
        }
        $canCredit = $user && cc_can_manage($user) && cc_ready($pdo);
        $credit = (int) $pos['credit'];
        $debtOrders = array_values(array_filter($orders, static fn($o) => $o['status'] === 'approved' && (int) $o['fin']['balance'] > 0));
        $kinds = cc_kinds();
        $multi = count($pos['ids']) > 1;
        ?>
        <div class="card p-3 p-md-4 mb-3 mt-4" id="customer-orders" style="border:1px solid #e7e2d3;border-radius:16px;border-top:3px solid #22c55e">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
              <span style="width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#dcfce7,#22c55e);color:#064e3b"><i class="fa-solid fa-bag-shopping"></i></span>
              <div>
                <h6 class="mb-0 fw-bold">سفارش‌ها و خریدهای مشتری</h6>
                <div class="small text-muted"><?= to_persian_digits((string) $approvedCnt) ?> سفارشِ تأییدشده — جمع فاکتورها: <?= format_toman((int) $pos['total']) ?><?= $multi ? ' — از ' . to_persian_digits((string) count($pos['ids'])) . ' پرونده‌ی همین شخص (پروفایل ۳۶۰)' : '' ?></div>
              </div>
            </div>
          </div>

          <div class="row g-2 mb-3 text-center">
            <div class="col-6 col-md-3"><div class="border rounded-3 p-2 h-100"><div class="small text-muted">جمع فاکتورها</div><div class="fw-bold"><?= format_toman((int) $pos['total']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded-3 p-2 h-100"><div class="small text-muted">کلِ پرداختیِ تأییدشده</div><div class="fw-bold text-success"><?= format_toman((int) $pos['paid'] + (int) $pos['cancelled_paid']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded-3 p-2 h-100" style="background:<?= $pos['debt'] > 0 ? '#fef2f2' : '#fff' ?>"><div class="small text-muted">بدهکار</div><div class="fw-bold <?= $pos['debt'] > 0 ? 'text-danger' : '' ?>"><?= format_toman((int) $pos['debt']) ?></div></div></div>
            <div class="col-6 col-md-3"><div class="border rounded-3 p-2 h-100" style="background:<?= $credit > 0 ? '#eff6ff' : '#fff' ?>"><div class="small text-muted">بستانکار</div><div class="fw-bold <?= $credit > 0 ? 'text-primary' : '' ?>"><?= format_toman(max(0, $credit)) ?></div></div></div>
          </div>
          <?php if ($credit != 0 || $pos['entries']): ?>
            <div class="small text-muted mb-3">
              <i class="fa-solid fa-calculator"></i> محاسبه‌ی بستانکاری:
              مازادِ پرداختِ فاکتورها <?= format_toman((int) $pos['surplus']) ?>
              <?= $pos['cancelled_paid'] ? ' + پرداختیِ سفارش‌های لغوشده ' . format_toman((int) $pos['cancelled_paid']) : '' ?>
              <?= $pos['adjust'] ? ($pos['adjust'] > 0 ? ' + ' : ' − ') . 'تعدیلِ دستی ' . format_toman(abs((int) $pos['adjust'])) : '' ?>
              <?= $pos['refund'] ? ' − بازپرداخت ' . format_toman((int) $pos['refund']) : '' ?>
              <?= $pos['used'] ? ' − استفاده‌شده در فاکتورها ' . format_toman((int) $pos['used']) : '' ?>
              = <b><?= format_toman($credit) ?></b>
            </div>
          <?php endif; ?>

          <?php if (!$orders): ?>
            <div class="text-muted small py-3 text-center"><i class="fa-regular fa-folder-open"></i> هنوز سفارشی برای این مشتری ثبت نشده. از «پیش‌فاکتورهای مشتری» یک پیش‌فاکتور را قفل کنید و «تبدیل به فاکتور و ثبت سفارش» را بزنید.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>شماره فاکتور</th><th>تاریخ ثبت</th><th>خدمات خریداری‌شده</th><th>مبلغ فاکتور</th><th>پرداختی</th><th>مانده / بستانکار</th><th>کارشناس</th><th>وضعیت</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): $f = $o['fin']; ?>
                  <tr>
                    <td class="text-nowrap fw-semibold"><?= to_persian_digits($o['order_number']) ?>
                      <?php if ((int) $o['customer_id'] !== $customerId): ?><div class="small text-muted fw-normal" title="پرونده‌ی دیگرِ همین شخص"><i class="fa-solid fa-link"></i> پرونده‌ی <?= e((string) ($o['record_owner_name'] ?? '—')) ?></div><?php endif; ?>
                    </td>
                    <td class="text-nowrap"><?= to_jalali($o['created_at']) ?></td>
                    <td class="small"><?php
                        $parts = [];
                        foreach ($o['items'] as $it) {
                            $parts[] = e($it['title']) . ' <span class="text-muted">×' . to_persian_digits((string) (float) $it['quantity']) . '</span>';
                        }
                        if (!$parts && !empty($o['is_legacy'])) {
                            $parts[] = '<span class="badge text-bg-warning"><i class="fa-solid fa-hand-holding-dollar"></i> اقساطِ قبل از سامانه</span>'
                                . (trim((string) ($o['legacy_note'] ?? '')) !== '' ? ' <span class="text-muted">' . e((string) $o['legacy_note']) . '</span>' : '');
                        }
                        echo implode('، ', $parts);
                    ?></td>
                    <td class="text-nowrap"><?= format_toman((int) $o['total_amount']) ?></td>
                    <td class="text-nowrap text-success"><?= in_array($o['status'], ['approved', 'cancelled'], true) ? format_toman((int) $f['paid']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="text-nowrap">
                      <?php if ($o['status'] === 'approved' && $f['balance'] > 0): ?><span class="text-danger fw-semibold"><?= format_toman((int) $f['balance']) ?></span>
                      <?php elseif ((int) $o['credit_part'] > 0): ?><span class="text-primary fw-semibold">+<?= format_toman((int) $o['credit_part']) ?></span>
                      <?php elseif ($o['status'] === 'approved'): ?><span class="text-success small">تسویه</span>
                      <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="small"><?= e($o['seller_name'] ?? '—') ?></td>
                    <td><?= orders_status_badge((string) $o['status']) ?></td>
                    <td><a href="<?= e($base) ?>order_view.php?id=<?= (int) $o['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-eye"></i></a></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php if ($canCredit): ?>
            <div class="row g-3 mt-2">
              <div class="col-lg-6">
                <form method="post" action="<?= e($base) ?>customer_credit.php" class="border rounded-3 p-3 h-100" style="background:#f8fafc">
                  <?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="customer_id" value="<?= $customerId ?>">
                  <div class="fw-bold small mb-2"><i class="fa-solid fa-hand-holding-dollar text-primary"></i> استفاده از بستانکاری در فاکتور</div>
                  <?php if ($credit <= 0): ?>
                    <div class="small text-muted">این مشتری بستانکاری ندارد.</div>
                  <?php elseif (!$debtOrders): ?>
                    <div class="small text-muted">فاکتورِ بدهکاری برای این مشتری نیست؛ بستانکاری برای فاکتورهای بعدی می‌ماند.</div>
                  <?php else: ?>
                    <select name="order_id" class="form-select form-select-sm mb-2" required>
                      <?php foreach ($debtOrders as $o): ?>
                        <option value="<?= (int) $o['id'] ?>"><?= e(to_persian_digits($o['order_number'])) ?> — مانده <?= e(format_toman((int) $o['fin']['balance'])) ?> — کارشناس: <?= e((string) ($o['seller_name'] ?? '—')) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input name="amount" class="form-control form-control-sm mb-2" dir="ltr" inputmode="numeric" placeholder="مبلغ (خالی = حداکثرِ ممکن)">
                    <input name="note" class="form-control form-control-sm mb-2" placeholder="توضیح (اختیاری)">
                    <button class="btn btn-sm btn-primary" onclick="return confirm('بستانکاری روی این فاکتور اعمال شود؟')"><i class="fa-solid fa-check"></i> اعمال</button>
                  <?php endif; ?>
                </form>
              </div>
              <div class="col-lg-6">
                <form method="post" action="<?= e($base) ?>customer_credit.php" class="border rounded-3 p-3 h-100" style="background:#f8fafc">
                  <?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="customer_id" value="<?= $customerId ?>">
                  <div class="fw-bold small mb-2"><i class="fa-solid fa-pen-to-square text-primary"></i> تعیین / تعدیلِ مبلغِ بستانکاری</div>
                  <div class="row g-2">
                    <div class="col-6"><select name="kind" class="form-select form-select-sm">
                      <option value="adjust_add">افزایشِ بستانکاری</option>
                      <option value="adjust_sub">کاهشِ بستانکاری</option>
                      <option value="refund">بازپرداخت به مشتری</option>
                    </select></div>
                    <div class="col-6"><input name="amount" class="form-control form-control-sm" dir="ltr" inputmode="numeric" placeholder="مبلغ (تومان)" required></div>
                    <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="دلیل (الزامی) — مثلاً: واریزِ خارج از سیستم / تخفیفِ مصوب" required></div>
                  </div>
                  <button class="btn btn-sm btn-outline-primary mt-2"><i class="fa-solid fa-floppy-disk"></i> ثبت</button>
                  <div class="small text-muted mt-1">مازادِ پرداختِ فاکتورها خودکار حساب می‌شود؛ این‌جا فقط برای اصلاح یا ثبتِ بازپرداخت است.</div>
                </form>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($pos['entries']): ?>
            <h6 class="fw-bold small mt-3 mb-2"><i class="fa-solid fa-book text-primary"></i> دفترِ بستانکاری</h6>
            <div class="table-responsive">
              <table class="table table-sm align-middle small mb-0">
                <thead class="table-light"><tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>فاکتور</th><th>توضیح</th><th>ثبت‌کننده</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pos['entries'] as $en): $k = $kinds[$en['kind']] ?? ['label' => $en['kind'], 'sign' => 1, 'color' => 'secondary']; $rev = !empty($en['reversed_at']); ?>
                  <tr class="<?= $rev ? 'text-decoration-line-through text-muted' : '' ?>">
                    <td class="text-nowrap"><?= to_jalali(substr((string) $en['created_at'], 0, 10)) ?></td>
                    <td><span class="badge text-bg-<?= e($k['color']) ?>"><?= e($k['label']) ?></span></td>
                    <td class="text-nowrap"><?= $k['sign'] > 0 ? '+' : '−' ?><?= format_toman((int) $en['amount']) ?></td>
                    <td><?php if (!empty($en['order_id'])): ?><a href="<?= e($base) ?>order_view.php?id=<?= (int) $en['order_id'] ?>"><?= e(to_persian_digits((string) $en['order_number'])) ?></a><?php else: ?>—<?php endif; ?></td>
                    <td><?= e((string) ($en['note'] ?? '')) ?><?= $rev ? '<div class="text-muted" style="text-decoration:none">برگشت‌خورده توسطِ ' . e((string) ($en['reverser_name'] ?? '')) . '</div>' : '' ?></td>
                    <td><?= e((string) ($en['creator_name'] ?? '—')) ?></td>
                    <td>
                      <?php if ($canCredit && !$rev): ?>
                        <form method="post" action="<?= e($base) ?>customer_credit.php" onsubmit="var n=prompt('دلیلِ برگشت:'); if(!n) return false; this.note.value=n; return true;">
                          <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="customer_id" value="<?= $customerId ?>">
                          <input type="hidden" name="entry_id" value="<?= (int) $en['id'] ?>"><input type="hidden" name="note" value="">
                          <button class="btn btn-sm btn-outline-danger py-0" title="برگشت"><i class="fa-solid fa-rotate-left"></i></button>
                        </form>
                      <?php endif; ?>
                    </td>
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
