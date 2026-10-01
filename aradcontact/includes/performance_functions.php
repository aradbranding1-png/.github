<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  سهم عملکرد — مدلِ نهایی (مالکیتِ A/B/C، Boxها، سهمِ پایه‌ی نسخه‌دار)
 * ═══════════════════════════════════════════════════════════════════════
 * جایگزینِ کاملِ مدلِ قبلی (تارگت/ساعت/ضریب حذف شد؛ جدول‌های قدیمی فقط برای سابقه دست‌نخورده مانده‌اند).
 *
 * برای هر پرداختِ «تأییدشده»:
 *   خالص = پرداخت ÷ (۱ + درصدِ مالیاتِ فاکتور)            (مبلغِ پرداختی شاملِ مالیات است)
 *   Pool = خالص × درصدِ سهمِ پایه   (درصدِ پایه = نسخه‌ی معتبر در زمانِ ثبتِ سفارش — snapshot)
 *   A = C = B = D = ۲۵٪ Pool
 *   A: همه برای A (یا سازمان) — C: همه برای C (یا سازمان)
 *   B: همه برای B (یا سازمان) — B هم مثلِ A و C فقط «یک نفر» است (قانونِ B1/B2 به بعد حذف شد)
 *   ارجاعِ هم‌سطح: B می‌تواند مشتریِ خودش را به B دیگری، و C به C دیگری (حتی از تیمِ دیگر) ارجاع دهد؛
 *      از آن لحظه گیرنده جایگزینِ قبلی می‌شود و سرپرستِ تیمِ «گیرنده» سهمِ D آن جایگاه را می‌گیرد.
 *   D: سه سهمِ ثابت — هر جایگاهِ A/B/C یک سهم برای سرپرستِ تیمِ صاحبِ همان جایگاه (جایگاهِ خالی یا بدونِ سرپرست ← سهمِ همان جایگاه به سازمان)؛
 *      اگر سفارش را سرپرست مستقیم ثبت کرده: سهمِ D مساوی بینِ سرپرست‌های متمایزِ درگیر + خودِ او
 *   هیچ‌کس سهمِ بخشِ دیگری را نمی‌گیرد؛ جایگاهِ خالیِ A/B/C ← سازمان
 * گرد کردن: هر تقسیم رو به پایین (تومانِ کامل)؛ همه‌ی باقی‌مانده‌ها به سازمان ← جمع = Pool دقیقاً.
 * مالکیت (A/Bها/C/تیم/سرپرست) هنگامِ ثبتِ سفارش snapshot می‌شود و هر محاسبه‌ی پرداخت immutable است.
 */

if (!defined('PS_SCHEMA_FLAG')) {
    define('PS_SCHEMA_FLAG', __DIR__ . '/../storage/.perf_share_schema_v3');
}
const PS_ORG_LABEL = 'سازمان آراد برندینگ';
const PS_UNIT_PERCENT = 25; // هر یک از A/B/C/D همیشه ۲۵٪ Pool

function perf_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (is_file(PS_SCHEMA_FLAG)) { $ready = ps_schema_v4($pdo) && ps_schema_v5($pdo) && ps_schema_v12($pdo); if ($ready) { ps_backfill_c_v6($pdo); ps_recalc_d_rule_v8($pdo); ps_recalc_b_rule_v9($pdo); ps_single_b_v10($pdo); ps_a_rule_v11($pdo); ps_a_d_rule_v14($pdo); } return $ready; }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS ps_base_versions (id INT UNSIGNED NOT NULL AUTO_INCREMENT, percent DECIMAL(6,3) NOT NULL, effective_from DATETIME NOT NULL,
          note VARCHAR(500) DEFAULT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY idx_psbv_from (effective_from)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ps_owners (id INT UNSIGNED NOT NULL AUTO_INCREMENT, person_key INT UNSIGNED NOT NULL, slot CHAR(1) NOT NULL, position INT UNSIGNED NOT NULL DEFAULT 1,
          user_id INT UNSIGNED NOT NULL, team_id INT UNSIGNED DEFAULT NULL, source VARCHAR(20) NOT NULL, customer_id INT UNSIGNED DEFAULT NULL,
          first_interaction_at DATETIME DEFAULT NULL, created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY uq_pso_slot (person_key, slot, position), UNIQUE KEY uq_pso_user (person_key, slot, user_id), KEY idx_pso_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ps_box_items (id INT UNSIGNED NOT NULL AUTO_INCREMENT, box CHAR(1) NOT NULL, customer_id INT UNSIGNED NOT NULL, person_key INT UNSIGNED NOT NULL,
          status VARCHAR(12) NOT NULL DEFAULT 'open', source VARCHAR(20) NOT NULL, note VARCHAR(300) DEFAULT NULL, entered_by INT UNSIGNED DEFAULT NULL, entered_at DATETIME NOT NULL,
          claimed_by INT UNSIGNED DEFAULT NULL, claimed_at DATETIME DEFAULT NULL, claim_token CHAR(32) DEFAULT NULL,
          PRIMARY KEY (id), KEY idx_psb_open (box, status, id), KEY idx_psb_person (person_key), UNIQUE KEY uq_psb_token (claim_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ps_order_snapshots (order_id INT UNSIGNED NOT NULL, person_key INT UNSIGNED NOT NULL, base_version_id INT UNSIGNED NOT NULL,
          base_percent DECIMAL(6,3) NOT NULL, tax_percent DECIMAL(6,3) NOT NULL, owners_json MEDIUMTEXT NOT NULL, registrant_user_id INT UNSIGNED DEFAULT NULL,
          registrant_role VARCHAR(20) DEFAULT NULL, source VARCHAR(12) NOT NULL DEFAULT 'order', created_at DATETIME NOT NULL, PRIMARY KEY (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ps_payment_calcs (id INT UNSIGNED NOT NULL AUTO_INCREMENT, payment_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NOT NULL,
          pay_date DATE NOT NULL, paid_amount BIGINT NOT NULL, tax_percent DECIMAL(6,3) NOT NULL, net_amount DECIMAL(18,2) NOT NULL, tax_amount DECIMAL(18,2) NOT NULL,
          base_version_id INT UNSIGNED NOT NULL, base_percent DECIMAL(6,3) NOT NULL, pool BIGINT NOT NULL, distributed BIGINT NOT NULL, org_total BIGINT NOT NULL,
          snapshot_json MEDIUMTEXT, calculated_by INT UNSIGNED DEFAULT NULL, calculated_at DATETIME NOT NULL, voided_at DATETIME DEFAULT NULL, void_reason VARCHAR(300) DEFAULT NULL,
          PRIMARY KEY (id), KEY idx_pspc_pay (payment_id, voided_at), KEY idx_pspc_order (order_id), KEY idx_pspc_date (pay_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS ps_lines (id INT UNSIGNED NOT NULL AUTO_INCREMENT, calc_id INT UNSIGNED NOT NULL, payment_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NOT NULL,
          pay_date DATE NOT NULL, unit CHAR(1) NOT NULL, slot VARCHAR(12) NOT NULL, user_id INT UNSIGNED DEFAULT NULL, team_id INT UNSIGNED DEFAULT NULL, amount BIGINT NOT NULL,
          reason VARCHAR(500) NOT NULL, voided TINYINT(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (id), KEY idx_psl_calc (calc_id), KEY idx_psl_user (user_id, pay_date), KEY idx_psl_date (pay_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS perf_payouts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED NOT NULL, kind VARCHAR(12) NOT NULL, amount BIGINT NOT NULL,
          paid_at DATE NOT NULL, period_month CHAR(7) DEFAULT NULL, method VARCHAR(40) DEFAULT NULL, ref VARCHAR(100) DEFAULT NULL, note VARCHAR(500) DEFAULT NULL,
          created_by INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, voided_by INT UNSIGNED DEFAULT NULL, voided_at DATETIME DEFAULT NULL, void_reason VARCHAR(300) DEFAULT NULL,
          PRIMARY KEY (id), KEY idx_pp_user_date (user_id, paid_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS perf_audit (id INT UNSIGNED NOT NULL AUTO_INCREMENT, user_id INT UNSIGNED DEFAULT NULL, action VARCHAR(40) NOT NULL, entity VARCHAR(40) NOT NULL,
          entity_id VARCHAR(60) DEFAULT NULL, old_json MEDIUMTEXT, new_json MEDIUMTEXT, reason VARCHAR(1000) DEFAULT NULL, ip VARCHAR(45) DEFAULT NULL, created_at DATETIME NOT NULL,
          PRIMARY KEY (id), KEY idx_pa_entity (entity, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    try {
        foreach ($ddl as $sql) $pdo->exec($sql);
        try { $pdo->query('SELECT ip FROM perf_audit LIMIT 1'); } catch (Throwable $e) { $pdo->exec('ALTER TABLE perf_audit ADD COLUMN ip VARCHAR(45) DEFAULT NULL'); }
        if ((int) $pdo->query('SELECT COUNT(*) FROM ps_base_versions')->fetchColumn() === 0) {
            $pdo->prepare('INSERT INTO ps_base_versions (percent, effective_from, note, created_at) VALUES (10, ?, ?, ?)')
                ->execute(['2000-01-01 00:00:00', 'نسخه‌ی اولیه (۱۰٪)', date('Y-m-d H:i:s')]);
        }
    } catch (Throwable $e) {
        error_log('perf_ready: ' . $e->getMessage());
        return $ready = false;
    }
    if (!is_dir(dirname(PS_SCHEMA_FLAG))) @mkdir(dirname(PS_SCHEMA_FLAG), 0755, true);
    @file_put_contents(PS_SCHEMA_FLAG, (string) time());
    return $ready = ps_schema_v4($pdo) && ps_schema_v5($pdo) && ps_schema_v12($pdo);
}

/** یک‌بار: محاسبه‌ی مجددِ همه‌ی پرداخت‌ها با قانونِ جدیدِ B (بدونِ B2 به بعد ← کلِ سهمِ B برای B1) */
function ps_recalc_b_rule_v9(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_b_rule_v9';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    try {
        @set_time_limit(900);
        $why = 'قانونِ جدیدِ سهمِ B (بدونِ B2 به بعد ← کلِ سهم برای B1) — محاسبه‌ی مجدد';
        // فقط سفارش‌هایی که خطِ «B2 به بعد ندارد ← سازمان» داشته‌اند
        $ids = $pdo->query("SELECT DISTINCT l.order_id FROM ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id WHERE l.voided = 0 AND c.voided_at IS NULL AND l.slot = 'B2+' AND l.user_id IS NULL
            AND EXISTS (SELECT 1 FROM ps_lines x WHERE x.calc_id = l.calc_id AND x.slot = 'B1' AND x.user_id IS NOT NULL)")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $vc = $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE order_id = ? AND voided_at IS NULL');
        $vl = $pdo->prepare('UPDATE ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id SET l.voided = 1 WHERE c.order_id = ? AND c.void_reason = ? AND l.voided = 0');
        foreach ($ids as $oid) {
            $vc->execute([date('Y-m-d H:i:s'), $why, (int) $oid]);
            $vl->execute([(int) $oid, $why]);
            ps_sync_order($pdo, (int) $oid, 0);
        }
    } catch (Throwable $e) {
        error_log('ps_recalc_b_rule_v9: ' . $e->getMessage());
    }
}

/** یک‌بار: محاسبه‌ی مجددِ همه‌ی پرداخت‌ها با قانونِ جدیدِ سهمِ سرپرست (D فقط بینِ جایگاه‌های پُر) */
function ps_recalc_d_rule_v8(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_d_rule_v8';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    try {
        @set_time_limit(900);
        $ids = $pdo->query('SELECT DISTINCT order_id FROM ps_payment_calcs WHERE voided_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $vc = $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE order_id = ? AND voided_at IS NULL');
        $vl = $pdo->prepare('UPDATE ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id SET l.voided = 1 WHERE c.order_id = ? AND c.void_reason = ? AND l.voided = 0');
        $why = 'قانونِ جدیدِ سهمِ سرپرست (D فقط بینِ جایگاه‌های پُر) — محاسبه‌ی مجدد';
        foreach ($ids as $oid) {
            $vc->execute([date('Y-m-d H:i:s'), $why, (int) $oid]);
            $vl->execute([(int) $oid, $why]);
            ps_sync_order($pdo, (int) $oid, 0);
        }
    } catch (Throwable $e) {
        error_log('ps_recalc_d_rule_v8: ' . $e->getMessage());
    }
}

/**
 * یک‌بار: سفارش‌هایی که قبل از قانونِ «C = کارشناسِ C که پول گرفته» محاسبه شده‌اند (C خالی ← سازمان)
 * دوباره بررسی و در صورتِ ثبت توسطِ کارشناسِ C، با C دوباره محاسبه می‌شوند.
 */
function ps_backfill_c_v6(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_backfill_registrant_v7';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time()); // قبل از اجرا (تا درخواستِ هم‌زمان دوباره اجرا نکند)
    try {
        @set_time_limit(600);
        $ids = $pdo->query("SELECT s.order_id FROM ps_order_snapshots s JOIN sales_orders o ON o.id = s.order_id JOIN users u ON u.id = COALESCE(s.registrant_user_id, o.seller_user_id)
            WHERE u.role IN ('A','B','C') LIMIT 10000")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $oid) ps_sync_order($pdo, (int) $oid, 0);
    } catch (Throwable $e) {
        error_log('ps_backfill_c_v6: ' . $e->getMessage());
    }
}

/**
 * نسخه‌ی ۵: اولویتِ تیمی در دریافت از Box.
 *   origin_team_id  = تیمِ کسی که «اولین بار» مشتری را وارد زنجیره کرد (معمولاً A که به Box B ارجاع داد) — در ارجاع‌های بعدی ثابت می‌ماند
 *   entered_team_id = تیمِ کسی که مشتری را در همین Box ریخت
 */
function ps_schema_v5(PDO $pdo): bool
{
    $flag = __DIR__ . '/../storage/.perf_share_schema_v5';
    if (is_file($flag)) return true;
    try {
        foreach (['origin_team_id', 'entered_team_id'] as $c) {
            try { $pdo->query("SELECT $c FROM ps_box_items LIMIT 1"); }
            catch (Throwable $e) { $pdo->exec("ALTER TABLE ps_box_items ADD COLUMN $c INT UNSIGNED DEFAULT NULL"); }
        }
        try { $pdo->exec('ALTER TABLE ps_box_items ADD KEY idx_psb_team (box, status, origin_team_id, entered_team_id)'); } catch (Throwable $e) {}
        // مواردِ قبلی: تیمِ ارجاع‌دهنده
        $pdo->exec('UPDATE ps_box_items b JOIN users u ON u.id = b.entered_by SET b.entered_team_id = u.team_id WHERE b.entered_team_id IS NULL');
        $pdo->exec('UPDATE ps_box_items SET origin_team_id = entered_team_id WHERE origin_team_id IS NULL');
    } catch (Throwable $e) {
        error_log('ps_schema_v5: ' . $e->getMessage());
        return false;
    }
    @file_put_contents($flag, (string) time());
    return true;
}

/**
 * نسخه‌ی ۱۲: موردِ «اختصاصی» در Box.
 *   reserved_user_id = فقط همین کارشناس می‌تواند این مورد را از Box دریافت کند (و اول از همه به او پیشنهاد می‌شود).
 *   کاربرد: مشتری‌ای که C دارد و B دوباره به Box C ارجاعش می‌دهد ← انحصاراً به همان C برمی‌گردد.
 */
function ps_schema_v12(PDO $pdo): bool
{
    $flag = __DIR__ . '/../storage/.perf_share_schema_v12';
    if (is_file($flag)) return true;
    try {
        try { $pdo->query('SELECT reserved_user_id FROM ps_box_items LIMIT 1'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE ps_box_items ADD COLUMN reserved_user_id INT UNSIGNED DEFAULT NULL'); }
        try { $pdo->exec('ALTER TABLE ps_box_items ADD KEY idx_psb_reserved (box, status, reserved_user_id)'); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log('ps_schema_v12: ' . $e->getMessage());
        return false;
    }
    @file_put_contents($flag, (string) time());
    return true;
}

/**
 * نسخه‌ی ۴: پرونده‌ی مشتریِ داخلِ Box از لیستِ کارشناسِ قبلی خارج می‌شود (مالک = کاربرِ سیستمیِ «Box مشتریان»)
 * و با دریافت، همان پرونده (با همه‌ی پیگیری‌ها و روندِ زمانی) به کارشناسِ بعدی می‌رسد.
 */
function ps_schema_v4(PDO $pdo): bool
{
    $flag = __DIR__ . '/../storage/.perf_share_schema_v4';
    if (is_file($flag)) return true;
    try {
        try { $pdo->query('SELECT prev_owner_id FROM ps_box_items LIMIT 1'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE ps_box_items ADD COLUMN prev_owner_id INT UNSIGNED DEFAULT NULL'); }
        // مواردِ بازِ قبلی (که هنوز در لیستِ ارجاع‌دهنده بودند) ← به Box منتقل می‌شوند
        $box = ps_box_user_id($pdo);
        if ($box) {
            $pdo->prepare("UPDATE ps_box_items b JOIN customers c ON c.id = b.customer_id SET b.prev_owner_id = c.owner_user_id, c.owner_user_id = ?
                WHERE b.status = 'open' AND c.owner_user_id <> ?")->execute([$box, $box]);
        }
    } catch (Throwable $e) {
        error_log('ps_schema_v4: ' . $e->getMessage());
        return false;
    }
    @file_put_contents($flag, (string) time());
    return true;
}

/** کاربرِ سیستمیِ «Box مشتریان» (غیرفعال، بدونِ امکانِ ورود) — مالکِ پرونده‌هایی که در Box هستند */
function ps_box_user_id(PDO $pdo): int
{
    static $id = null;
    if ($id !== null) return $id;
    $st = $pdo->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
    $st->execute(['BOX-SYSTEM']);
    $id = (int) $st->fetchColumn();
    if (!$id) {
        try {
            $pdo->prepare("INSERT INTO users (full_name, mobile, password_hash, role, is_approved, is_active) VALUES (?, 'BOX-SYSTEM', ?, 'admin', 0, 0)")
                ->execute(['Box مشتریان (سیستم)', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('ps_box_user_id: ' . $e->getMessage());
            $id = 0;
        }
    }
    return $id;
}

/** انتقالِ همان پرونده (نه کپی) به کارشناسِ جدید: پیگیری‌ها و روندِ زمانی همراهش می‌رود */
function ps_transfer_record(PDO $pdo, int $customerId, int $toUser, int $byUser, string $why): void
{
    $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ?')->execute([$toUser, $customerId]);
    if (function_exists('get_or_create_relation')) { try { get_or_create_relation($pdo, $customerId, $toUser, 'box_claim', true); } catch (Throwable $e) {} }
    ps_activity($pdo, $customerId, $byUser, $why);
}

/**
 * زمانی که این کاربر این پرونده را از Box گرفته؛ هرچه قبل از آن ثبت شده (پیگیری/روندِ زمانیِ کارشناس‌های قبلی)
 * برای او قابلِ مشاهده است (تاریخچه‌ی تحویل). null = از Box نگرفته.
 */
function ps_inherit_cutoff(PDO $pdo, int $customerId, int $userId): ?string
{
    try {
        $st = $pdo->prepare("SELECT MAX(claimed_at) FROM ps_box_items WHERE customer_id = ? AND claimed_by = ? AND status = 'claimed'");
        $st->execute([$customerId, $userId]);
        $v = $st->fetchColumn();
        return $v ? (string) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* =========================================================================
   مجوز / Audit
   ========================================================================= */

function perf_can(string $what, array $user): bool
{
    if (is_super_admin($user)) return true;
    $map = ['view_all' => 'perf_view_all', 'rules' => 'perf_rules_manage', 'owners' => 'perf_ownership_manage', 'payouts' => 'perf_payouts_manage',
        'own' => 'perf_view_own', 'calc_edit' => 'perf_calc_edit', 'box_A' => 'box_a_access', 'box_B' => 'box_b_access', 'box_C' => 'box_c_access', 'box_manage' => 'box_manage'];
    if ($what === 'own' && user_can('perf_view_all', $user)) return true;
    return user_can($map[$what] ?? $what, $user);
}

function perf_audit(PDO $pdo, int $userId, string $action, string $entity, $entityId, $old = null, $new = null, ?string $reason = null): void
{
    try {
        $pdo->prepare('INSERT INTO perf_audit (user_id, action, entity, entity_id, old_json, new_json, reason, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$userId ?: null, $action, $entity, $entityId !== null ? (string) $entityId : null,
                $old !== null ? json_encode($old, JSON_UNESCAPED_UNICODE) : null, $new !== null ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                $reason !== null ? mb_substr($reason, 0, 1000) : null, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('perf_audit: ' . $e->getMessage());
    }
}

/* =========================================================================
   موتورِ محاسبه (خالص — بدونِ دیتابیس؛ تست‌پذیر)
   ========================================================================= */

/** مالیاتِ داخلِ پرداخت: خالص = پرداخت ÷ (۱ + مالیات) — دو رقمِ اعشار (نمایش) */
function ps_net(int $paid, float $taxPct): array
{
    $net = round($paid * 100 / (100 + $taxPct), 2);
    return ['net' => $net, 'tax' => round($paid - $net, 2)];
}

/** Pool به تومانِ کامل (رو به پایین) — مستقیم از پرداخت تا خطای اعشار جمع نشود */
function ps_pool(int $paid, float $taxPct, float $basePct): int
{
    $num = $paid * (int) round($basePct * 1000);
    $den = (int) round((100 + $taxPct) * 1000);
    return intdiv($num, $den);
}

/**
 * تقسیمِ Pool طبقِ مالکیتِ snapshot‌شده.
 * $own = [
 *   'A' => ['user_id'=>, 'team_id'=>, 'leader_id'=>, 'name'=>, 'leader_name'=>] | null,
 *   'B' => [ ['position'=>1|2|…, 'user_id'=>, 'team_id'=>, 'leader_id'=>, …], … ],
 *   'C' => [...] | null,
 *   'direct_d' => ['user_id'=>, 'name'=>] | null   // سرپرستی که مستقیم سفارش ثبت کرده
 * ]
 * @return array{lines: array, distributed: int, org_total: int}
 */
function ps_distribute(int $pool, array $own): array
{
    $lines = [];
    $unit = intdiv($pool * PS_UNIT_PERCENT, 100);
    $rem0 = $pool - 4 * $unit;
    $org = static function (array &$lines, string $u, string $slot, int $amt, string $why): void {
        if ($amt > 0) $lines[] = ['unit' => $u, 'slot' => $slot, 'user_id' => null, 'team_id' => null, 'amount' => $amt, 'reason' => $why];
    };
    $team = static fn($o) => $o && !empty($o['team_id']) ? ' / تیم ' . $o['team_id'] : '';

    // A
    $a = $own['A'] ?? null;
    if ($a) $lines[] = ['unit' => 'A', 'slot' => 'A', 'user_id' => (int) $a['user_id'], 'team_id' => $a['team_id'] ?? null, 'amount' => $unit,
        'reason' => 'A مشتری = ' . ($a['name'] ?? '#' . $a['user_id']) . $team($a) . ' / کلِ سهمِ A (۲۵٪)' . (!empty($a['basis']) ? ' / مبنا: ' . $a['basis'] : '')];
    else $org($lines, 'A', 'A', $unit, 'A ندارد' . (!empty($own['A_note']) ? ' (کارشناسِ قبلی: ' . $own['A_note'] . ')' : '') . ' ← سهمِ A به سازمان');

    // C
    $c = $own['C'] ?? null;
    if ($c) $lines[] = ['unit' => 'C', 'slot' => 'C', 'user_id' => (int) $c['user_id'], 'team_id' => $c['team_id'] ?? null, 'amount' => $unit,
        'reason' => 'C مشتری = ' . ($c['name'] ?? '#' . $c['user_id']) . $team($c) . ' / کلِ سهمِ C (۲۵٪)' . (!empty($c['basis']) ? ' / مبنا: ' . $c['basis'] : '')];
    else $org($lines, 'C', 'C', $unit, 'C ندارد ← سهمِ C به سازمان');

    // B — فقط یک نفر (مثلِ A و C). snapshotهای قدیمی که چند B داشتند: همان B اول (کوچک‌ترین جایگاه) کلِ سهم را می‌گیرد.
    $bs = array_values($own['B'] ?? []);
    usort($bs, static fn($x, $y) => (int) ($x['position'] ?? 1) <=> (int) ($y['position'] ?? 1));
    $bEff = $bs[0] ?? null;
    if ($bEff) $lines[] = ['unit' => 'B', 'slot' => 'B', 'user_id' => (int) $bEff['user_id'], 'team_id' => $bEff['team_id'] ?? null, 'amount' => $unit,
        'reason' => 'B مشتری = ' . ($bEff['name'] ?? '#' . $bEff['user_id']) . $team($bEff) . ' / کلِ سهمِ B (۲۵٪)' . (!empty($bEff['basis']) ? ' / مبنا: ' . $bEff['basis'] : '')];
    else $org($lines, 'B', 'B', $unit, 'B ندارد ← سهمِ B به سازمان');

    // D
    $direct = $own['direct_d'] ?? null;
    if ($direct) {
        // سفارشِ مستقیمِ سرپرست: سهمِ D به‌طورِ مساوی بینِ سرپرست‌های «متمایزِ» درگیر
        // (سرپرستِ تیمِ A، B مؤثر، C — هر کدام یک بار) + سرپرستِ ثبت‌کننده
        $leaders = [];
        foreach (['A' => $a, 'B' => $bEff, 'C' => $c] as $slot => $o) {
            if ($o && !empty($o['leader_id'])) {
                $lid = (int) $o['leader_id'];
                $leaders[$lid] = $leaders[$lid] ?? ['user_id' => $lid, 'team_id' => $o['team_id'] ?? null, 'name' => $o['leader_name'] ?? ('#' . $lid), 'slots' => []];
                $leaders[$lid]['slots'][] = $slot;
            }
        }
        $did = (int) $direct['user_id'];
        $leaders[$did] = $leaders[$did] ?? ['user_id' => $did, 'team_id' => $direct['team_id'] ?? null, 'name' => $direct['name'] ?? ('#' . $did), 'slots' => []];
        $leaders[$did]['direct'] = true;
        $each = intdiv($unit, count($leaders));
        foreach ($leaders as $L) {
            $why = [];
            if ($L['slots']) $why[] = 'سرپرستِ تیمِ جایگاهِ ' . implode('/', $L['slots']);
            if (!empty($L['direct'])) $why[] = 'ثبت‌کننده‌ی مستقیمِ سفارش';
            $lines[] = ['unit' => 'D', 'slot' => 'D', 'user_id' => $L['user_id'], 'team_id' => $L['team_id'], 'amount' => $each,
                'reason' => 'سفارشِ مستقیمِ سرپرست ← سهمِ D مساوی بینِ ' . count($leaders) . ' سرپرست: ' . $L['name'] . ' (' . implode(' + ', $why) . ')'];
        }
        $org($lines, 'D', 'D-round', $unit - $each * count($leaders), 'باقی‌مانده‌ی گرد کردنِ تقسیمِ D ← سازمان');
    } else {
        // حالتِ عادی: سهمِ D سه قسمتِ ثابت است — یک قسمت برای هر جایگاه (A / B / C)؛ قسمتِ هر جایگاه به سرپرستِ تیمِ صاحبِ همان جایگاه.
        // جایگاهِ خالی (یا صاحبِ بدونِ سرپرست) ← قسمتِ همان جایگاه به سازمان (سهمِ سرپرستِ دیگر زیاد نمی‌شود).
        $each = intdiv($unit, 3);
        foreach (['A' => $a, 'B' => $bEff, 'C' => $c] as $slot => $o) {
            if ($o && !empty($o['leader_id'])) {
                $lines[] = ['unit' => 'D', 'slot' => 'D(' . $slot . ')', 'user_id' => (int) $o['leader_id'], 'team_id' => $o['team_id'] ?? null, 'amount' => $each,
                    'reason' => 'جایگاهِ ' . $slot . ' ← تیم ' . ($o['team_id'] ?? '?') . ' ← سرپرست ' . ($o['leader_name'] ?? '#' . $o['leader_id']) . ' ← یک سهم از ۳ سهمِ D'];
            } elseif ($o) {
                $org($lines, 'D', 'D(' . $slot . ')', $each, 'تیمِ صاحبِ جایگاهِ ' . $slot . ' سرپرست ندارد ← یک سهم از ۳ سهمِ D به سازمان');
            } else {
                $org($lines, 'D', 'D(' . $slot . ')', $each, 'جایگاهِ ' . $slot . ' خالی است (کارشناس ندارد) ← یک سهم از ۳ سهمِ D به سازمان');
            }
        }
        $org($lines, 'D', 'D-round', $unit - $each * 3, 'باقی‌مانده‌ی گرد کردنِ تقسیمِ D ← سازمان');
    }
    $org($lines, 'O', 'round', $rem0, 'باقی‌مانده‌ی گرد کردنِ تقسیمِ ۲۵٪ها ← سازمان');

    $dist = 0; $orgT = 0;
    foreach ($lines as $l) { if ($l['user_id']) $dist += $l['amount']; else $orgT += $l['amount']; }
    return ['lines' => $lines, 'distributed' => $dist, 'org_total' => $orgT];
}

/* =========================================================================
   سهمِ پایه (نسخه‌دار)
   ========================================================================= */

function ps_base_version_at(PDO $pdo, string $at): array
{
    $st = $pdo->prepare('SELECT * FROM ps_base_versions WHERE effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1');
    $st->execute([$at]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) $v = $pdo->query('SELECT * FROM ps_base_versions ORDER BY effective_from ASC, id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $v ?: ['id' => 0, 'percent' => 10];
}

function ps_base_version_add(PDO $pdo, float $percent, string $from, string $note, int $userId): array
{
    if ($percent <= 0 || $percent > 100) return ['ok' => false, 'message' => 'درصدِ سهمِ پایه باید بین ۰ و ۱۰۰ باشد.'];
    if ($from < date('Y-m-d H:i:s', time() - 60)) return ['ok' => false, 'message' => 'شروعِ اعتبار نمی‌تواند در گذشته باشد (سفارش‌های قبلی تغییر نمی‌کنند؛ نسخه‌ی جدید فقط برای آینده است).'];
    $cur = ps_base_version_at($pdo, date('Y-m-d H:i:s'));
    $pdo->prepare('INSERT INTO ps_base_versions (percent, effective_from, note, created_by, created_at) VALUES (?,?,?,?,?)')
        ->execute([$percent, $from, mb_substr($note, 0, 500) ?: null, $userId, date('Y-m-d H:i:s')]);
    $id = (int) $pdo->lastInsertId();
    perf_audit($pdo, $userId, 'base_share_version', 'ps_base_versions', $id, ['percent' => (float) $cur['percent']], ['percent' => $percent, 'from' => $from], $note);
    return ['ok' => true, 'message' => 'نسخه‌ی جدیدِ سهمِ پایه (' . $percent . '٪) از ' . $from . ' ثبت شد.'];
}

/* =========================================================================
   مالکیتِ مشتری (پروفایل ۳۶۰)
   ========================================================================= */

/** کلیدِ شخص = کوچک‌ترین شناسه‌ی پرونده در پروفایلِ ۳۶۰ */
function ps_person_key(PDO $pdo, int $customerId): int
{
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = cc_person_ids($pdo, $customerId);
    return $ids ? min($ids) : $customerId;
}

function ps_user_row(PDO $pdo, int $uid): ?array
{
    static $c = [];
    if (!array_key_exists($uid, $c)) {
        $st = $pdo->prepare('SELECT u.id, u.full_name, u.role, u.team_id, t.leader_user_id, l.full_name AS leader_name FROM users u
            LEFT JOIN teams t ON t.id = u.team_id LEFT JOIN users l ON l.id = t.leader_user_id WHERE u.id = ?');
        $st->execute([$uid]);
        $c[$uid] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    return $c[$uid];
}

/** مالکانِ فعلیِ یک شخص (برای نمایش/کنترل؛ در محاسبه همیشه از snapshotِ سفارش استفاده می‌شود) */
function ps_owners(PDO $pdo, int $customerId): array
{
    $pk = ps_person_key($pdo, $customerId);
    $st = $pdo->prepare('SELECT o.*, u.full_name, u.role FROM ps_owners o JOIN users u ON u.id = o.user_id WHERE o.person_key = ? ORDER BY o.slot, o.position');
    $st->execute([$pk]);
    $out = ['person_key' => $pk, 'A' => null, 'B' => [], 'C' => null];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $u = ps_user_row($pdo, (int) $r['user_id']);
        $row = $r + ['leader_id' => $u['leader_user_id'] ?? null, 'leader_name' => $u['leader_name'] ?? null, 'name' => $r['full_name'], 'team_id' => $u['team_id'] ?? $r['team_id']];
        if ($r['slot'] === 'B') $out['B'][] = $row; else $out[$r['slot']] = $row;
    }
    return $out;
}

/** ثبتِ مالک (A / B / C هر کدام یکتا — یک نفر) — تکراری/تداخل با کلیدِ یکتا رد می‌شود؛ برای جایگزینی از ps_owner_set استفاده کنید */
function ps_owner_add(PDO $pdo, int $customerId, string $slot, int $userId, string $source, int $byUser, ?int $position = null, ?string $firstAt = null): array
{
    $pk = ps_person_key($pdo, $customerId);
    $u = ps_user_row($pdo, $userId);
    if (!$u) return ['ok' => false, 'message' => 'کاربر پیدا نشد.'];
    // A / B / C هر کدام فقط یک نفر (جایگاه همیشه ۱)
    $position = 1;
    try {
        $pdo->prepare('INSERT INTO ps_owners (person_key, slot, position, user_id, team_id, source, customer_id, first_interaction_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$pk, $slot, $position, $userId, $u['team_id'] ?: null, $source, $customerId, $firstAt ?? date('Y-m-d H:i:s'), $byUser ?: null, date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            $cur = ps_owners($pdo, $customerId);
            $who = $slot === 'B' ? implode('، ', array_map(static fn($b) => $b['full_name'], $cur['B'])) : ($cur[$slot]['full_name'] ?? '');
            return ['ok' => false, 'message' => 'این جایگاه قبلاً ثبت شده (' . $slot . ': ' . $who . ').'];
        }
        throw $e;
    }
    perf_audit($pdo, $byUser, 'owner_add', 'ps_owners', $pk, null, ['slot' => $slot, 'position' => $position, 'user_id' => $userId, 'team_id' => $u['team_id'], 'source' => $source]);
    return ['ok' => true, 'message' => $slot . ' = ' . $u['full_name'] . ' ثبت شد.', 'position' => $position];
}

function ps_owner_remove(PDO $pdo, int $ownerId, int $byUser, string $reason): array
{
    $st = $pdo->prepare('SELECT * FROM ps_owners WHERE id = ?');
    $st->execute([$ownerId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'message' => 'پیدا نشد.'];
    if (mb_strlen(trim($reason)) < 3) return ['ok' => false, 'message' => 'دلیل را بنویسید.'];
    $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([$ownerId]);
    perf_audit($pdo, $byUser, 'owner_remove', 'ps_owners', $r['person_key'], $r, null, $reason);
    return ['ok' => true, 'message' => 'جایگاه حذف شد (سفارش‌های قبلی تغییر نمی‌کنند).'];
}

/**
 * (قبلاً: تعاملِ B با مشتری ← ثبتِ B2 به بعد). قانونِ B2 به بعد حذف شد و B هم مثلِ A و C فقط یک نفر است
 * (از Box B، ارجاعِ هم‌سطح، دریافتِ پول، یا تعیینِ دستی). این تابع برای سازگاری با فراخوانی‌های قبلی باقی مانده و کاری نمی‌کند.
 */
function ps_note_interaction(PDO $pdo, int $customerId, int $userId, string $source): void
{
}

/**
 * کنترلِ ثبتِ سفارش (سمتِ سرور): A/C فقط صاحبِ همان جایگاه؛ B فقط Bهای ثبت‌شده؛ سرپرست و مدیران آزاد.
 * @return string|null پیغامِ خطا یا null (مجاز)
 */
function ps_validate_order_owner(PDO $pdo, int $customerId, array $user): ?string
{
    if (!perf_ready($pdo)) return null;
    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['A', 'B', 'C'], true)) return null;
    $own = ps_owners($pdo, $customerId);
    $uid = (int) $user['id'];
    if ($role === 'A' || $role === 'C') {
        $o = $own[$role];
        if ($o && (int) $o['user_id'] !== $uid) {
            return 'شما ' . $role . ' این مشتری نیستید. ' . $role . ' ثبت‌شده برای این مشتری: ' . $o['full_name']
                . ' (تیم ' . ($o['team_id'] ?: '—') . '). برای ثبتِ سفارش و دریافتِ سهمِ عملکردِ این مشتری، ابتدا باید با ' . $role . ' ثبت‌شده مرتبط شوید.';
        }
        return null;
    }
    // B
    if ($own['B']) {
        foreach ($own['B'] as $b) if ((int) $b['user_id'] === $uid) return null;
        return 'شما B این مشتری نیستید. B ثبت‌شده برای این مشتری: ' . $own['B'][0]['full_name'] . ' (تیم ' . ($own['B'][0]['team_id'] ?: '—') . ')'
            . '. برای ثبتِ سفارش، B فعلی باید مشتری را به شما ارجاع دهد (ارجاعِ هم‌سطح).';
    }
    return null;
}

/* =========================================================================
   Boxها (صفِ مشترک؛ دریافتِ Atomic)
   ========================================================================= */

function ps_box_open_item(PDO $pdo, int $pk, string $box): ?array
{
    $st = $pdo->prepare("SELECT * FROM ps_box_items WHERE person_key = ? AND box = ? AND status = 'open' LIMIT 1");
    $st->execute([$pk, $box]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** تعدادِ مواردِ بازِ Box که این کارشناس اجازه‌ی دریافتش را دارد (آزاد + اختصاصیِ خودش) */
function ps_box_claimable_count(PDO $pdo, string $box, int $uid): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM ps_box_items WHERE box = ? AND status = 'open' AND (reserved_user_id IS NULL OR reserved_user_id = ?)");
    $st->execute([$box, $uid]);
    return (int) $st->fetchColumn();
}

/** سابقه‌ی «احیای مشتری توسطِ C» برای این شخص (همه‌ی پرونده‌های ۳۶۰، هر وضعیتی) — یا null */
function ps_c_revive_history(PDO $pdo, int $customerId): ?array
{
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = array_map('intval', cc_person_ids($pdo, $customerId) ?: [$customerId]);
    $in = implode(',', $ids);
    $pk = ps_person_key($pdo, $customerId);
    $st = $pdo->prepare("SELECT b.*, u.full_name AS by_name FROM ps_box_items b LEFT JOIN users u ON u.id = b.entered_by
        WHERE b.box = 'A' AND b.source = 'c_revive' AND (b.person_key = ? OR b.customer_id IN ($in)) ORDER BY b.id ASC LIMIT 1");
    $st->execute([$pk]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * آیا کارشناسِ C می‌تواند این مشتری را به Box A منتقل کند؟ (احیای مشتری)
 * شرط‌ها — هدف: هر مشتری فقط یک A، یک B و یک C داشته باشد و چند نفر هم‌زمان با او در ارتباط نباشند:
 *   ۱) کاربر نقشِ C دارد و پرونده در لیستِ خودش است
 *   ۲) هیچ‌وقت (از هیچ مسیری، با هیچ پرونده‌ی همان شماره/کدِ ملی) وارد Box A نشده — پس هر مشتری فقط یک بار احیا می‌شود
 *   ۳) A و B ندارد؛ C یا ندارد یا خودِ همین کارشناس است
 *   ۴) همین الان در هیچ Boxی نیست
 *   ۵) هیچ کارشناسِ دیگری با این شماره ارتباط نداشته: پرونده‌ی دیگری با همین شماره/کدِ ملی نزدِ کسِ دیگری نیست،
 *      رابطه‌ی ثبت‌شده با کارشناسِ دیگر ندارد، و کارشناسِ دیگری (A/B/C/سرپرست) برایش پیگیری ثبت نکرده
 * @return string|null دلیلِ رد، یا null (مجاز)
 */
function ps_c_revive_check(PDO $pdo, int $customerId, array $user): ?string
{
    $uid = (int) $user['id'];
    if (($user['role'] ?? '') !== 'C') return 'فقط کارشناسانِ واحدِ C می‌توانند مشتری را به Box A منتقل کنند.';
    $st = $pdo->prepare('SELECT id, owner_user_id FROM customers WHERE id = ?');
    $st->execute([$customerId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return 'مشتری پیدا نشد.';
    if ((int) $c['owner_user_id'] !== $uid) return 'این مشتری در لیستِ شما نیست.';
    if (ps_c_revive_history($pdo, $customerId)) return 'قبلاً یک بار توسطِ C به Box A منتقل شده';
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = array_map('intval', cc_person_ids($pdo, $customerId) ?: [$customerId]);
    $in = implode(',', $ids);
    $pk = ps_person_key($pdo, $customerId);
    $q = $pdo->prepare("SELECT COUNT(*) FROM ps_box_items WHERE box = 'A' AND (person_key = ? OR customer_id IN ($in))");
    $q->execute([$pk]);
    if ((int) $q->fetchColumn() > 0) return 'قبلاً وارد Box A شده';
    $own = ps_owners($pdo, $customerId);
    if ($own['A']) return 'برایش A تعریف شده (' . $own['A']['full_name'] . ')';
    if ($own['B']) return 'برایش B تعریف شده (' . $own['B'][0]['full_name'] . ')';
    if ($own['C'] && (int) $own['C']['user_id'] !== $uid) return 'C ِ دیگری دارد (' . $own['C']['full_name'] . ')';
    $q = $pdo->prepare("SELECT COUNT(*) FROM ps_box_items WHERE status = 'open' AND (person_key = ? OR customer_id IN ($in))");
    $q->execute([$pk]);
    if ((int) $q->fetchColumn() > 0) return 'همین الان در یکی از Boxهاست';
    // پرونده‌های دیگرِ همین شخص (همان شماره/کدِ ملی) نزدِ کارشناسِ دیگر
    $q = $pdo->prepare("SELECT u.full_name FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.id IN ($in) AND c.owner_user_id <> ? LIMIT 1");
    $q->execute([$uid]);
    if (($n = $q->fetchColumn()) !== false) return 'کارشناسِ دیگری با همین شماره پرونده دارد (' . $n . ')';
    // رابطه‌های ثبت‌شده (مدلِ چندکارشناسه)
    try {
        $q = $pdo->prepare("SELECT u.full_name FROM customer_employee_relations r JOIN users u ON u.id = r.employee_id WHERE r.customer_id IN ($in) AND r.employee_id <> ? AND u.role IN ('A','B','C','leader') LIMIT 1");
        $q->execute([$uid]);
        if (($n = $q->fetchColumn()) !== false) return 'کارشناسِ دیگری قبلاً با این مشتری ارتباط داشته (' . $n . ')';
    } catch (Throwable $e) {
        // جدولِ رابطه‌ها هنوز ساخته نشده
    }
    // پیگیری‌های ثبت‌شده توسطِ کارشناسانِ دیگر (تغییرِ دسته‌جمعیِ مدیر حساب نمی‌شود)
    $q = $pdo->prepare("SELECT u.full_name FROM followups f JOIN users u ON u.id = f.created_by WHERE f.customer_id IN ($in) AND f.created_by <> ? AND u.role IN ('A','B','C','leader') LIMIT 1");
    $q->execute([$uid]);
    if (($n = $q->fetchColumn()) !== false) return 'کارشناسِ دیگری قبلاً با این مشتری ارتباط داشته (' . $n . ')';
    return null;
}

/**
 * احیای مشتری توسطِ C: مشتریِ خودِ C ← Box A.
 *   C = همین ارجاع‌دهنده (ثبت می‌شود اگر خالی باشد)، A و B خالی؛ هر A که از Box A دریافت کند A می‌شود،
 *   او به Box B می‌دهد، B دریافت‌کننده B می‌شود، و وقتی B به Box C ارجاع دهد، مورد «اختصاصیِ» همین C است و فقط به او برمی‌گردد.
 */
function ps_c_revive_to_box_a(PDO $pdo, int $customerId, array $user): array
{
    if (!perf_ready($pdo)) return ['ok' => false, 'message' => 'ماژولِ سهم عملکرد آماده نیست.'];
    if ($why = ps_c_revive_check($pdo, $customerId, $user)) return ['ok' => false, 'message' => $why . '.'];
    $uid = (int) $user['id'];
    $pdo->beginTransaction();
    try {
        $own = ps_owners($pdo, $customerId);
        if (!$own['C']) {
            $r = ps_owner_add($pdo, $customerId, 'C', $uid, 'c_revive', $uid);
            if (!$r['ok']) throw new RuntimeException($r['message']);
        }
        $r = ps_box_add($pdo, $customerId, 'A', $uid, 'c_revive', 'احیای مشتری توسطِ C: ' . ($user['full_name'] ?? ''));
        if (!$r['ok']) throw new RuntimeException($r['message']);
        ps_activity($pdo, $customerId, $uid, 'احیای مشتری توسطِ C (' . ($user['full_name'] ?? '') . '): ورود به Box A — C = ' . ($user['full_name'] ?? '')
            . '، A و B خالی؛ وقتی B دوباره به Box C ارجاع دهد، مشتری فقط به همین C برمی‌گردد');
        perf_audit($pdo, $uid, 'c_revive', 'ps_box_items', ps_person_key($pdo, $customerId), null, ['customer_id' => $customerId, 'c_user_id' => $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    return ['ok' => true, 'message' => 'مشتری به Box A منتقل شد؛ C ِ این مشتری شما هستید و در پایانِ مسیر فقط به خودتان برمی‌گردد.'];
}

/** نسخه‌ی گروهیِ احیا (از صفحه‌ی پیگیری مشتریان) — هر شخص (۳۶۰) فقط یک بار */
function ps_c_revive_many(PDO $pdo, array $customerIds, array $user): array
{
    $added = 0; $skipped = 0; $reasons = []; $seen = [];
    if (!perf_ready($pdo)) return ['added' => 0, 'skipped' => count($customerIds), 'reasons' => ['ماژول آماده نیست' => count($customerIds)]];
    @set_time_limit(600);
    foreach (array_values(array_unique(array_map('intval', $customerIds))) as $cid) {
        if ($cid <= 0) continue;
        $pk = ps_person_key($pdo, $cid);
        if (isset($seen[$pk])) { $skipped++; $k = 'پرونده‌ی دیگرِ همان شخص (۳۶۰) قبلاً در همین انتخاب بود'; $reasons[$k] = ($reasons[$k] ?? 0) + 1; continue; }
        $seen[$pk] = true;
        $r = ps_c_revive_to_box_a($pdo, $cid, $user);
        if ($r['ok']) { $added++; continue; }
        $skipped++;
        $key = preg_replace('/\s*\(.*\)\s*\.?$/u', '', $r['message']);
        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
    }
    return ['added' => $added, 'skipped' => $skipped, 'reasons' => $reasons];
}

/** ثبت در «روند زمانیِ» مشتری (تاریخچه‌ی ارجاع/دریافت) */
function ps_activity(PDO $pdo, int $customerId, int $userId, string $text): void
{
    try {
        $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)')
            ->execute([$customerId, $userId ?: null, 'box', mb_substr($text, 0, 1000)]);
    } catch (Throwable $e) {
    }
}

/** ورود به Box (ارجاع یا آپلود) */
function ps_box_add(PDO $pdo, int $customerId, string $box, int $byUser, string $source, string $note = ''): array
{
    if (!in_array($box, ['A', 'B', 'C'], true)) return ['ok' => false, 'message' => 'Box نامعتبر.'];
    $pk = ps_person_key($pdo, $customerId);
    $own = ps_owners($pdo, $customerId);
    $cRevive = $source === 'c_revive';
    // مشتری‌ای که یک بار توسطِ C به Box A منتقل شده، دیگر هرگز (از هیچ مسیری) دوباره وارد Box A نمی‌شود
    if ($box === 'A' && ($prev = ps_c_revive_history($pdo, $customerId))) {
        return ['ok' => false, 'message' => 'این مشتری قبلاً یک بار توسطِ C به Box A منتقل شده (' . ($prev['by_name'] ?: 'کارشناس C') . ' — '
            . to_jalali(substr((string) $prev['entered_at'], 0, 10)) . ') و دوباره قابلِ انتقال نیست.'];
    }
    // Box A فقط برای مشتریانی است که B/C ندارند؛ اگر A داشته باشد، با ورود به Box A جایگاهِ A خالی می‌شود
    // (هر کس از Box برش دارد A می‌شود). استثنا: «احیای مشتری توسطِ C» (ps_c_revive_to_box_a) که C همان ارجاع‌دهنده است.
    if ($box === 'A' && ($own['B'] || ($own['C'] && !$cRevive))) {
        $who = [];
        foreach ($own['B'] as $b) $who[] = 'B: ' . $b['full_name'];
        if ($own['C']) $who[] = 'C: ' . $own['C']['full_name'];
        return ['ok' => false, 'message' => 'Box A فقط برای مشتریِ بدونِ B/C است؛ این مشتری دارد (' . implode('، ', $who) . ').'];
    }
    if ($box === 'A' && $own['A'] && !ps_box_open_item($pdo, $pk, 'A')) {
        $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([(int) $own['A']['id']]);
        perf_audit($pdo, $byUser, 'owner_remove', 'ps_owners', $pk, $own['A'], null, 'ورود به Box A ← جایگاهِ A خالی شد');
        ps_activity($pdo, $customerId, $byUser, 'ورود به Box A: جایگاهِ A (' . $own['A']['full_name'] . ') خالی شد؛ هر کس از Box دریافت کند A می‌شود');
    }
    // مشتری‌ای که C دارد ← موردِ Box C «اختصاصی» همان C می‌شود (فقط او دریافتش می‌کند و اول از همه به او می‌رسد)
    $reservedFor = null;
    if ($box === 'C' && $own['C']) {
        $cu = $pdo->prepare('SELECT role, is_active, is_approved FROM users WHERE id = ?');
        $cu->execute([(int) $own['C']['user_id']]);
        $cu = $cu->fetch(PDO::FETCH_ASSOC);
        if (!$cu || $cu['role'] !== 'C' || (int) $cu['is_active'] !== 1 || (int) $cu['is_approved'] !== 1) {
            return ['ok' => false, 'message' => 'این مشتری C دارد (' . $own['C']['full_name'] . ') ولی آن کارشناس فعال نیست؛ مدیر باید جایگاهِ C را اصلاح کند.'];
        }
        $reservedFor = (int) $own['C']['user_id'];
    }
    if ($box === 'B' && $own['B']) return ['ok' => false, 'message' => 'این مشتری B دارد (' . $own['B'][0]['full_name'] . ').'];
    if (ps_box_open_item($pdo, $pk, $box)) return ['ok' => false, 'message' => 'این مشتری همین الان در Box ' . $box . ' است.'];
    $cur = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ?');
    $cur->execute([$customerId]);
    $prevOwner = (int) $cur->fetchColumn();
    // تیمِ ارجاع‌دهنده + تیمِ مبدأ (اولین ارجاعِ همین شخص در زنجیره؛ مثلاً A که به Box B ریخت)
    $by = $byUser ? ps_user_row($pdo, $byUser) : null;
    $enteredTeam = $by && !empty($by['team_id']) ? (int) $by['team_id'] : null;
    $originTeam = $enteredTeam;
    if ($box !== 'A') {
        $o = $pdo->prepare('SELECT origin_team_id FROM ps_box_items WHERE person_key = ? AND origin_team_id IS NOT NULL AND box <> ? ORDER BY id ASC LIMIT 1');
        $o->execute([$pk, $box]);
        $first = $o->fetchColumn();
        if ($first) $originTeam = (int) $first;
    }
    $pdo->prepare('INSERT INTO ps_box_items (box, customer_id, person_key, status, source, note, entered_by, entered_at, prev_owner_id, entered_team_id, origin_team_id, reserved_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$box, $customerId, $pk, 'open', $source, mb_substr($note, 0, 300) ?: null, $byUser ?: null, date('Y-m-d H:i:s'), $prevOwner ?: null, $enteredTeam, $originTeam, $reservedFor]);
    // پرونده از لیستِ کارشناسِ فعلی خارج می‌شود (مالکِ موقت = «Box مشتریان») تا کارشناسِ بعدی دریافتش کند
    $boxUser = ps_box_user_id($pdo);
    if ($boxUser) $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ?')->execute([$boxUser, $customerId]);
    $u = $byUser ? ps_user_row($pdo, $byUser) : null;
    perf_audit($pdo, $byUser, 'box_enter', 'ps_box_items', $pk, null, ['box' => $box, 'source' => $source, 'customer_id' => $customerId, 'role' => $u['role'] ?? null, 'team_id' => $u['team_id'] ?? null, 'reserved_user_id' => $reservedFor]);
    $resTxt = $reservedFor ? ' — اختصاصی برای C: ' . $own['C']['full_name'] : '';
    ps_activity($pdo, $customerId, $byUser, 'ورود به Box ' . $box . ' (' . $source . ')' . $resTxt . ($note !== '' ? ' — ' . $note : ''));
    return ['ok' => true, 'message' => 'مشتری وارد Box ' . $box . ' شد' . ($reservedFor ? ' و فقط به C خودش (' . $own['C']['full_name'] . ') برمی‌گردد' : '') . '.'];
}

/**
 * دریافتِ اولین موردِ آزادِ Box — Atomic: یک UPDATE با شرطِ status='open' و توکنِ یکتا؛ حتی با هزاران درخواستِ
 * هم‌زمان، هر ردیف فقط یک بار «claimed» می‌شود (قفلِ سطریِ InnoDB). سپس مالکیت ثبت می‌شود.
 */
function ps_box_claim(PDO $pdo, string $box, array $user): array
{
    if (!in_array($box, ['A', 'B', 'C'], true) || ($user['role'] ?? '') !== $box) return ['ok' => false, 'message' => 'فقط نقشِ ' . $box . ' می‌تواند از Box ' . $box . ' دریافت کند.'];
    $uid = (int) $user['id'];
    for ($try = 0; $try < 8; $try++) {
        $token = bin2hex(random_bytes(16));
        // اولویت: ۱) تیمِ مبدأ = تیمِ من  ۲) تیمِ ارجاع‌دهنده = تیمِ من  ۳) بقیه (قدیمی‌ترین اول) — مشتری بینِ تیم‌ها جابه‌جا نشود
        $myTeam = (int) ($user['team_id'] ?? 0);
        // مواردِ اختصاصی: فقط صاحبش دریافت می‌کند و قبل از همه به او می‌رسد
        $pdo->prepare("UPDATE ps_box_items SET status = 'claimed', claimed_by = ?, claimed_at = ?, claim_token = ?
                WHERE box = ? AND status = 'open' AND (reserved_user_id IS NULL OR reserved_user_id = ?)
                ORDER BY COALESCE(reserved_user_id = ?, 0) DESC, COALESCE(origin_team_id = ?, 0) DESC, COALESCE(entered_team_id = ?, 0) DESC, id ASC LIMIT 1")
            ->execute([$uid, date('Y-m-d H:i:s'), $token, $box, $uid, $uid, $myTeam, $myTeam]);
        $st = $pdo->prepare('SELECT * FROM ps_box_items WHERE claim_token = ?');
        $st->execute([$token]);
        $item = $st->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            // در رقابتِ هم‌زمان ممکن است ردیفِ قفل‌شده را نفرِ قبلی گرفته باشد؛ اگر هنوز موردِ آزاد هست دوباره تلاش کن
            if (ps_box_claimable_count($pdo, $box, $uid) > 0) { usleep(random_int(20000, 120000)); continue; }
            return ['ok' => false, 'message' => 'Box ' . $box . ' الان موردِ آزادی برای شما ندارد.'];
        }
        $slot = $box;
        $curSlot = ps_owners($pdo, (int) $item['customer_id']);
        $curOwner = $slot === 'B' ? ($curSlot['B'][0] ?? null) : $curSlot[$slot];
        // مشتریِ خودِ همین کارشناس (مثلاً C ِ ثبت‌شده که مشتری‌اش از Box C برگشته) ← جایگاه از قبل مالِ اوست
        $res = $curOwner && (int) $curOwner['user_id'] === $uid
            ? ['ok' => true, 'message' => $slot . ' از قبل همین کارشناس است.']
            : ps_owner_add($pdo, (int) $item['customer_id'], $slot, $uid, 'box', $uid);
        if (!$res['ok']) {
            // جایگاه در این فاصله پر شده (مثلاً دستی) ← این مورد کنار می‌رود و موردِ بعدی امتحان می‌شود
            $pdo->prepare("UPDATE ps_box_items SET status = 'cancelled', note = ? WHERE id = ?")->execute([mb_substr('جایگاه قبلاً پر شده: ' . $res['message'], 0, 300), (int) $item['id']]);
            continue;
        }
        $cid = (int) $item['customer_id'];
        ps_transfer_record($pdo, $cid, $uid, $uid, 'تحویلِ پرونده از Box ' . $box . ' به ' . ($user['full_name'] ?? '') . ' (همراه با همه‌ی پیگیری‌ها و روندِ زمانیِ قبلی)');
        // «تاریخچه ارجاع»: از کسی که مشتری را به Box داد ← دریافت‌کننده
        if (function_exists('referral_log')) {
            try { referral_log($pdo, $cid, (int) ($item['prev_owner_id'] ?: $item['entered_by']), $uid, (int) ($item['entered_by'] ?: $uid), 'box', 'Box ' . $box, 'bx' . (int) $item['id']); } catch (Throwable $e) {}
        }
        perf_audit($pdo, $uid, 'box_claim', 'ps_box_items', (int) $item['id'], null, ['box' => $box, 'customer_id' => $cid, 'person_key' => $item['person_key'], 'team_id' => $user['team_id'] ?? null]);
        ps_activity($pdo, $cid, $uid, 'دریافت از Box ' . $box . ' ← ' . $box . ' = ' . ($user['full_name'] ?? ''));
        if ((int) ($item['reserved_user_id'] ?? 0) === $uid) {
            return ['ok' => true, 'customer_id' => $cid, 'message' => 'مشتریِ خودتان به شما برگشت (موردِ اختصاصیِ Box ' . $box . ')؛ پرونده در لیستِ پیگیریِ شماست.'];
        }
        $sameTeam = $myTeam && ((int) ($item['origin_team_id'] ?? 0) === $myTeam || (int) ($item['entered_team_id'] ?? 0) === $myTeam);
        return ['ok' => true, 'customer_id' => $cid, 'message' => 'مشتری دریافت شد؛ شما ' . $box . 'ِ این مشتری هستید.'
            . ($myTeam ? ($sameTeam ? ' (از ارجاعِ هم‌تیمی‌های خودتان)' : ' (هم‌تیمی‌هایتان موردی در Box نداشتند؛ از تیم‌های دیگر)') : '')];
    }
    return ['ok' => false, 'message' => 'دریافت انجام نشد؛ دوباره تلاش کنید.'];
}

/**
 * پرونده‌ی خودِ دریافت‌کننده: اگر در پروفایلِ ۳۶۰ پرونده‌ای ندارد ساخته می‌شود (لیستِ مشتریان مالک‌محور است).
 * لیدِ واردشده به Box A که مالکش کارشناس نیست (آپلود/مدیر)، مستقیم به همان A منتقل می‌شود.
 */
function ps_ensure_record(PDO $pdo, int $customerId, int $userId, bool $takeOverImport): int
{
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = cc_person_ids($pdo, $customerId);
    $in = implode(',', array_map('intval', $ids));
    $own = $pdo->prepare("SELECT id FROM customers WHERE id IN ($in) AND owner_user_id = ? LIMIT 1");
    $own->execute([$userId]);
    if ($id = (int) $own->fetchColumn()) return $id;
    $st = $pdo->prepare('SELECT c.*, u.role AS owner_role FROM customers c LEFT JOIN users u ON u.id = c.owner_user_id WHERE c.id = ?');
    $st->execute([$customerId]);
    $src = $st->fetch(PDO::FETCH_ASSOC);
    if (!$src) return $customerId;
    if ($takeOverImport && !in_array((string) $src['owner_role'], ['A', 'B', 'C', 'leader'], true)) {
        $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ?')->execute([$userId, $customerId]);
        return $customerId;
    }
    $pdo->prepare("INSERT INTO customers (owner_user_id, full_name, mobile, initial_contact_date, next_followup_date, status, contact_type) VALUES (?,?,?,?,?,'جدید',?)")
        ->execute([$userId, $src['full_name'], $src['mobile'], date('Y-m-d'), date('Y-m-d'), $src['contact_type'] ?: 'customer']);
    $nid = (int) $pdo->lastInsertId();
    if (function_exists('sync_customer_phone_normalized')) { try { sync_customer_phone_normalized($pdo, $nid, (string) $src['mobile'], null); sync_customer_phone_entries($pdo, $nid, (string) $src['mobile'], null); } catch (Throwable $e) {} }
    if (function_exists('get_or_create_relation')) { try { get_or_create_relation($pdo, $nid, $userId, 'box_claim', true); } catch (Throwable $e) {} }
    return $nid;
}

/** ورودِ لید از اکسل/لیست به Box A: هر ردیف [نام، موبایل] — اگر شماره در سامانه هست همان مشتری، وگرنه پرونده‌ی جدید */
function ps_box_import(PDO $pdo, array $rows, int $byUser): array
{
    $ok = 0; $skip = []; $new = 0;
    foreach ($rows as $r) {
        $name = trim((string) ($r[0] ?? ''));
        $mob = function_exists('normalize_phone_for_match') ? normalize_phone_for_match((string) ($r[1] ?? '')) : preg_replace('/\D+/', '', (string) ($r[1] ?? ''));
        if (!$mob) { if ($name !== '' || !empty($r[1])) $skip[] = ($r[1] ?? '') . ': شماره نامعتبر'; continue; }
        $cid = 0;
        try { $st = $pdo->prepare('SELECT customer_id FROM customer_phones WHERE phone_normalized = ? LIMIT 1'); $st->execute([$mob]); $cid = (int) $st->fetchColumn(); } catch (Throwable $e) {}
        if (!$cid) { $s2 = $pdo->prepare('SELECT id FROM customers WHERE mobile = ? LIMIT 1'); $s2->execute([$mob]); $cid = (int) $s2->fetchColumn(); }
        if (!$cid) {
            $pdo->prepare("INSERT INTO customers (owner_user_id, full_name, mobile, initial_contact_date, next_followup_date, status, contact_type) VALUES (?,?,?,?,?,'جدید','customer')")
                ->execute([ps_box_user_id($pdo) ?: $byUser, $name !== '' ? mb_substr($name, 0, 150) : 'لید ' . $mob, $mob, date('Y-m-d'), date('Y-m-d')]);
            $cid = (int) $pdo->lastInsertId();
            if (function_exists('sync_customer_phone_normalized')) { try { sync_customer_phone_normalized($pdo, $cid, $mob, null); sync_customer_phone_entries($pdo, $cid, $mob, null); } catch (Throwable $e) {} }
            $new++;
        }
        $res = ps_box_add($pdo, $cid, 'A', $byUser, 'import');
        if ($res['ok']) $ok++; else $skip[] = $mob . ': ' . $res['message'];
    }
    return ['ok' => $ok, 'new' => $new, 'skipped' => $skip];
}

/* =========================================================================
   snapshot سفارش + محاسبه‌ی هر پرداخت (immutable)
   ========================================================================= */

function ps_order_snapshot(PDO $pdo, int $orderId, ?array $registrant, string $source = 'order'): ?array
{
    $st = $pdo->prepare('SELECT * FROM ps_order_snapshots WHERE order_id = ?');
    $st->execute([$orderId]);
    if ($s = $st->fetch(PDO::FETCH_ASSOC)) return $s;
    $o = $pdo->prepare('SELECT o.*, COALESCE(NULLIF(o.tax_percent, 0), q.tax_percent) AS q_tax FROM sales_orders o LEFT JOIN quotes q ON q.id = o.quote_id WHERE o.id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch(PDO::FETCH_ASSOC);
    if (!$order) return null;
    $own = ps_owners($pdo, (int) $order['customer_id']);
    $slim = static fn($x) => $x ? ['user_id' => (int) $x['user_id'], 'name' => $x['full_name'], 'team_id' => $x['team_id'] ? (int) $x['team_id'] : null,
        'leader_id' => $x['leader_id'] ? (int) $x['leader_id'] : null, 'leader_name' => $x['leader_name'], 'position' => (int) $x['position']] : null;
    // قانونِ A: فقط اگر همین A مشتری را واردِ سامانه کرده یا از Box A گرفته باشد (بدونِ استثنا)؛ وگرنه سهمِ A ← سازمان
    $aOwn = $own['A'];
    $aBasis = $aOwn ? ps_a_basis($pdo, (int) $order['customer_id'], (int) $aOwn['user_id']) : null;
    if ($aBasis && !$aBasis['ok']) $aOwn = null;
    $snap = ['A' => $slim($aOwn), 'B' => array_map($slim, $own['B']), 'C' => $slim($own['C']), 'direct_d' => null];
    if ($snap['A']) $snap['A']['basis'] = $aBasis['text'];
    elseif ($aBasis) $snap['A_note'] = $own['A']['full_name'] . ' — ' . $aBasis['text'];
    foreach ($snap['B'] as $i => $bb) $snap['B'][$i]['basis'] = ps_owner_basis($pdo, $own['B'][$i]);
    if ($snap['C']) $snap['C']['basis'] = ps_owner_basis($pdo, $own['C']);
    $regId = $registrant ? (int) $registrant['id'] : (int) $order['seller_user_id'];
    $reg = ps_user_row($pdo, $regId);
    if ($reg && $reg['role'] === 'leader') $snap['direct_d'] = ['user_id' => $regId, 'name' => $reg['full_name'], 'team_id' => $reg['team_id'] ? (int) $reg['team_id'] : null];
    $ver = ps_base_version_at($pdo, (string) $order['created_at']);
    $tax = (float) ($order['q_tax'] ?? 0);
    if ($tax <= 0 && (int) $order['tax_amount'] > 0 && (int) $order['total_amount'] > (int) $order['tax_amount']) {
        $tax = round((int) $order['tax_amount'] / ((int) $order['total_amount'] - (int) $order['tax_amount']) * 100, 3);
    }
    $pdo->prepare('INSERT IGNORE INTO ps_order_snapshots (order_id, person_key, base_version_id, base_percent, tax_percent, owners_json, registrant_user_id, registrant_role, source, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$orderId, $own['person_key'], (int) $ver['id'], (float) $ver['percent'], $tax, json_encode($snap, JSON_UNESCAPED_UNICODE), $regId ?: null, $reg['role'] ?? null, $source, date('Y-m-d H:i:s')]);
    perf_audit($pdo, $regId, 'order_snapshot', 'sales_orders', $orderId, null, ['base_percent' => (float) $ver['percent'], 'tax' => $tax, 'owners' => $snap, 'source' => $source]);
    $st->execute([$orderId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** همگام‌سازیِ محاسبه‌های یک سفارش با پرداخت‌های تأییدشده (جدید ← محاسبه؛ لغو/رد ← ابطال). محاسبه‌ی ثبت‌شده هرگز ویرایش نمی‌شود. */
function ps_sync_order(PDO $pdo, int $orderId, int $byUser = 0): void
{
    if (!perf_ready($pdo)) return;
    try {
        $o = $pdo->prepare('SELECT status FROM sales_orders WHERE id = ?');
        $o->execute([$orderId]);
        $ostatus = (string) $o->fetchColumn();
        $pays = $pdo->prepare("SELECT p.*, COALESCE(p.paid_at, DATE(p.decided_at)) AS pay_date FROM sales_order_payments p WHERE p.order_id = ? AND p.kind NOT IN ('credit','transfer')");
        $pays->execute([$orderId]);
        $valid = [];
        foreach ($pays->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            if ($ostatus === 'approved' && $p['status'] === 'confirmed') $valid[(int) $p['id']] = $p;
        }
        $calcs = $pdo->prepare('SELECT * FROM ps_payment_calcs WHERE order_id = ? AND voided_at IS NULL');
        $calcs->execute([$orderId]);
        $active = [];
        foreach ($calcs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
            $p = $valid[(int) $c['payment_id']] ?? null;
            if (!$p || (int) $p['amount'] !== (int) $c['paid_amount'] || (string) $p['pay_date'] !== (string) $c['pay_date']) {
                $why = !$p ? 'پرداخت دیگر تأییدشده نیست (رد/در انتظار/لغوِ سفارش)' : 'مبلغ یا تاریخِ پرداخت بعد از محاسبه تغییر کرد';
                $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $why, (int) $c['id']]);
                $pdo->prepare('UPDATE ps_lines SET voided = 1 WHERE calc_id = ?')->execute([(int) $c['id']]);
                perf_audit($pdo, $byUser, 'calc_void', 'ps_payment_calcs', (int) $c['id'], ['payment_id' => (int) $c['payment_id'], 'pool' => (int) $c['pool']], null, $why);
            } else {
                $active[(int) $c['payment_id']] = true;
            }
        }
        if (!$valid) return;
        // اولین پولِ تأییدشده‌ی سفارشی که یک کارشناسِ C ثبت کرده و مشتری C ندارد ← همان کارشناس C مشتری می‌شود
        if (ps_assign_c_on_first_money($pdo, $orderId, $byUser)) {
            $active = []; // محاسبه‌های قبلی باطل شدند ← همه‌ی پرداخت‌ها دوباره حساب می‌شوند
        }
        $snap = null;
        foreach ($valid as $pid => $p) {
            if (isset($active[$pid])) continue;
            $snap = $snap ?? ps_order_snapshot($pdo, $orderId, null, 'migrated');
            if (!$snap) return;
            ps_calc_payment($pdo, $snap, $p, $byUser);
        }
    } catch (Throwable $e) {
        error_log('ps_sync_order: ' . $e->getMessage());
    }
}

/**
 * قاعده: هر کارشناسِ نقشِ C که از مشتری پول بگیرد (پرداختِ تأییدشده‌ی سفارشی که خودش ثبت کرده)، اگر مشتری C نداشته باشد،
 * C همان مشتری می‌شود و مشتری از همه‌ی Boxها خارج می‌شود (تا کسِ دیگری با او ارتباط نگیرد).
 * فقط روی اولین پولِ این سفارش (قبل از هر محاسبه) اجرا می‌شود؛ snapshotِ همین سفارش هم C را می‌گیرد تا سهمِ C همین پرداخت به او برسد.
 */
function ps_assign_c_on_first_money(PDO $pdo, int $orderId, int $byUser): bool
{
    // «کسی که خودش فیش را ثبت کرده، سهمِ جایگاهِ خودش را می‌گیرد» (A / B / C) — حتی برای سفارش‌های قبلاً محاسبه‌شده (مهاجرت)
    $o = $pdo->prepare('SELECT id, customer_id, seller_user_id FROM sales_orders WHERE id = ?');
    $o->execute([$orderId]);
    $order = $o->fetch(PDO::FETCH_ASSOC);
    if (!$order) return false;
    $snap = ps_order_snapshot($pdo, $orderId, null, 'migrated');
    if (!$snap) return false;
    $ow = json_decode((string) $snap['owners_json'], true) ?: [];
    if (!empty($ow['manual_edit'])) return false; // مالی/مدیر دستی تعیین کرده؛ قانونِ خودکار دخالت نمی‌کند
    $regId = (int) ($snap['registrant_user_id'] ?? $order['seller_user_id']);
    $reg = ps_user_row($pdo, $regId);
    if (!$reg || !in_array($reg['role'], ['A', 'B', 'C'], true)) return false;
    $slot = $reg['role'];
    $cid = (int) $order['customer_id'];
    $own = ps_owners($pdo, $cid);
    $changed = false;

    if ($slot === 'A' || $slot === 'C') {
        if (!empty($ow[$slot])) return false; // جایگاهِ این سفارش پر است (A/C یکتاست)
        // قانونِ A: ثبت‌کننده فقط وقتی A می‌شود که مشتری را از Box A گرفته باشد یا خودش برای اولین بار واردِ سامانه کرده باشد؛
        // مشتریانِ قدیمی (که در Box A ریخته نشده‌اند) A ندارند ← سهمِ A به سازمان
        $aB = $slot === 'A' ? ps_a_basis($pdo, $cid, $regId) : null;
        if ($aB && !$aB['ok']) return false;
        if (!$own[$slot]) {
            $r = ps_owner_add($pdo, $cid, $slot, $regId, 'payment', $byUser ?: $regId);
            if ($r['ok']) ps_activity($pdo, $cid, $byUser ?: $regId, $slot . ' مشتری = ' . $reg['full_name'] . ' (ثبتِ فیش در سفارشِ #' . $orderId . ')');
        }
        $ow[$slot] = ps_snapshot_person($pdo, $regId);
        if ($ow[$slot]) $ow[$slot]['basis'] = $aB ? $aB['text'] : 'ثبتِ فیشِ همین سفارش (اولین پولِ مشتری را خودش گرفته)';
        $changed = true;
    } else { // B — مثلِ A و C: فقط یک نفر
        if (!empty($ow['B'])) return false; // جایگاهِ B این سفارش پر است
        if (!$own['B']) {
            $r = ps_owner_add($pdo, $cid, 'B', $regId, 'payment', $byUser ?: $regId);
            if ($r['ok']) ps_activity($pdo, $cid, $byUser ?: $regId, 'B مشتری = ' . $reg['full_name'] . ' (ثبتِ فیش در سفارشِ #' . $orderId . ')');
        }
        $ow['B'] = [ps_snapshot_person($pdo, $regId, 1) + ['basis' => 'ثبتِ فیشِ همین سفارش (اولین پولِ مشتری را خودش گرفته)']];
        $changed = true;
    }
    if (!$changed) return false;

    // قانونِ C: مشتری از همه‌ی Boxها خارج و پرونده‌ی داخلِ Box به همین C تحویل می‌شود
    if ($slot === 'C') {
        $pk = ps_person_key($pdo, $cid);
        $open = $pdo->prepare("SELECT id, customer_id, prev_owner_id, entered_by FROM ps_box_items WHERE person_key = ? AND status = 'open'");
        $open->execute([$pk]);
        foreach ($open->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) {
            $pdo->prepare("UPDATE ps_box_items SET status = 'claimed', claimed_by = ?, claimed_at = ?, note = ? WHERE id = ? AND status = 'open'")
                ->execute([$regId, date('Y-m-d H:i:s'), 'خروج از Box: C مشتری (' . $reg['full_name'] . ') از او پول دریافت کرد', (int) $it['id']]);
            ps_transfer_record($pdo, (int) $it['customer_id'], $regId, $byUser ?: $regId, 'خروج از Box و تحویلِ پرونده به C مشتری (' . $reg['full_name'] . ') پس از دریافتِ پول');
            if (function_exists('referral_log')) {
                try { referral_log($pdo, (int) $it['customer_id'], (int) ($it['prev_owner_id'] ?: $it['entered_by']), $regId, $byUser ?: $regId, 'box', 'خروج از Box: C مشتری پول دریافت کرد', 'bx' . (int) $it['id']); } catch (Throwable $e) {}
            }
        }
    }
    $pdo->prepare('UPDATE ps_order_snapshots SET owners_json = ? WHERE order_id = ?')->execute([json_encode($ow, JSON_UNESCAPED_UNICODE), $orderId]);
    perf_audit($pdo, $byUser, 'registrant_share', 'sales_orders', $orderId, null, ['slot' => $slot, 'user' => $reg['full_name']]);
    // محاسبه‌های قبلیِ این سفارش باطل می‌شوند تا با ثبت‌کننده دوباره حساب شوند (در سابقه می‌مانند)
    $cs = $pdo->prepare('SELECT id FROM ps_payment_calcs WHERE order_id = ? AND voided_at IS NULL');
    $cs->execute([$orderId]);
    foreach ($cs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $calcId) {
        $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), 'ثبت‌کننده‌ی فیش (' . $reg['full_name'] . ' — ' . $slot . ') به سهم اضافه شد — محاسبه‌ی مجدد', (int) $calcId]);
        $pdo->prepare('UPDATE ps_lines SET voided = 1 WHERE calc_id = ?')->execute([(int) $calcId]);
    }
    return true;
}

function ps_calc_payment(PDO $pdo, array $snap, array $p, int $byUser): void
{
    $own = json_decode((string) $snap['owners_json'], true) ?: [];
    $paid = (int) $p['amount'];
    $tax = (float) $snap['tax_percent'];
    $base = (float) $snap['base_percent'];
    $nt = ps_net($paid, $tax);
    $pool = ps_pool($paid, $tax, $base);
    $r = ps_distribute($pool, $own);
    if ($r['distributed'] + $r['org_total'] !== $pool) {
        error_log('ps_calc_payment: sum mismatch for payment ' . $p['id']);
        return;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO ps_payment_calcs (payment_id, order_id, pay_date, paid_amount, tax_percent, net_amount, tax_amount, base_version_id, base_percent, pool, distributed, org_total, snapshot_json, calculated_by, calculated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([(int) $p['id'], (int) $p['order_id'], (string) $p['pay_date'], $paid, $tax, $nt['net'], $nt['tax'], (int) $snap['base_version_id'], $base, $pool, $r['distributed'], $r['org_total'], $snap['owners_json'], $byUser ?: null, date('Y-m-d H:i:s')]);
        $cid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO ps_lines (calc_id, payment_id, order_id, pay_date, unit, slot, user_id, team_id, amount, reason) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($r['lines'] as $l) {
            $ins->execute([$cid, (int) $p['id'], (int) $p['order_id'], (string) $p['pay_date'], $l['unit'], $l['slot'], $l['user_id'], $l['team_id'], $l['amount'], mb_substr($l['reason'], 0, 500)]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('ps_calc_payment: ' . $e->getMessage());
        return;
    }
    perf_audit($pdo, $byUser, 'calc', 'ps_payment_calcs', $cid, null, ['payment_id' => (int) $p['id'], 'paid' => $paid, 'net' => $nt['net'], 'base' => $base, 'pool' => $pool, 'org' => $r['org_total']]);
}

/** شبکه‌ی ایمنی: همه‌ی سفارش‌هایی که پرداختِ تأییدشده‌ی بدونِ محاسبه دارند (یا محاسبه‌ی بی‌اعتبار) */
function ps_sync_all(PDO $pdo, int $byUser = 0, int $limit = 300): int
{
    if (!perf_ready($pdo)) return 0;
    $ids = $pdo->query("SELECT DISTINCT p.order_id FROM sales_order_payments p JOIN sales_orders o ON o.id = p.order_id
        WHERE o.status = 'approved' AND p.status = 'confirmed' AND p.kind NOT IN ('credit','transfer')
        AND NOT EXISTS (SELECT 1 FROM ps_payment_calcs c WHERE c.payment_id = p.id AND c.voided_at IS NULL)
        UNION SELECT DISTINCT c.order_id FROM ps_payment_calcs c JOIN sales_order_payments p ON p.id = c.payment_id JOIN sales_orders o ON o.id = c.order_id
        WHERE c.voided_at IS NULL AND (p.status <> 'confirmed' OR o.status <> 'approved' OR p.amount <> c.paid_amount)
        LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($ids as $oid) ps_sync_order($pdo, (int) $oid, $byUser);
    return count($ids);
}

/* =========================================================================
   گزارش، طلب، پرداختِ سهم و حقوق
   ========================================================================= */

function perf_earned(PDO $pdo, ?int $userId, string $from, string $to): array
{
    $sql = 'SELECT l.user_id, u.full_name, u.role, SUM(l.amount) AS earned, COUNT(DISTINCT l.payment_id) AS payments
        FROM ps_lines l JOIN users u ON u.id = l.user_id WHERE l.voided = 0 AND l.user_id IS NOT NULL AND l.pay_date BETWEEN ? AND ?'
        . ($userId ? ' AND l.user_id = ?' : '') . ' GROUP BY l.user_id, u.full_name, u.role ORDER BY earned DESC';
    $st = $pdo->prepare($sql);
    $st->execute($userId ? [$from, $to, $userId] : [$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function perf_paid(PDO $pdo, ?int $userId, string $from, string $to, ?string $kind = null): array
{
    $sql = 'SELECT user_id, kind, SUM(amount) AS total FROM perf_payouts WHERE voided_at IS NULL AND paid_at BETWEEN ? AND ?'
        . ($userId ? ' AND user_id = ?' : '') . ($kind ? ' AND kind = ?' : '') . ' GROUP BY user_id, kind';
    $params = [$from, $to];
    if ($userId) $params[] = $userId;
    if ($kind) $params[] = $kind;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int) $r['user_id']][$r['kind']] = (int) $r['total'];
    return $out;
}

function perf_balances(PDO $pdo, ?int $userId = null): array
{
    $earned = perf_earned($pdo, $userId, '2000-01-01', '2100-01-01');
    $paid = perf_paid($pdo, $userId, '2000-01-01', '2100-01-01', 'commission');
    $out = [];
    foreach ($earned as $e) {
        $uid = (int) $e['user_id'];
        $out[$uid] = ['user_id' => $uid, 'full_name' => $e['full_name'], 'role' => $e['role'], 'earned' => (int) $e['earned'], 'paid' => (int) ($paid[$uid]['commission'] ?? 0)];
    }
    foreach ($paid as $uid => $k) {
        if (!isset($out[$uid])) {
            $u = ps_user_row($pdo, (int) $uid);
            $out[$uid] = ['user_id' => $uid, 'full_name' => $u['full_name'] ?? '#' . $uid, 'role' => $u['role'] ?? '', 'earned' => 0, 'paid' => (int) ($k['commission'] ?? 0)];
        }
    }
    foreach ($out as &$o) $o['balance'] = $o['earned'] - $o['paid'];
    unset($o);
    uasort($out, static fn($a, $b) => $b['balance'] <=> $a['balance']);
    return $out;
}

function perf_payout_add(PDO $pdo, array $data, int $userId): array
{
    $uid = (int) ($data['user_id'] ?? 0);
    $kind = in_array($data['kind'] ?? '', ['commission', 'salary'], true) ? $data['kind'] : '';
    $amount = (int) ($data['amount'] ?? 0);
    $date = (string) ($data['paid_at'] ?? '');
    if ($uid <= 0 || $kind === '' || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok' => false, 'message' => 'کارشناس، نوع، مبلغ و تاریخ را درست وارد کنید.'];
    $pdo->prepare('INSERT INTO perf_payouts (user_id, kind, amount, paid_at, period_month, method, ref, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $kind, $amount, $date, $data['period_month'] ?: null, $data['method'] ?: null, $data['ref'] ?: null, $data['note'] ?: null, $userId, date('Y-m-d H:i:s')]);
    perf_audit($pdo, $userId, 'payout_add', 'perf_payouts', (int) $pdo->lastInsertId(), null, $data);
    return ['ok' => true, 'message' => ($kind === 'salary' ? 'حقوق' : 'پرداختِ سهم عملکرد') . ' ثبت شد.'];
}

function perf_payout_void(PDO $pdo, int $id, int $userId, string $reason): array
{
    $st = $pdo->prepare('SELECT * FROM perf_payouts WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p || $p['voided_at']) return ['ok' => false, 'message' => 'پرداخت پیدا نشد یا قبلاً باطل شده.'];
    if (mb_strlen(trim($reason)) < 3) return ['ok' => false, 'message' => 'دلیلِ ابطال را بنویسید.'];
    $pdo->prepare('UPDATE perf_payouts SET voided_by = ?, voided_at = ?, void_reason = ? WHERE id = ?')->execute([$userId, date('Y-m-d H:i:s'), mb_substr($reason, 0, 300), $id]);
    perf_audit($pdo, $userId, 'payout_void', 'perf_payouts', $id, $p, null, $reason);
    return ['ok' => true, 'message' => 'پرداخت باطل شد.'];
}

function perf_visible_user_ids(PDO $pdo, array $user): ?array
{
    if (perf_can('view_all', $user)) return null;
    $ids = [(int) $user['id']];
    if (($user['role'] ?? '') === 'leader' && function_exists('team_led_by') && ($team = team_led_by($pdo, (int) $user['id']))) {
        $st = $pdo->prepare('SELECT id FROM users WHERE team_id = ?');
        $st->execute([(int) $team['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $i) $ids[] = (int) $i;
    }
    return array_values(array_unique($ids));
}

function perf_range(string $preset, string $fromJ = '', string $toJ = ''): array
{
    $today = date('Y-m-d');
    $jToday = explode('/', (string) normalize_digits(to_jalali($today)));
    switch ($preset) {
        case 'yesterday': $d = date('Y-m-d', strtotime('-1 day')); return [$d, $d, 'دیروز'];
        case 'week': $back = ((int) date('w') + 1) % 7; return [date('Y-m-d', strtotime("-$back day")), $today, 'این هفته'];
        case 'month':
        case 'last_month':
            if (count($jToday) === 3) {
                [$jy, $jm] = [(int) $jToday[0], (int) $jToday[1]];
                if ($preset === 'last_month') { $jm--; if ($jm < 1) { $jm = 12; $jy--; } }
                $start = to_gregorian(sprintf('%04d/%02d/01', $jy, $jm));
                $nm = $jm + 1; $ny = $jy; if ($nm > 12) { $nm = 1; $ny++; }
                $end = date('Y-m-d', strtotime(to_gregorian(sprintf('%04d/%02d/01', $ny, $nm)) . ' -1 day'));
                return [$start, $preset === 'month' ? min($end, $today) : $end, $preset === 'month' ? 'این ماه' : 'ماهِ قبل'];
            }
            return [date('Y-m-01'), $today, 'این ماه'];
        case 'all': return ['2000-01-01', $today, 'کلِ زمان'];
        case 'custom':
            $f = $fromJ !== '' ? to_gregorian(normalize_digits($fromJ)) : null;
            $t = $toJ !== '' ? to_gregorian(normalize_digits($toJ)) : null;
            if ($f && $t) return [min($f, $t), max($f, $t), 'بازه‌ی دلخواه'];
            return [$today, $today, 'امروز'];
        default: return [$today, $today, 'امروز'];
    }
}

/**
 * ارسالِ گروهیِ مشتریان به Box A (از هر جایی که انتخاب و ارجاع دارد).
 * فقط مشتریِ بدونِ A/B/C وارد می‌شود؛ بقیه با دلیل رد می‌شوند. هر شخص (۳۶۰) فقط یک بار.
 * @return array{added:int, skipped:int, reasons:array<string,int>}
 */
function ps_box_add_many(PDO $pdo, array $customerIds, int $byUser, string $source = 'bulk'): array
{
    $added = 0; $skipped = 0; $reasons = []; $seen = [];
    if (!perf_ready($pdo)) return ['added' => 0, 'skipped' => count($customerIds), 'reasons' => ['ماژول آماده نیست' => count($customerIds)]];
    @set_time_limit(600);
    foreach (array_values(array_unique(array_map('intval', $customerIds))) as $cid) {
        if ($cid <= 0) continue;
        $pk = ps_person_key($pdo, $cid);
        if (isset($seen[$pk])) { $skipped++; $reasons['پرونده‌ی دیگرِ همان شخص (۳۶۰) قبلاً در همین انتخاب بود'] = ($reasons['پرونده‌ی دیگرِ همان شخص (۳۶۰) قبلاً در همین انتخاب بود'] ?? 0) + 1; continue; }
        $seen[$pk] = true;
        $r = ps_box_add($pdo, $cid, 'A', $byUser, $source);
        if ($r['ok']) { $added++; continue; }
        $skipped++;
        $key = preg_replace('/\s*\(.*\)\s*\.?$/u', '', $r['message']); // بدونِ نام‌ها، برای خلاصه
        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
    }
    perf_audit($pdo, $byUser, 'box_bulk', 'ps_box_items', null, null, ['source' => $source, 'requested' => count($customerIds), 'added' => $added, 'skipped' => $skipped]);
    return ['added' => $added, 'skipped' => $skipped, 'reasons' => $reasons];
}

function ps_box_bulk_message(array $r): string
{
    $msg = to_persian_digits((string) $r['added']) . ' مشتری وارد Box A شد.';
    if ($r['skipped']) {
        $parts = [];
        foreach ($r['reasons'] as $why => $n) $parts[] = to_persian_digits((string) $n) . ' مورد: ' . $why;
        $msg .= ' ' . to_persian_digits((string) $r['skipped']) . ' مورد وارد نشد — ' . implode('؛ ', $parts);
    }
    return $msg;
}

/** یک نفر برای snapshotِ سفارش (با تیم و سرپرستِ فعلی‌اش) */
function ps_snapshot_person(PDO $pdo, int $uid, int $position = 1): ?array
{
    $u = ps_user_row($pdo, $uid);
    if (!$u) return null;
    return ['user_id' => $uid, 'name' => $u['full_name'], 'team_id' => $u['team_id'] ? (int) $u['team_id'] : null,
        'leader_id' => $u['leader_user_id'] ? (int) $u['leader_user_id'] : null, 'leader_name' => $u['leader_name'], 'position' => $position];
}

/**
 * ویرایشِ دستیِ سهمِ یک سفارش (مالی/مدیر): افرادِ A/B/C/سرپرستِ مستقیمِ همین سفارش عوض می‌شود،
 * و همان افراد در «مالکیتِ مشتری» (پروفایلِ ۳۶۰) هم جایگزین می‌شوند (تا پروفایل همان کسی را نشان دهد که مالی تعیین کرده)؛
 * محاسبه‌های قبلی «باطل» (حذف نه) و همه‌ی پرداخت‌های تأییدشده با افرادِ جدید دوباره محاسبه می‌شوند. دلیل الزامی و در تاریخچه ثبت می‌شود.
 * درصدِ سهمِ پایه و مالیاتِ snapshot تغییر نمی‌کند.
 */
function ps_edit_order_owners(PDO $pdo, int $orderId, array $in, int $byUser, string $reason): array
{
    if (mb_strlen(trim($reason)) < 5) return ['ok' => false, 'message' => 'دلیلِ ویرایش را کامل بنویسید.'];
    $snap = ps_order_snapshot($pdo, $orderId, null, 'migrated');
    if (!$snap) return ['ok' => false, 'message' => 'سفارش پیدا نشد.'];
    $need = static function (PDO $pdo, int $uid, string $role) {
        $u = ps_user_row($pdo, $uid);
        return $u && $u['role'] === $role ? null : ('نقشِ کاربرِ انتخاب‌شده برای ' . $role . ' باید ' . $role . ' باشد.');
    };
    $new = ['A' => null, 'B' => [], 'C' => null, 'direct_d' => null, 'manual_edit' => true];
    $byName = ps_user_row($pdo, $byUser)['full_name'] ?? '';
    $manualBasis = 'ویرایشِ دستیِ سهمِ سفارش' . ($byName !== '' ? ' توسطِ ' . $byName : '') . ' (' . to_jalali(date('Y-m-d')) . ')';
    if (!empty($in['A'])) {
        if ($e = $need($pdo, (int) $in['A'], 'A')) return ['ok' => false, 'message' => $e];
        $oc0 = $pdo->prepare('SELECT customer_id FROM sales_orders WHERE id = ?');
        $oc0->execute([$orderId]);
        $ab = ps_a_basis($pdo, (int) $oc0->fetchColumn(), (int) $in['A']);
        if (!$ab['ok']) return ['ok' => false, 'message' => 'این کارشناس نمی‌تواند A ِ این مشتری باشد: ' . $ab['text'] . '. A فقط کسی است که مشتری را خودش واردِ سامانه کرده یا از Box A برداشته.'];
        $new['A'] = ps_snapshot_person($pdo, (int) $in['A']) + ['basis' => $ab['text'] . ' — ' . $manualBasis];
    }
    if (!empty($in['C'])) { if ($e = $need($pdo, (int) $in['C'], 'C')) return ['ok' => false, 'message' => $e]; $new['C'] = ps_snapshot_person($pdo, (int) $in['C']) + ['basis' => $manualBasis]; }
    $bIn = (int) ($in['B'] ?? ($in['B1'] ?? 0));
    if ($bIn > 0) { if ($e = $need($pdo, $bIn, 'B')) return ['ok' => false, 'message' => $e]; $new['B'][] = ps_snapshot_person($pdo, $bIn, 1) + ['basis' => $manualBasis]; }
    if (!empty($in['D'])) {
        $d = ps_user_row($pdo, (int) $in['D']);
        if (!$d || $d['role'] !== 'leader') return ['ok' => false, 'message' => 'سرپرستِ ثبت‌کننده باید نقشِ سرپرست داشته باشد.'];
        $new['direct_d'] = ['user_id' => (int) $in['D'], 'name' => $d['full_name'], 'team_id' => $d['team_id'] ? (int) $d['team_id'] : null];
    }
    $old = json_decode((string) $snap['owners_json'], true) ?: [];
    $pdo->prepare('UPDATE ps_order_snapshots SET owners_json = ? WHERE order_id = ?')->execute([json_encode($new, JSON_UNESCAPED_UNICODE), $orderId]);
    $calcs = $pdo->prepare('SELECT id FROM ps_payment_calcs WHERE order_id = ? AND voided_at IS NULL');
    $calcs->execute([$orderId]);
    $n = 0;
    foreach ($calcs->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
        $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), mb_substr('ویرایشِ دستیِ سهم: ' . $reason, 0, 300), (int) $cid]);
        $pdo->prepare('UPDATE ps_lines SET voided = 1 WHERE calc_id = ?')->execute([(int) $cid]);
        $n++;
    }
    perf_audit($pdo, $byUser, 'manual_share_edit', 'sales_orders', $orderId, $old, $new, $reason);
    // همگام‌سازیِ «مالکیتِ مشتری» (پروفایلِ ۳۶۰) با افرادی که مالی/مدیر برای این سفارش تعیین کرد
    try {
        $oc = $pdo->prepare('SELECT customer_id FROM sales_orders WHERE id = ?');
        $oc->execute([$orderId]);
        if ($ocid = (int) $oc->fetchColumn()) ps_sync_owners_from_snapshot($pdo, $ocid, $new, $byUser, 'ویرایشِ دستیِ سهمِ سفارشِ #' . $orderId . ': ' . $reason);
    } catch (Throwable $e) {
        error_log('ps_edit_order_owners owner sync: ' . $e->getMessage());
    }
    ps_sync_order($pdo, $orderId, $byUser);
    return ['ok' => true, 'message' => 'سهمِ این سفارش ویرایش شد: ' . to_persian_digits((string) $n) . ' محاسبه‌ی قبلی باطل (در سابقه می‌ماند) و پرداخت‌های تأییدشده با افرادِ جدید دوباره محاسبه شدند.'];
}

/* =========================================================================
   ثبتِ گروهیِ پرداختِ سهم عملکرد / حقوق از روی فایلِ اکسل
   ستون‌ها: نام کارشناس | موبایل | نوع (سهم عملکرد / حقوق) | مبلغ | تاریخ پرداخت | بابت ماه حقوق | روش | شماره پیگیری | توضیحات
   ========================================================================= */

function perf_payout_import_columns(): array
{
    // کلید => [عنوانِ ستون در فایلِ نمونه، کلمه‌های تشخیصِ سرستون]
    return [
        'name'   => ['نام کارشناس', ['نام', 'کارشناس', 'name']],
        'mobile' => ['موبایل', ['موبایل', 'همراه', 'تلفن', 'شماره تماس', 'mobile', 'phone']],
        'kind'   => ['نوع (سهم عملکرد / حقوق)', ['نوع', 'kind', 'type']],
        'amount' => ['مبلغ (تومان)', ['مبلغ', 'amount']],
        'date'   => ['تاریخ پرداخت', ['تاریخ', 'date']],
        'period' => ['بابت ماه حقوق', ['بابت', 'ماه', 'period']],
        'method' => ['روش پرداخت', ['روش', 'method']],
        'ref'    => ['شماره پیگیری', ['پیگیری', 'رهگیری', 'ref']],
        'note'   => ['توضیحات', ['توضیح', 'note', 'شرح']],
    ];
}

/** یکسان‌سازیِ متن برای مقایسه (ی/ک عربی، نیم‌فاصله، فاصله‌های اضافه) */
function perf_imp_norm(string $s): string
{
    $s = str_replace(['ي', 'ى', 'ك', "\u{200C}", "\u{200F}", "\u{200E}", 'ۀ', 'ة'], ['ی', 'ی', 'ک', ' ', '', '', 'ه', 'ه'], $s);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
}

/** نگاشتِ ستون‌ها از روی سرستون؛ اگر سرستون شناخته نشد، ترتیبِ فایلِ نمونه */
function perf_payout_import_map(array $header): array
{
    $cols = perf_payout_import_columns();
    $map = [];
    $taken = [];
    // ترتیبِ تشخیص مهم است: «بابت ماه» قبل از «تاریخ»، «نوع» قبل از «نام»
    foreach (['ref', 'period', 'date', 'kind', 'amount', 'method', 'note', 'mobile', 'name'] as $key) {
        foreach ($header as $i => $h) {
            if (isset($taken[$i])) continue;
            $h = perf_imp_norm((string) $h);
            if ($h === '') continue;
            foreach ($cols[$key][1] as $kw) {
                if (mb_strpos($h, perf_imp_norm($kw)) !== false) {
                    $map[$key] = $i;
                    $taken[$i] = true;
                    continue 3;
                }
            }
        }
    }
    return $map;
}

function perf_imp_amount(string $v): int
{
    $v = trim(normalize_digits($v));
    if ($v === '') return 0;
    if (preg_match('/^\d+(\.\d+)?(E\+?\d+)?$/i', $v)) return (int) round((float) $v); // عددِ اکسل (مثلاً 15000000 یا 1.5E+7)
    $d = preg_replace('/\D/', '', $v);
    return $d === '' ? 0 : (int) $d;
}

function perf_imp_date(string $v): ?string
{
    $v = trim(normalize_digits($v));
    if ($v === '') return null;
    if (preg_match('/^(1[34]\d{2})(\d{2})(\d{2})$/', $v, $m)) { // 14050705
        return to_gregorian($m[1] . '/' . $m[2] . '/' . $m[3]);
    }
    if (function_exists('parse_row_date_to_gregorian')) {
        return parse_row_date_to_gregorian($v);
    }
    return to_gregorian($v);
}

/** «۱۴۰۵/۷»، «1405-07»، «140507»، «مهر ۱۴۰۵» → «1405/07» */
function perf_imp_period(string $v): ?string
{
    $v = perf_imp_norm(normalize_digits($v));
    if ($v === '') return '';
    if (preg_match('/^(1[34]\d{2})\s*[\/\-.]?\s*(\d{1,2})(?:\s*[\/\-.]\s*\d{1,2})?$/', $v, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        return sprintf('%04d/%02d', (int) $m[1], (int) $m[2]);
    }
    $months = ['فروردین' => 1, 'اردیبهشت' => 2, 'خرداد' => 3, 'تیر' => 4, 'مرداد' => 5, 'امرداد' => 5, 'شهریور' => 6,
               'مهر' => 7, 'آبان' => 8, 'اذر' => 9, 'آذر' => 9, 'دی' => 10, 'بهمن' => 11, 'اسفند' => 12];
    if (preg_match('/(1[34]\d{2})/', $v, $y)) {
        // نام‌های بلندتر اول (اردیبهشت قبل از دی و …)
        uksort($months, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($months as $name => $num) {
            if (mb_strpos($v, $name) !== false) {
                return sprintf('%04d/%02d', (int) $y[1], $num);
            }
        }
    }
    return null;
}

function perf_imp_kind(string $v): ?string
{
    $v = perf_imp_norm($v);
    if ($v === '') return null;
    if (mb_strpos($v, 'حقوق') !== false || mb_strpos($v, 'salary') !== false) return 'salary';
    foreach (['سهم', 'عملکرد', 'پورسانت', 'کمیسیون', 'commission'] as $k) {
        if (mb_strpos($v, $k) !== false) return 'commission';
    }
    return null;
}

/**
 * خواندن و اعتبارسنجیِ ردیف‌های فایل.
 * @return array{rows: array<int, array>, map: array, header_found: bool}
 *   هر ردیف: line, raw(name,mobile,…), user_id, user_label, kind, amount, paid_at, period_month, method, ref, note,
 *           errors[], warnings[], ok(bool), default_on(bool)
 */
function perf_payout_import_parse(PDO $pdo, array $sheetRows): array
{
    $sheetRows = array_values($sheetRows);
    $map = $sheetRows ? perf_payout_import_map((array) $sheetRows[0]) : [];
    $headerFound = isset($map['amount']) && (isset($map['mobile']) || isset($map['name']));
    if ($headerFound) {
        array_shift($sheetRows);
        $startLine = 2;
    } else {
        $map = array_flip(array_keys(perf_payout_import_columns())); // ترتیبِ فایلِ نمونه
        $startLine = 1;
    }
    // کاربران: فعال‌ها (غیرفعال‌ها فقط برای پیامِ خطای روشن)
    $users = $pdo->query('SELECT id, full_name, mobile, role, is_active FROM users')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byMobile = [];
    $byName = [];
    foreach ($users as $u) {
        $m = function_exists('normalize_phone_for_match') ? normalize_phone_for_match((string) $u['mobile']) : preg_replace('/\D/', '', (string) $u['mobile']);
        if ($m) $byMobile[$m][] = $u;
        $byName[perf_imp_norm((string) $u['full_name'])][] = $u;
    }
    $roleLbl = static fn($r) => $r === 'leader' ? 'سرپرست' : (in_array($r, ['A', 'B', 'C'], true) ? $r : (string) $r);
    $dup = $pdo->prepare('SELECT COUNT(*) FROM perf_payouts WHERE voided_at IS NULL AND user_id = ? AND kind = ? AND amount = ? AND paid_at = ? AND (? = \'\' OR ref = ?)');
    $seen = [];
    $out = [];
    foreach ($sheetRows as $i => $r) {
        $r = array_values((array) $r);
        $get = static fn(string $k): string => isset($map[$k]) ? trim((string) ($r[$map[$k]] ?? '')) : '';
        $raw = [];
        foreach (array_keys(perf_payout_import_columns()) as $k) $raw[$k] = $get($k);
        if (implode('', $raw) === '') continue; // ردیفِ خالی
        $row = ['line' => $startLine + $i, 'raw' => $raw, 'user_id' => 0, 'user_label' => '', 'errors' => [], 'warnings' => []];

        // کارشناس: اول با موبایل، بعد با نام
        $u = null;
        $mob = $raw['mobile'] !== '' && function_exists('normalize_phone_for_match') ? normalize_phone_for_match($raw['mobile']) : null;
        $nameN = perf_imp_norm($raw['name']);
        if ($raw['mobile'] !== '' && !$mob) {
            $row['warnings'][] = 'موبایل نامعتبر است';
        }
        if ($mob && !empty($byMobile[$mob])) {
            $cands = $byMobile[$mob];
            if (count($cands) > 1 && $nameN !== '') {
                $cands = array_values(array_filter($cands, static fn($c) => perf_imp_norm((string) $c['full_name']) === $nameN)) ?: $cands;
            }
            if (count($cands) > 1) {
                $act = array_values(array_filter($cands, static fn($c) => (int) $c['is_active'] === 1));
                if (count($act) === 1) $cands = $act;
            }
            if (count($cands) === 1) {
                $u = $cands[0];
                if ($nameN !== '' && perf_imp_norm((string) $u['full_name']) !== $nameN) {
                    $row['warnings'][] = 'نامِ فایل («' . $raw['name'] . '») با نامِ ثبت‌شده فرق دارد';
                }
            } else {
                $row['errors'][] = 'چند کاربر با این موبایل هست؛ نامِ دقیق را بنویسید';
            }
        } elseif ($nameN !== '' && !empty($byName[$nameN])) {
            $cands = $byName[$nameN];
            if (count($cands) > 1) {
                $act = array_values(array_filter($cands, static fn($c) => (int) $c['is_active'] === 1));
                if (count($act) === 1) $cands = $act;
            }
            if (count($cands) === 1) {
                $u = $cands[0];
                if ($raw['mobile'] !== '') $row['warnings'][] = 'با موبایل پیدا نشد؛ با نام تطبیق داده شد';
            } else {
                $row['errors'][] = 'چند کارشناس با این نام هست؛ موبایل را بنویسید';
            }
        } else {
            $row['errors'][] = ($raw['mobile'] === '' && $raw['name'] === '') ? 'نام و موبایلِ کارشناس خالی است' : 'کارشناسی با این موبایل/نام پیدا نشد';
        }
        if ($u) {
            $row['user_id'] = (int) $u['id'];
            $row['user_label'] = person_pick_label((string) $u['full_name'], $u['mobile'] ?? null, $roleLbl($u['role'] ?? ''));
            if ((int) $u['is_active'] !== 1) $row['warnings'][] = 'این کاربر غیرفعال است';
        }

        $row['kind'] = perf_imp_kind($raw['kind']);
        if (!$row['kind']) $row['errors'][] = $raw['kind'] === '' ? 'نوع خالی است (سهم عملکرد یا حقوق)' : 'نوعِ «' . $raw['kind'] . '» شناخته نشد (سهم عملکرد یا حقوق)';

        $row['amount'] = perf_imp_amount($raw['amount']);
        if ($row['amount'] <= 0) $row['errors'][] = 'مبلغ نامعتبر است';

        $row['paid_at'] = perf_imp_date($raw['date']);
        if (!$row['paid_at'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['paid_at'])) {
            $row['paid_at'] = null;
            $row['errors'][] = $raw['date'] === '' ? 'تاریخ پرداخت خالی است' : 'تاریخِ «' . $raw['date'] . '» نامعتبر است (مثل ۱۴۰۵/۰۷/۰۵)';
        } elseif ($row['paid_at'] > date('Y-m-d')) {
            $row['warnings'][] = 'تاریخ پرداخت در آینده است';
        }

        $per = perf_imp_period($raw['period']);
        if ($per === null) {
            $row['errors'][] = '«بابت ماه» نامعتبر است (مثل ۱۴۰۵/۰۷ یا مهر ۱۴۰۵)';
            $per = '';
        }
        $row['period_month'] = $per;
        if ($row['kind'] === 'salary' && $per === '') $row['warnings'][] = 'حقوق بدونِ «بابت ماه»';

        $row['method'] = mb_substr($raw['method'], 0, 40);
        $row['ref'] = mb_substr(normalize_digits($raw['ref']), 0, 100);
        $row['note'] = mb_substr($raw['note'], 0, 500);

        // تکراری: در همین فایل یا قبلاً در سیستم
        $row['duplicate'] = false;
        if (!$row['errors']) {
            $key = $row['user_id'] . '|' . $row['kind'] . '|' . $row['amount'] . '|' . $row['paid_at'] . '|' . $row['ref'];
            if (isset($seen[$key])) {
                $row['warnings'][] = 'تکراری در همین فایل (ردیف ' . to_persian_digits((string) $seen[$key]) . ')';
                $row['duplicate'] = true;
            }
            $seen[$key] = $row['line'];
            $dup->execute([$row['user_id'], $row['kind'], $row['amount'], $row['paid_at'], $row['ref'], $row['ref']]);
            if ((int) $dup->fetchColumn() > 0) {
                $row['warnings'][] = 'پرداختی با همین کارشناس، نوع، مبلغ و تاریخ قبلاً ثبت شده';
                $row['duplicate'] = true;
            }
        }
        $row['ok'] = !$row['errors'];
        $row['default_on'] = $row['ok'] && !$row['duplicate'];
        $out[] = $row;
    }
    return ['rows' => $out, 'map' => $map, 'header_found' => $headerFound];
}

/** ثبتِ ردیف‌های انتخاب‌شده (همه یکجا، در یک تراکنش) */
function perf_payout_import_save(PDO $pdo, array $rows, int $userId, string $batchNote = ''): array
{
    $ok = 0;
    $sum = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            if (empty($r['ok'])) continue;
            $res = perf_payout_add($pdo, [
                'user_id' => (int) $r['user_id'], 'kind' => (string) $r['kind'], 'amount' => (int) $r['amount'], 'paid_at' => (string) $r['paid_at'],
                'period_month' => (string) ($r['period_month'] ?? ''), 'method' => (string) ($r['method'] ?? ''), 'ref' => (string) ($r['ref'] ?? ''),
                'note' => trim((string) ($r['note'] ?? '') . ($batchNote !== '' ? ' [' . $batchNote . ']' : '')),
                'source' => 'excel_import', 'line' => (int) ($r['line'] ?? 0),
            ], $userId);
            if (!$res['ok']) {
                throw new RuntimeException('ردیف ' . ($r['line'] ?? '?') . ': ' . $res['message']);
            }
            $ok++;
            $sum += (int) $r['amount'];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('perf_payout_import_save: ' . $e->getMessage());
        return ['ok' => false, 'count' => 0, 'sum' => 0, 'message' => 'ثبتِ گروهی انجام نشد و هیچ ردیفی ذخیره نشد: ' . $e->getMessage()];
    }
    return ['ok' => true, 'count' => $ok, 'sum' => $sum, 'message' => to_persian_digits((string) $ok) . ' پرداخت به مبلغِ کلِ ' . number_format($sum) . ' تومان یکجا ثبت شد.'];
}

/* =========================================================================
   B یک‌نفره + ارجاعِ هم‌سطح (B→B و C→C) + همگام‌سازیِ مالکیت با ویرایشِ دستیِ سهم
   ========================================================================= */

/** برچسبِ نمایشیِ جایگاه: B1 / B2 / B2+ / B-round … همه «B» نمایش داده می‌شوند (B1 دیگر وجود ندارد) */
function ps_slot_label(string $slot): string
{
    if (preg_match('/^B(\d+|\d*\+)$/', $slot)) return 'B';
    if ($slot === 'B-round') return 'B (گرد کردن)';
    return $slot;
}

/** اصلاحِ متن‌های قدیمیِ ذخیره‌شده (دلیلِ خطوطِ محاسبه، روندِ زمانی) برای نمایش: «B1» ← «B» */
function ps_label_fix(string $text): string
{
    return (string) preg_replace('/(?<![A-Za-z0-9])B1(?![0-9])/u', 'B', $text);
}

/**
 * تعیین/جایگزینیِ صاحبِ یک جایگاه (A / B / C) برای شخص (۳۶۰). اگر جایگاه پر باشد، صاحبِ قبلی حذف و جدید جایش ثبت می‌شود.
 * سفارش‌های قبلی (snapshot) تغییر نمی‌کنند.
 */
function ps_owner_set(PDO $pdo, int $customerId, string $slot, int $userId, string $source, int $byUser, string $reason = ''): array
{
    if (!in_array($slot, ['A', 'B', 'C'], true)) return ['ok' => false, 'message' => 'جایگاه نامعتبر است.'];
    $u = ps_user_row($pdo, $userId);
    if (!$u) return ['ok' => false, 'message' => 'کاربر پیدا نشد.'];
    if ($u['role'] !== $slot) return ['ok' => false, 'message' => 'نقشِ «' . $u['full_name'] . '» ' . ($u['role'] ?: '—') . ' است؛ برای جایگاهِ ' . $slot . ' باید ' . $slot . ' باشد.'];
    if ($slot === 'A') {
        $ab = ps_a_basis($pdo, $customerId, $userId);
        if (!$ab['ok']) return ['ok' => false, 'message' => $u['full_name'] . ' نمی‌تواند A ِ این مشتری باشد: ' . $ab['text'] . '. A فقط کسی است که مشتری را خودش واردِ سامانه کرده یا از Box A برداشته.'];
    }
    $pk = ps_person_key($pdo, $customerId);
    $st = $pdo->prepare('SELECT * FROM ps_owners WHERE person_key = ? AND slot = ?');
    $st->execute([$pk, $slot]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 1 && (int) $rows[0]['user_id'] === $userId) return ['ok' => true, 'message' => $slot . ' = ' . $u['full_name'] . ' (بدونِ تغییر).', 'changed' => false];
    foreach ($rows as $r) {
        $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([(int) $r['id']]);
        perf_audit($pdo, $byUser, 'owner_replace_out', 'ps_owners', $pk, $r, null, $reason !== '' ? $reason : $source);
    }
    $res = ps_owner_add($pdo, $customerId, $slot, $userId, $source, $byUser);
    if (!$res['ok']) return $res;
    $prev = $rows ? (ps_user_row($pdo, (int) $rows[0]['user_id'])['full_name'] ?? '') : '';
    return ['ok' => true, 'changed' => true, 'prev_user_id' => $rows ? (int) $rows[0]['user_id'] : 0,
        'message' => $slot . ' = ' . $u['full_name'] . ($prev !== '' ? ' (به جای ' . $prev . ')' : '') . ' ثبت شد.'];
}

/** A/B/C ِ یک snapshotِ سفارش (مثلاً پس از ویرایشِ دستیِ سهم) را در مالکیتِ مشتری هم اعمال می‌کند (جایگاهِ خالی را پاک نمی‌کند) */
function ps_sync_owners_from_snapshot(PDO $pdo, int $customerId, array $snap, int $byUser, string $why): void
{
    $cur = ps_owners($pdo, $customerId);
    foreach (['A', 'C'] as $slot) {
        $uid = (int) ($snap[$slot]['user_id'] ?? 0);
        if ($uid <= 0 || ($cur[$slot] && (int) $cur[$slot]['user_id'] === $uid)) continue;
        $r = ps_owner_set($pdo, $customerId, $slot, $uid, 'manual', $byUser, $why);
        if ($r['ok'] && !empty($r['changed'])) ps_activity($pdo, $customerId, $byUser, 'مالکیت: ' . $r['message'] . ' — ' . $why);
    }
    $bs = array_values((array) ($snap['B'] ?? []));
    usort($bs, static fn($x, $y) => (int) ($x['position'] ?? 1) <=> (int) ($y['position'] ?? 1));
    $bu = (int) ($bs[0]['user_id'] ?? 0);
    if ($bu > 0 && !(count($cur['B']) === 1 && (int) $cur['B'][0]['user_id'] === $bu)) {
        $r = ps_owner_set($pdo, $customerId, 'B', $bu, 'manual', $byUser, $why);
        if ($r['ok'] && !empty($r['changed'])) ps_activity($pdo, $customerId, $byUser, 'مالکیت: ' . $r['message'] . ' — ' . $why);
    }
}

/** نیروهای هم‌سطح (هم‌نقش) برای ارجاع، با نامِ تیم و سرپرست */
function ps_peer_targets(PDO $pdo, string $role, int $excludeUserId = 0): array
{
    if (!in_array($role, ['B', 'C'], true)) return [];
    $st = $pdo->prepare("SELECT u.id, u.full_name, u.mobile, u.team_id, t.name AS team_name, l.full_name AS leader_name
        FROM users u LEFT JOIN teams t ON t.id = u.team_id LEFT JOIN users l ON l.id = t.leader_user_id
        WHERE u.role = ? AND u.is_active = 1 AND u.is_approved = 1 AND u.id <> ? ORDER BY u.team_id, u.full_name");
    try { $st->execute([$role, $excludeUserId]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) {
        $st = $pdo->prepare("SELECT id, full_name, mobile, team_id, NULL AS team_name, NULL AS leader_name FROM users WHERE role = ? AND is_active = 1 AND is_approved = 1 AND id <> ? ORDER BY full_name");
        $st->execute([$role, $excludeUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * ارجاعِ هم‌سطح: B صاحبِ مشتری ← B دیگر، یا C صاحبِ مشتری ← C دیگر (از هر تیمی).
 *  - از این لحظه گیرنده صاحبِ جایگاه است و قبلی دیگر حساب نمی‌شود (ps_owners جایگزین می‌شود)
 *  - همان پرونده (نه کپی) با همه‌ی پیگیری‌ها، وضعیت و روندِ زمانی به گیرنده منتقل می‌شود و گیرنده پیگیری‌های قبلی را می‌بیند
 *  - سهمِ D این جایگاه از این به بعد به سرپرستِ تیمِ «گیرنده» می‌رسد (در snapshotِ سفارش‌های بعدی و سفارش‌های هنوز محاسبه‌نشده)
 *  - در گزارشِ ارجاع (customer_referrals) هم ثبت می‌شود
 * @param array $by کاربرِ ارجاع‌دهنده
 * @param string|null $slot فقط برای مدیر (B یا C)؛ برای خودِ B/C از نقشِ خودش گرفته می‌شود
 */
function ps_peer_refer(PDO $pdo, int $customerId, int $toUserId, array $by, string $note = '', ?string $slot = null): array
{
    if (!perf_ready($pdo)) return ['ok' => false, 'message' => 'ماژولِ سهم عملکرد آماده نیست.'];
    $byId = (int) $by['id'];
    $role = (string) ($by['role'] ?? '');
    $manage = perf_can('owners', $by) || perf_can('box_manage', $by);
    if (in_array($role, ['B', 'C'], true)) $slot = $role;
    if (!in_array($slot, ['B', 'C'], true)) return ['ok' => false, 'message' => 'ارجاعِ هم‌سطح فقط برای جایگاه‌های B و C است.'];
    $own = ps_owners($pdo, $customerId);
    $cur = $slot === 'B' ? ($own['B'][0] ?? null) : $own['C'];
    $isOwner = $cur && (int) $cur['user_id'] === $byId;
    if (!$isOwner && !$manage) {
        return ['ok' => false, 'message' => 'فقط ' . $slot . 'ِ ثبت‌شده‌ی همین مشتری می‌تواند او را به ' . $slot . ' دیگری ارجاع دهد'
            . ($cur ? ' (' . $slot . ' فعلی: ' . $cur['full_name'] . ').' : ' (این مشتری هنوز ' . $slot . ' ندارد).')];
    }
    $to = ps_user_row($pdo, $toUserId);
    if (!$to || $to['role'] !== $slot) return ['ok' => false, 'message' => 'گیرنده باید نیروی هم‌سطح (نقشِ ' . $slot . ') باشد.'];
    $act = $pdo->prepare('SELECT is_active, is_approved FROM users WHERE id = ?');
    $act->execute([$toUserId]);
    $a = $act->fetch(PDO::FETCH_ASSOC);
    if (!$a || (int) $a['is_active'] !== 1 || (int) $a['is_approved'] !== 1) return ['ok' => false, 'message' => 'گیرنده فعال نیست.'];
    if ($cur && (int) $cur['user_id'] === $toUserId) return ['ok' => false, 'message' => $to['full_name'] . ' همین الان ' . $slot . 'ِ این مشتری است.'];
    $fromId = $cur ? (int) $cur['user_id'] : 0;
    $fromName = $cur ? (string) $cur['full_name'] : '—';
    $pk = ps_person_key($pdo, $customerId);
    if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
    $ids = array_map('intval', cc_person_ids($pdo, $customerId) ?: [$customerId]);
    if (!in_array($customerId, $ids, true)) $ids[] = $customerId;

    $pdo->beginTransaction();
    try {
        $r = ps_owner_set($pdo, $customerId, $slot, $toUserId, 'peer', $byId, 'ارجاعِ هم‌سطح' . ($note !== '' ? ': ' . $note : ''));
        if (!$r['ok']) throw new RuntimeException($r['message']);

        // پرونده(های) ارجاع‌دهنده/صاحبِ قبلی ← گیرنده (همان پرونده با همه‌ی پیگیری‌ها و روندِ زمانی)
        $moved = [];
        if ($fromId) {
            $in = implode(',', $ids);
            $q = $pdo->prepare("SELECT id FROM customers WHERE id IN ($in) AND owner_user_id = ?");
            $q->execute([$fromId]);
            $moved = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN) ?: []);
        }
        if (!$moved) $moved = [ps_ensure_record($pdo, $customerId, $toUserId, true)];
        $toTeamTxt = $to['team_id'] ? ' (تیم ' . (int) $to['team_id'] . ($to['leader_name'] ? ' — سرپرست: ' . $to['leader_name'] : '') . ')' : '';
        $why = 'ارجاعِ هم‌سطحِ ' . $slot . ': از «' . $fromName . '» به «' . $to['full_name'] . '»' . $toTeamTxt
            . ' — از این به بعد ' . $slot . 'ِ مشتری گیرنده است و سهمِ D این جایگاه به سرپرستِ تیمِ گیرنده می‌رسد' . ($note !== '' ? ' — توضیح: ' . $note : '');
        $byRow = ps_user_row($pdo, $byId);
        $orig = $pdo->prepare('SELECT origin_team_id FROM ps_box_items WHERE person_key = ? AND origin_team_id IS NOT NULL ORDER BY id ASC LIMIT 1');
        $orig->execute([$pk]);
        $originTeam = $orig->fetchColumn();
        $now = date('Y-m-d H:i:s');
        foreach (array_unique($moved) as $rid) {
            // ارجاع‌دهنده = کسی که پرونده واقعاً دستش بود (نه فقط صاحبِ جایگاه)
            $prevSt = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ?');
            $prevSt->execute([$rid]);
            $recFrom = (int) $prevSt->fetchColumn();
            $pdo->prepare('UPDATE customers SET owner_user_id = ?, new_customer_notified = 0 WHERE id = ?')->execute([$toUserId, $rid]);
            if (function_exists('get_or_create_relation')) { try { get_or_create_relation($pdo, $rid, $toUserId, 'peer_referral', true); } catch (Throwable $e) {} }
            // ثبت در گزارشِ ارجاع
            try {
                $__from = $recFrom && $recFrom !== $toUserId && $recFrom !== ps_box_user_id($pdo) ? $recFrom : ($fromId ?: $byId);
                if (function_exists('referral_log')) referral_log($pdo, $rid, $__from, $toUserId, $byId, 'peer', $note);
                else $pdo->prepare('INSERT INTO customer_referrals (customer_id, from_user_id, to_user_id, referred_by) VALUES (?,?,?,?)')
                    ->execute([$rid, $__from, $toUserId, $byId]);
            } catch (Throwable $e) {}
            // «تحویلِ پرونده» ← گیرنده همه‌ی پیگیری‌ها و روندِ زمانیِ قبل از این لحظه را می‌بیند (ps_inherit_cutoff)
            $pdo->prepare('INSERT INTO ps_box_items (box, customer_id, person_key, status, source, note, entered_by, entered_at, claimed_by, claimed_at, prev_owner_id, entered_team_id, origin_team_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$slot, $rid, $pk, 'claimed', 'peer', mb_substr('ارجاعِ هم‌سطح' . ($note !== '' ? ': ' . $note : ''), 0, 300), $byId, $now, $toUserId, $now,
                    $fromId ?: null, $byRow && $byRow['team_id'] ? (int) $byRow['team_id'] : null, $originTeam ? (int) $originTeam : null]);
            ps_activity($pdo, $rid, $byId, $why);
        }

        // سفارش‌هایی که هنوز هیچ پرداختِ محاسبه‌شده‌ای ندارند ← گیرنده جایگزین می‌شود (پرداخت‌های محاسبه‌شده دست نمی‌خورند)
        $in = implode(',', $ids);
        $os = $pdo->query("SELECT s.order_id, s.owners_json FROM ps_order_snapshots s JOIN sales_orders o ON o.id = s.order_id
            WHERE o.customer_id IN ($in) AND o.status NOT IN ('cancelled','rejected')
            AND NOT EXISTS (SELECT 1 FROM ps_payment_calcs c WHERE c.order_id = s.order_id AND c.voided_at IS NULL)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare('UPDATE ps_order_snapshots SET owners_json = ? WHERE order_id = ?');
        $nOrders = 0;
        foreach ($os as $o) {
            $ow = json_decode((string) $o['owners_json'], true) ?: [];
            if (!empty($ow['manual_edit'])) continue;
            $p = ps_snapshot_person($pdo, $toUserId, 1);
            if ($slot === 'B') $ow['B'] = [$p]; else $ow['C'] = $p;
            $upd->execute([json_encode($ow, JSON_UNESCAPED_UNICODE), (int) $o['order_id']]);
            $nOrders++;
        }
        perf_audit($pdo, $byId, 'peer_refer', 'ps_owners', $pk, ['slot' => $slot, 'user_id' => $fromId],
            ['slot' => $slot, 'user_id' => $toUserId, 'team_id' => $to['team_id'], 'leader_id' => $to['leader_user_id'], 'records' => $moved, 'orders_updated' => $nOrders], $note);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ps_peer_refer: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'ارجاع انجام نشد: ' . $e->getMessage()];
    }
    return ['ok' => true, 'customer_id' => (int) $moved[0], 'message' => 'مشتری به «' . $to['full_name'] . '» ارجاع شد؛ از این به بعد او ' . $slot . 'ِ این مشتری است'
        . ($to['team_id'] ? ' و سهمِ D این جایگاه به سرپرستِ تیمِ ' . (int) $to['team_id'] . ($to['leader_name'] ? ' (' . $to['leader_name'] . ')' : '') . ' می‌رسد' : '') . '.'];
}

/**
 * یک‌بار (نسخه‌ی ۱۰): حذفِ قانونِ B2 به بعد.
 *  ۱) هر شخص فقط یک B نگه می‌دارد (B1، و اگر نبود اولین B)؛ بقیه با ثبت در perf_audit حذف می‌شوند
 *  ۲) مالکیتِ مشتری با آخرین ویرایشِ دستیِ سهمِ سفارش‌ها همگام می‌شود (مثلاً C = کسی که مالی تعیین کرده)
 *  ۳) متنِ «B1» در روندِ زمانیِ Box به «B» اصلاح می‌شود
 */
function ps_single_b_v10(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_single_b_v10';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    try {
        @set_time_limit(900);
        $rows = $pdo->query("SELECT * FROM ps_owners WHERE slot = 'B' ORDER BY person_key, position, id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $kept = [];
        foreach ($rows as $r) {
            $pk = (int) $r['person_key'];
            if (!isset($kept[$pk])) { $kept[$pk] = (int) $r['id']; continue; }
            $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([(int) $r['id']]);
            perf_audit($pdo, 0, 'owner_remove', 'ps_owners', $pk, $r, null, 'حذفِ قانونِ B2 به بعد — هر مشتری فقط یک B');
        }
        $pdo->exec("UPDATE ps_owners SET position = 1 WHERE slot = 'B' AND position <> 1");
    } catch (Throwable $e) {
        error_log('ps_single_b_v10 (owners): ' . $e->getMessage());
    }
    try {
        $snaps = $pdo->query("SELECT s.order_id, s.owners_json, o.customer_id FROM ps_order_snapshots s JOIN sales_orders o ON o.id = s.order_id
            WHERE s.owners_json LIKE '%\"manual_edit\":true%' ORDER BY s.order_id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($snaps as $sn) {
            $ow = json_decode((string) $sn['owners_json'], true) ?: [];
            ps_sync_owners_from_snapshot($pdo, (int) $sn['customer_id'], $ow, 0, 'همگام‌سازی با ویرایشِ دستیِ سهمِ سفارشِ #' . (int) $sn['order_id']);
        }
    } catch (Throwable $e) {
        error_log('ps_single_b_v10 (manual sync): ' . $e->getMessage());
    }
    try {
        $pdo->exec("UPDATE customer_activity_logs SET description = REPLACE(description, 'B1', 'B') WHERE activity_type = 'box' AND description LIKE '%B1%'");
    } catch (Throwable $e) {
    }
}


/* =========================================================================
   قانونِ A (نسخه‌ی ۱۱): سهمِ A فقط برای مشتریِ «جدید» یا «دریافت‌شده از Box A»
   ========================================================================= */

/** لحظه‌ی راه‌اندازیِ Boxها (اولین ورود به Box) — مشتریانی که قبل از آن ساخته شده‌اند «قدیمی» حساب می‌شوند */
function ps_launch_at(PDO $pdo): ?string
{
    static $c = false;
    if ($c !== false) return $c;
    try {
        $v = $pdo->query('SELECT MIN(entered_at) FROM ps_box_items')->fetchColumn();
        $c = $v ? (string) $v : null;
    } catch (Throwable $e) {
        $c = null;
    }
    return $c;
}

/**
 * چه کسی این پرونده را «واردِ سامانه» کرده؟ (نه صاحبِ فعلی — صاحبِ فعلی ممکن است پرونده را بعداً تحویل گرفته باشد)
 *   ۱) لاگِ «ثبتِ مشتری» (customer_activity_logs: create) ← همان کاربر
 *   ۲) اولین انتقال/ارجاعِ پرونده ← فرستنده (صاحبِ قبل از اولین انتقال)
 *   ۳) اولین رابطه‌ی کارشناس با پرونده، اگر از راهِ ثبت/تماسِ خودِ کارشناس بوده (نه Box/ارجاع/ایمپورتِ مدیر)
 *   ۴) پرونده‌ای که هیچ رابطه و انتقالی ندارد ← صاحبِ پرونده
 * @return array{uid:int, how:string}
 */
function ps_customer_creator(PDO $pdo, int $recId): array
{
    static $cache = [];
    if (isset($cache[$recId])) return $cache[$recId];
    try {
        $q = $pdo->prepare("SELECT user_id, created_at FROM customer_activity_logs WHERE customer_id = ? AND activity_type = 'create' AND user_id IS NOT NULL ORDER BY created_at ASC, id ASC LIMIT 1");
        $q->execute([$recId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $cache[$recId] = ['uid' => (int) $r['user_id'], 'how' => 'ثبتِ مشتری در سامانه'];
    } catch (Throwable $e) {}
    foreach (['customer_referrals', 'customer_handoffs'] as $t) {
        try {
            $q = $pdo->prepare("SELECT from_user_id FROM $t WHERE customer_id = ? AND from_user_id > 0 ORDER BY created_at ASC, id ASC LIMIT 1");
            $q->execute([$recId]);
            if ($u = (int) $q->fetchColumn()) return $cache[$recId] = ['uid' => $u, 'how' => 'صاحبِ پرونده قبل از اولین انتقال'];
        } catch (Throwable $e) {}
    }
    $hasRel = false;
    try {
        $q = $pdo->prepare('SELECT employee_id, created_by_source FROM customer_employee_relations WHERE customer_id = ? ORDER BY created_at ASC, id ASC LIMIT 1');
        $q->execute([$recId]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
            $hasRel = true;
            if (in_array((string) $r['created_by_source'], ['call_import', 'novatel_import', 'manual_link', 'legacy_owner'], true)) {
                return $cache[$recId] = ['uid' => (int) $r['employee_id'], 'how' => 'اولین کارشناسِ پرونده (' . (string) $r['created_by_source'] . ')'];
            }
        }
    } catch (Throwable $e) {}
    if (!$hasRel) {
        $q = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ?');
        $q->execute([$recId]);
        if ($u = (int) $q->fetchColumn()) return $cache[$recId] = ['uid' => $u, 'how' => 'صاحبِ پرونده (بدونِ هیچ انتقالی)'];
    }
    return $cache[$recId] = ['uid' => 0, 'how' => 'واردکننده مشخص نیست (ایمپورت/انتقال)'];
}

/**
 * مبنای جایگاهِ A — فقط دو حالت:
 *   ۱) کارشناس مشتری را از Box A برداشته، یا
 *   ۲) خودش مشتری را واردِ سامانه کرده (قدیمی‌ترین پرونده‌ی این شماره در کلِ ۳۶۰، بعد از راه‌اندازیِ Boxها).
 * مشتریانِ قدیمی که در Box A ریخته نشده‌اند ← A ندارند (سهمِ A برای سازمان). تعیینِ دستی هم استثنا نیست.
 * @return array{ok:bool, text:string}
 */
function ps_a_basis(PDO $pdo, int $customerId, int $userId): array
{
    static $cache = [];
    $key = $customerId . ':' . $userId;
    if (isset($cache[$key])) return $cache[$key];
    $pk = ps_person_key($pdo, $customerId);
    try {
        $b = $pdo->prepare("SELECT claimed_at FROM ps_box_items WHERE person_key = ? AND box = 'A' AND status = 'claimed' AND claimed_by = ? ORDER BY claimed_at ASC LIMIT 1");
        $b->execute([$pk, $userId]);
        $at = $b->fetchColumn();
        if ($at !== false) return $cache[$key] = ['ok' => true, 'text' => 'از Box A برداشته' . ($at ? ' (' . to_jalali(substr((string) $at, 0, 10)) . ')' : '')];
    } catch (Throwable $e) {}
    try {
        if (!function_exists('cc_person_ids')) require_once __DIR__ . '/customer_credit.php';
        $ids = array_map('intval', cc_person_ids($pdo, $customerId) ?: []);
    } catch (Throwable $e) {
        $ids = [];
    }
    if (!in_array($customerId, $ids, true)) $ids[] = $customerId;
    $in = implode(',', $ids);
    $first = $pdo->query("SELECT id, owner_user_id, created_at FROM customers WHERE id IN ($in) ORDER BY created_at ASC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$first) return $cache[$key] = ['ok' => false, 'text' => 'پرونده پیدا نشد'];
    $cr = ps_customer_creator($pdo, (int) $first['id']);
    $crName = $cr['uid'] ? (ps_user_row($pdo, $cr['uid'])['full_name'] ?? ('#' . $cr['uid'])) : '';
    $when = to_jalali(substr((string) $first['created_at'], 0, 10));
    $launch = ps_launch_at($pdo);
    if ($launch === null || (string) $first['created_at'] < $launch) {
        return $cache[$key] = ['ok' => false, 'text' => 'مشتریِ قدیمی (ثبت ' . $when . '، قبل از راه‌اندازیِ Box) و از Box A برداشته نشده'];
    }
    if ($cr['uid'] === $userId) return $cache[$key] = ['ok' => true, 'text' => 'خودش مشتری را واردِ سامانه کرده (' . $when . ' — ' . $cr['how'] . ')'];
    return $cache[$key] = ['ok' => false, 'text' => 'نه از Box A برداشته، نه خودش واردِ سامانه کرده (واردکننده: ' . ($crName !== '' ? $crName : $cr['how']) . '، ' . $when . ')'];
}

function ps_a_eligible(PDO $pdo, int $customerId, int $userId): bool
{
    return ps_a_basis($pdo, $customerId, $userId)['ok'];
}

/** مبنای جایگاهِ B/C از روی ردیفِ مالکیت (ps_owners) */
function ps_owner_basis(PDO $pdo, ?array $row): string
{
    if (!$row) return 'مالکیتِ مشتری در لحظه‌ی ثبتِ سفارش';
    $slot = (string) ($row['slot'] ?? '');
    $at = !empty($row['created_at']) ? ' (' . to_jalali(substr((string) $row['created_at'], 0, 10)) . ')' : '';
    switch ((string) ($row['source'] ?? '')) {
        case 'box': return 'از Box ' . $slot . ' برداشته' . $at;
        case 'payment': return 'ثبتِ فیش (اولین پولِ مشتری را خودش گرفته)' . $at;
        case 'peer': return 'ارجاعِ هم‌سطح از کارشناسِ قبلی' . $at;
        case 'c_revive': return 'برگشتِ مشتریِ خودش از Box C' . $at;
        case 'manual':
            $by = !empty($row['created_by']) ? (ps_user_row($pdo, (int) $row['created_by'])['full_name'] ?? '') : '';
            return 'تعیینِ دستی' . ($by !== '' ? ' توسطِ ' . $by : '') . $at;
        default: return 'منبع: ' . (string) ($row['source'] ?? '—') . $at;
    }
}

/**
 * یک‌بار (نسخه‌ی ۱۱): اصلاحِ A‌هایی که فقط به‌خاطرِ «ثبتِ فیش» ثبت شده بودند ولی مشتری قدیمی بوده (نه جدید، نه از Box A):
 *   - A از snapshotِ سفارش‌ها برداشته و محاسبه‌ها دوباره انجام می‌شود (سهمِ A ← سازمان)
 *   - جایگاهِ A در مالکیتِ مشتری (پروفایلِ ۳۶۰) پاک می‌شود
 * A‌هایی که از Box، تعیینِ دستی یا ویرایشِ دستیِ سهمِ سفارش آمده‌اند دست نمی‌خورند.
 */
function ps_a_rule_v11(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_a_rule_v11';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    @set_time_limit(900);
    $why = 'قانونِ A: مشتریِ قدیمی (نه جدید، نه از Box A) ← سهمِ A برای سازمان — محاسبه‌ی مجدد';
    try {
        $snaps = $pdo->query("SELECT s.order_id, s.owners_json, s.person_key, o.customer_id FROM ps_order_snapshots s JOIN sales_orders o ON o.id = s.order_id
            WHERE s.owners_json LIKE '%\"A\":{%'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $vc = $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE order_id = ? AND voided_at IS NULL');
        $vl = $pdo->prepare('UPDATE ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id SET l.voided = 1 WHERE c.order_id = ? AND c.void_reason = ? AND l.voided = 0');
        $upd = $pdo->prepare('UPDATE ps_order_snapshots SET owners_json = ? WHERE order_id = ?');
        $ownA = $pdo->prepare("SELECT * FROM ps_owners WHERE person_key = ? AND slot = 'A' LIMIT 1");
        foreach ($snaps as $sn) {
            $ow = json_decode((string) $sn['owners_json'], true) ?: [];
            if (!empty($ow['manual_edit'])) continue;
            $aUid = (int) ($ow['A']['user_id'] ?? 0);
            if ($aUid <= 0) continue;
            $ownA->execute([(int) $sn['person_key']]);
            $row = $ownA->fetch(PDO::FETCH_ASSOC);
            if ($row && (int) $row['user_id'] === $aUid && $row['source'] !== 'payment') continue; // از Box / دستی
            if (ps_a_eligible($pdo, (int) $sn['customer_id'], $aUid)) continue;
            $old = $ow['A'];
            $ow['A'] = null;
            $upd->execute([json_encode($ow, JSON_UNESCAPED_UNICODE), (int) $sn['order_id']]);
            perf_audit($pdo, 0, 'a_rule_fix', 'sales_orders', (int) $sn['order_id'], ['A' => $old], ['A' => null], $why);
            $vc->execute([date('Y-m-d H:i:s'), $why, (int) $sn['order_id']]);
            $vl->execute([(int) $sn['order_id'], $why]);
            ps_sync_order($pdo, (int) $sn['order_id'], 0);
        }
        // جایگاهِ A‌ای که فقط با «ثبتِ فیش» آمده و شرایط را ندارد
        $rows = $pdo->query("SELECT o.*, (SELECT c.id FROM customers c WHERE c.id = o.person_key LIMIT 1) AS cid FROM ps_owners o WHERE o.slot = 'A' AND o.source = 'payment'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $cid = (int) ($r['cid'] ?: $r['person_key']);
            if (ps_a_eligible($pdo, $cid, (int) $r['user_id'])) continue;
            $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([(int) $r['id']]);
            perf_audit($pdo, 0, 'owner_remove', 'ps_owners', (int) $r['person_key'], $r, null, $why);
        }
    } catch (Throwable $e) {
        error_log('ps_a_rule_v11: ' . $e->getMessage());
    }
}

/**
 * یک‌بار (نسخه‌ی ۱۴) — اصلاحِ کاملِ گذشته:
 *   ۱) قانونِ A بدونِ استثنا: A فقط کسی است که مشتری را خودش واردِ سامانه کرده یا از Box A برداشته.
 *      هر A ِ دیگری (از هر منبعی، حتی تعیینِ دستی یا ویرایشِ دستیِ سهمِ سفارش) از snapshotِ سفارش و از مالکیتِ مشتری برداشته می‌شود
 *      ← سهمِ A و سهمِ D(A) به سازمان.
 *   ۲) مبنای هر جایگاه (A/B/C) در snapshot نوشته می‌شود تا در صفحه‌ی سهم دیده شود «از چه راهی» به او رسیده.
 *   ۳) قانونِ جدیدِ D (سه سهمِ ثابت) ← همه‌ی سفارش‌ها دوباره محاسبه می‌شوند.
 */
function ps_a_d_rule_v14(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.perf_share_a_d_rule_v14';
    if (is_file($flag)) return;
    @file_put_contents($flag, (string) time());
    @set_time_limit(1800);
    $why = 'قانونِ A (فقط واردکننده یا برداشتن از Box A — بدونِ استثنا) + قانونِ جدیدِ D (هر جایگاه یک سهم) — محاسبه‌ی مجدد';
    try {
        // ۱) مالکیتِ A (پروفایلِ ۳۶۰)
        $rows = $pdo->query("SELECT o.*, (SELECT c.id FROM customers c WHERE c.id = o.customer_id LIMIT 1) AS cid FROM ps_owners o WHERE o.slot = 'A'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $cid = (int) ($r['cid'] ?: $r['person_key']);
            $ab = ps_a_basis($pdo, $cid, (int) $r['user_id']);
            if ($ab['ok']) continue;
            $pdo->prepare('DELETE FROM ps_owners WHERE id = ?')->execute([(int) $r['id']]);
            perf_audit($pdo, 0, 'owner_remove', 'ps_owners', (int) $r['person_key'], $r, null, 'قانونِ A: ' . $ab['text'] . ' ← سهمِ A برای سازمان');
        }
        // ۲) snapshotِ سفارش‌ها: A ِ بی‌شرط حذف + مبنای هر جایگاه
        $snaps = $pdo->query('SELECT s.order_id, s.owners_json, s.person_key, o.customer_id FROM ps_order_snapshots s JOIN sales_orders o ON o.id = s.order_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $upd = $pdo->prepare('UPDATE ps_order_snapshots SET owners_json = ? WHERE order_id = ?');
        $ownQ = $pdo->prepare('SELECT * FROM ps_owners WHERE person_key = ? AND slot = ? AND user_id = ? LIMIT 1');
        $regQ = $pdo->prepare("SELECT 1 FROM perf_audit WHERE action = 'registrant_share' AND entity = 'sales_orders' AND entity_id = ? LIMIT 1");
        foreach ($snaps as $sn) {
            $ow = json_decode((string) $sn['owners_json'], true) ?: [];
            $orig = $ow;
            $manual = !empty($ow['manual_edit']);
            $aUid = (int) ($ow['A']['user_id'] ?? 0);
            if ($aUid > 0) {
                $ab = ps_a_basis($pdo, (int) $sn['customer_id'], $aUid);
                if (!$ab['ok']) {
                    perf_audit($pdo, 0, 'a_rule_fix', 'sales_orders', (int) $sn['order_id'], ['A' => $ow['A']], ['A' => null], 'قانونِ A: ' . $ab['text'] . ' ← سهمِ A و D(A) برای سازمان');
                    $ow['A_note'] = ($ow['A']['name'] ?? ('#' . $aUid)) . ' — ' . $ab['text'];
                    $ow['A'] = null;
                } elseif (empty($ow['A']['basis'])) {
                    $ow['A']['basis'] = $ab['text'] . ($manual ? ' — ویرایشِ دستیِ سهمِ سفارش' : '');
                }
            }
            $basisOf = static function (string $slot, array $p) use ($pdo, $sn, $ownQ, $regQ, $manual): string {
                if ($manual) return 'ویرایشِ دستیِ سهمِ سفارش';
                $ownQ->execute([(int) $sn['person_key'], $slot, (int) $p['user_id']]);
                if ($row = $ownQ->fetch(PDO::FETCH_ASSOC)) return ps_owner_basis($pdo, $row);
                try { $regQ->execute([(int) $sn['order_id']]); if ($regQ->fetchColumn()) return 'ثبتِ فیشِ همین سفارش (اولین پولِ مشتری را خودش گرفته)'; } catch (Throwable $e) {}
                return 'مالکیتِ مشتری در لحظه‌ی ثبتِ سفارش';
            };
            if (!empty($ow['C']) && empty($ow['C']['basis'])) $ow['C']['basis'] = $basisOf('C', $ow['C']);
            foreach ((array) ($ow['B'] ?? []) as $i => $bb) if ($bb && empty($bb['basis'])) $ow['B'][$i]['basis'] = $basisOf('B', $bb);
            if ($ow !== $orig) $upd->execute([json_encode($ow, JSON_UNESCAPED_UNICODE), (int) $sn['order_id']]);
        }
        // ۳) محاسبه‌ی مجددِ همه‌ی سفارش‌ها
        $ids = $pdo->query('SELECT DISTINCT order_id FROM ps_payment_calcs WHERE voided_at IS NULL')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $vc = $pdo->prepare('UPDATE ps_payment_calcs SET voided_at = ?, void_reason = ? WHERE order_id = ? AND voided_at IS NULL');
        $vl = $pdo->prepare('UPDATE ps_lines l JOIN ps_payment_calcs c ON c.id = l.calc_id SET l.voided = 1 WHERE c.order_id = ? AND c.void_reason = ? AND l.voided = 0');
        foreach ($ids as $oid) {
            $vc->execute([date('Y-m-d H:i:s'), $why, (int) $oid]);
            $vl->execute([(int) $oid, $why]);
            ps_sync_order($pdo, (int) $oid, 0);
        }
    } catch (Throwable $e) {
        error_log('ps_a_d_rule_v14: ' . $e->getMessage());
    }
}
