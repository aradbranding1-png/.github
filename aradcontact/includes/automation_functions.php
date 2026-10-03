<?php
/**
 * توابعِ هسته‌ی ماژولِ «اتوماسیون اداری».
 * این فایل باید بعد از includes/auth.php require بشه (به db()، e()، csrf_*، redirect() و... نیاز داره).
 *
 * الگوی سازگاریِ عقب‌رو: automation_ready() چک می‌کنه جدول‌های این ماژول (از
 * database/migration_automation_v1.sql) رویِ دیتابیس ساخته شدن یا نه — تا وقتی کسی
 * «بروزرسانی سیستم» رو اجرا نکرده، بقیه‌ی سایت بدونِ خطا کار کنه و فقط لینکِ
 * «اتوماسیون» توی سایدبار دیده نشه.
 */

/**
 * چند واحدی بودنِ اعضا + نامه به «رده‌ی سازمانی» (یک‌بار):
 *  - automation_user_positions: یک ردیف به ازای هر (کاربر، واحد) + is_primary (واحدِ اصلی برای مسیرِ تایید/نمایش)
 *  - letter_recipients: recipient_kind = 'level' و ستونِ level_id
 */
function automation_multi_unit_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.automation_multi_unit_v1';
    if (is_file($flag)) return $ok = true;
    try {
        try { $pdo->query('SELECT is_primary FROM automation_user_positions LIMIT 1'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE automation_user_positions ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 1'); }
        try { $pdo->exec('ALTER TABLE automation_user_positions ADD UNIQUE KEY uq_aup_user_unit (user_id, unit_id)'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE automation_user_positions DROP INDEX uq_automation_user_positions_user'); } catch (Throwable $e) {}
        try { $pdo->exec('ALTER TABLE automation_user_positions ADD KEY idx_aup_user (user_id)'); } catch (Throwable $e) {}
        try { $pdo->query('SELECT level_id FROM letter_recipients LIMIT 1'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE letter_recipients ADD COLUMN level_id INT UNSIGNED DEFAULT NULL'); }
        try { $pdo->exec("ALTER TABLE letter_recipients MODIFY recipient_kind ENUM('user','unit','level') NOT NULL"); } catch (Throwable $e) {}
    } catch (Throwable $e) {
        error_log('automation_multi_unit_ready: ' . $e->getMessage());
        return $ok = false;
    }
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

/** اگر کاربر واحدِ اصلی ندارد (مثلاً بعد از حذفِ عضویتِ اصلی)، قدیمی‌ترین عضویتش اصلی می‌شود */
function automation_fix_primary(PDO $pdo, int $userId): void
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM automation_user_positions WHERE user_id = ? AND is_primary = 1');
        $st->execute([$userId]);
        if ((int) $st->fetchColumn() === 0) {
            $pdo->prepare('UPDATE automation_user_positions SET is_primary = 1 WHERE user_id = ? ORDER BY id ASC LIMIT 1')->execute([$userId]);
        }
    } catch (Throwable $e) {}
}

function automation_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    // این تابع در layout_top.php روی همه‌یِ صفحاتِ سایت صدا زده می‌شه، پس نباید هر بار
    // کوئریِ INFORMATION_SCHEMA بزنه (این کوئری روی خیلی از هاست‌ها کند است و باعثِ
    // کندیِ کلِ سایت می‌شه). دو لایه کش داریم تا حتی اگه نوشتنِ فایل روی هاست به هر
    // دلیلی (مجوزِ پوشه و...) ممکن نباشه، بازم کوئری تکرار نشه:
    //  ۱) کشِ سشن (نیازی به نوشتنِ فایل نداره، برایِ همون کاربر تا پایانِ سشن معتبره)
    //  ۲) فایلِ پرچم (برایِ همه‌ی کاربرها/ریکوئست‌هایِ بعدی مشترکه)
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['__automation_ready'])) {
        $ready = true;
        return $ready;
    }

    $flagFile = __DIR__ . '/../storage/.automation_ready';
    if (is_file($flagFile)) {
        $ready = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['__automation_ready'] = true;
        }
        return $ready;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'letters'");
        $stmt->execute();
        $ready = ((int) $stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $ready = false;
    }

    if ($ready) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['__automation_ready'] = true;
        }
        $storageDir = dirname($flagFile);
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents($flagFile, (string) time());
    }

    return $ready;
}

// -----------------------------------------------------------------
// Permission
// -----------------------------------------------------------------

/** لیستِ کدهایِ Permission موجود در سیستم (کد => برچسبِ فارسی)، هم‌راستا با جدولِ automation_permissions */
function automation_permission_codes(): array
{
    return [
        'letter_view'                => 'مشاهده نامه',
        'letter_create'               => 'ایجاد نامه',
        'letter_send'                  => 'ارسال نامه',
        'letter_forward'               => 'ارجاع',
        'letter_approve'               => 'تایید',
        'letter_reject'                => 'رد',
        'letter_return'                => 'برگشت برای اصلاح',
        'letter_reply'                  => 'پاسخ',
        'letter_view_cc'                => 'مشاهده رونوشت',
        'letter_view_attachment'        => 'مشاهده پیوست',
        'letter_download_attachment'    => 'دانلود پیوست',
        'letter_archive'                => 'بایگانی',
        'letter_view_reports'           => 'مشاهده گزارش‌ها',
        'org_structure_manage'          => 'مدیریت ساختار سازمانی',
        'automation_users_manage'       => 'مدیریت کاربرانِ اتوماسیون',
        'letter_view_all'               => 'مشاهده همه مکاتبات',
        'audit_log_view'                => 'مشاهده Audit Log',
        'letter_hide_sender'            => 'انتخابِ نمایش/پنهان‌کردنِ نامِ فرستنده در نامه',
    ];
}

/**
 * نامِ فرستنده در نامه پنهان شود؟ (ستونِ letters.hide_sender_name)
 * یک‌بار: ستون ساخته می‌شود و مجوزِ «letter_hide_sender» به همه‌ی ادمین‌ها داده می‌شود (ادمینِ کل همیشه دارد).
 */
function automation_hide_sender_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.automation_hide_sender_v1';
    if (is_file($flag)) return $ok = true;
    try {
        try { $pdo->query('SELECT hide_sender_name FROM letters LIMIT 0'); }
        catch (Throwable $e) { $pdo->exec('ALTER TABLE letters ADD COLUMN hide_sender_name TINYINT(1) NOT NULL DEFAULT 0'); }
        $pdo->exec("INSERT IGNORE INTO automation_user_permissions (user_id, permission_code) SELECT id, 'letter_hide_sender' FROM users WHERE role = 'admin'");
    } catch (Throwable $e) {
        error_log('automation_hide_sender_ready: ' . $e->getMessage());
        return $ok = false;
    }
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

/** پیش‌فرضِ پنهان‌بودنِ نامِ فرستنده برای یک واحد: «مدیران عالی» (و «مدیریت عالی») ← پنهان؛ بقیه ← نمایش */
function automation_unit_hides_sender_by_default(?string $unitTitle): bool
{
    $t = automation_norm_title((string) $unitTitle);
    return mb_strpos($t, 'مدیرانعالی') !== false || mb_strpos($t, 'مدیریتعالی') !== false; // automation_norm_title فاصله‌ها را حذف می‌کند
}

/** اگر نامِ فرستنده‌ی این نامه پنهان است: نامِ واحدِ فرستنده (به‌جای نامِ فرد)، وگرنه null */
function automation_letter_hidden_sender(PDO $pdo, int $letterId): ?array
{
    static $cache = [];
    if (array_key_exists($letterId, $cache)) return $cache[$letterId];
    if (!automation_hide_sender_ready($pdo)) return $cache[$letterId] = null;
    try {
        $st = $pdo->prepare('SELECT l.sender_user_id, l.hide_sender_name, u.title AS unit_title FROM letters l LEFT JOIN automation_org_units u ON u.id = l.sender_unit_id WHERE l.id = ?');
        $st->execute([$letterId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $r = null;
    }
    if (!$r || (int) $r['hide_sender_name'] !== 1) return $cache[$letterId] = null;
    return $cache[$letterId] = ['user_id' => (int) $r['sender_user_id'], 'label' => trim((string) $r['unit_title']) !== '' ? trim((string) $r['unit_title']) : 'دبیرخانه'];
}

/**
 * آیا کاربر Permission موردنظر رو داره؟ ادمین کلِ سایت (is_super_admin) همیشه همه‌چیز رو داره؛
 * سایر ادمین‌ها و کاربران باید دقیقاً بر اساس Permissionهای ثبت‌شده برای خودشان کنترل شوند.
 */
function automation_user_has_permission(PDO $pdo, array $user, string $code): bool
{
    if (function_exists('is_super_admin') && is_super_admin($user)) {
        return true;
    }
    // سازگاری با «نقش‌ها و دسترسی‌ها»: مجوزهای اتوماسیونی که در صفحه‌ی نقش‌ها تعریف شده‌اند
    // نقشِ «سقف» دارند؛ یعنی حتی اگر سطحِ اجازه‌ی اتوماسیونِ کاربر آن را بدهد، تا وقتی نقشِ
    // کاربر اجازه ندهد فعال نمی‌شود (مثلاً حذف نامه و بخش‌های مدیریتی برای واحدهای A/B/C).
    if (function_exists('perm_automation_keys') && function_exists('user_can')
        && in_array($code, perm_automation_keys(), true) && !user_can($code, $user)) {
        return false;
    }
    static $cache = [];
    $uid = (int) $user['id'];
    if (!isset($cache[$uid])) {
        $stmt = $pdo->prepare('SELECT permission_code FROM automation_user_permissions WHERE user_id = ?');
        $stmt->execute([$uid]);
        $cache[$uid] = array_column($stmt->fetchAll(), 'permission_code');
    }
    return in_array($code, $cache[$uid], true);
}

function automation_require_permission(PDO $pdo, array $user, string $code): void
{
    if (!automation_user_has_permission($pdo, $user, $code)) {
        if (function_exists('perm_deny')) {
            perm_deny('شما اجازه‌ی این بخش از اتوماسیون را ندارید.', $user);
        }
        http_response_code(403);
        die('دسترسی غیرمجاز. Permission لازم برای این عملیات را ندارید.');
    }
}

// -----------------------------------------------------------------
// ساختار سازمانی
// -----------------------------------------------------------------

/** جایگاهِ سازمانیِ یک کاربر (سمت + واحد + رده) — یا null اگه هنوز جایگاهی تعریف نشده */
function automation_user_position(PDO $pdo, int $userId): ?array
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }
    $mu = automation_multi_unit_ready($pdo);
    // عضوِ چند واحد: واحدِ «اصلی» (برای مسیرِ تایید و نمایش)
    $stmt = $pdo->prepare('SELECT p.*, u.title AS unit_title, u.parent_id AS unit_parent_id, l.id AS level_id, l.title AS level_title, l.weight AS level_weight
        FROM automation_user_positions p
        JOIN automation_org_units u ON u.id = p.unit_id
        LEFT JOIN automation_org_levels l ON l.id = u.level_id
        WHERE p.user_id = ? ORDER BY ' . ($mu ? 'p.is_primary DESC, ' : '') . 'p.id ASC LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: null;
    $cache[$userId] = $row;
    return $row;
}

/** همه‌ی جایگاه‌های سازمانیِ یک کاربر (عضوِ چند واحد): اصلی اول */
function automation_user_positions_all(PDO $pdo, int $userId): array
{
    $mu = automation_multi_unit_ready($pdo);
    $stmt = $pdo->prepare('SELECT p.*, u.title AS unit_title, l.title AS level_title
        FROM automation_user_positions p JOIN automation_org_units u ON u.id = p.unit_id
        LEFT JOIN automation_org_levels l ON l.id = u.level_id
        WHERE p.user_id = ? ORDER BY ' . ($mu ? 'p.is_primary DESC, ' : '') . 'p.id ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** جایگاهی که نامه «از سوی» آن ارسال می‌شود: جایگاهِ انتخاب‌شده (اگر متعلق به همین کاربر باشد)، وگرنه جایگاهِ اصلی */
function automation_sender_position(PDO $pdo, int $userId, ?int $positionId = null): ?array
{
    if ($positionId) {
        foreach (automation_user_positions_all($pdo, $userId) as $p) {
            if ((int) $p['id'] === $positionId) return $p;
        }
    }
    return automation_user_position($pdo, $userId);
}

/** رتبه‌یِ عددیِ کاربر (کمتر = بالاتر). کاربرِ بدونِ جایگاه، پایین‌ترین رتبه‌ی ممکن (999999) گرفته می‌شه. */
function automation_user_weight(PDO $pdo, int $userId): int
{
    $pos = automation_user_position($pdo, $userId);
    return $pos && $pos['level_weight'] !== null ? (int) $pos['level_weight'] : 999999;
}

/**
 * مدیرِ مستقیمِ یک کاربر: اول direct_manager_user_id (اگه دستی تنظیم شده)، وگرنه مدیرِ همون
 * واحد (is_unit_manager=1، غیر از خودش)، وگرنه با بالا رفتن از زنجیره‌ی واحدهایِ والد، اولین
 * واحدی که مدیر داره. اگه به هیچ‌کجا نرسه، null (یعنی به بالاترین ردهٔ سازمانی رسیده‌ایم).
 */
function automation_manager_of(PDO $pdo, int $userId): ?int
{
    $pos = automation_user_position($pdo, $userId);
    if (!$pos) {
        return null;
    }
    if (!empty($pos['direct_manager_user_id']) && (int) $pos['direct_manager_user_id'] !== $userId) {
        return (int) $pos['direct_manager_user_id'];
    }
    $unitId = (int) $pos['unit_id'];
    $guard = 0;
    while ($unitId && $guard < 30) {
        $guard++;
        $stmt = $pdo->prepare('SELECT p.user_id FROM automation_user_positions p WHERE p.unit_id = ? AND p.is_unit_manager = 1 AND p.user_id <> ? LIMIT 1');
        $stmt->execute([$unitId, $userId]);
        $managerId = $stmt->fetchColumn();
        if ($managerId) {
            return (int) $managerId;
        }
        $stmt2 = $pdo->prepare('SELECT parent_id FROM automation_org_units WHERE id = ? LIMIT 1');
        $stmt2->execute([$unitId]);
        $parentId = $stmt2->fetchColumn();
        $unitId = $parentId ? (int) $parentId : null;
    }
    return null;
}

/**
 * مسیرِ تاییدِ لازم بینِ فرستنده و گیرنده. طبقِ منطقِ اصلیِ سیستم:
 *  - اگه رتبه‌یِ فرستنده بالاتر یا مساوی گیرنده باشه: ارسال مستقیم (آرایه‌یِ خالی).
 *  - وگرنه: از مدیرِ مستقیمِ فرستنده شروع می‌کنیم و پله‌پله بالا می‌ریم تا به خودِ گیرنده
 *    برسیم؛ هر نفرِ بینِ راه یک مرحله‌یِ تاییدِ اجباریه. هیچ رده‌ای دور زده نمی‌شه.
 * خروجی: آرایه‌ای از user_id هایِ تاییدکننده، به ترتیب.
 */
function automation_build_approval_chain(PDO $pdo, int $senderId, int $recipientId): array
{
    // تأیید مافوق برای ارسال نامه به‌طور کامل غیرفعال شده است.
    // این تابع برای سازگاری با نسخه‌های قدیمی نگه داشته شده، اما همیشه زنجیره خالی برمی‌گرداند.
    return [];
}

/** درختِ کاملِ واحدهایِ سازمانی (تودرتو) برایِ نمایشِ Tree View در پنلِ ادمین */
function automation_unit_tree(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT u.*, l.title AS level_title, l.weight AS level_weight, l.color AS level_color
        FROM automation_org_units u LEFT JOIN automation_org_levels l ON l.id = u.level_id
        ORDER BY u.sort_order ASC, u.id ASC');
    $rows = $stmt->fetchAll();
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[$r['parent_id'] ?? 0][] = $r;
    }
    $build = function ($parentId) use (&$build, $byParent) {
        $out = [];
        foreach (($byParent[$parentId] ?? []) as $node) {
            $node['children'] = $build((int) $node['id']);
            $out[] = $node;
        }
        return $out;
    };
    return $build(0);
}

/** اعضایِ یک واحد (کاربرانی که جایگاهشون رویِ این واحده)، به‌همراهِ سمت */
function automation_unit_members(PDO $pdo, int $unitId): array
{
    $stmt = $pdo->prepare('SELECT p.*, u.full_name, u.mobile FROM automation_user_positions p
        JOIN users u ON u.id = p.user_id WHERE p.unit_id = ? ORDER BY p.is_unit_manager DESC, u.full_name ASC');
    $stmt->execute([$unitId]);
    return $stmt->fetchAll();
}

/** همه‌یِ واحدهایِ زیرمجموعه‌یِ یک واحد (بازگشتی)، شاملِ خودِ واحد */
function automation_unit_descendant_ids(PDO $pdo, int $unitId): array
{
    $ids = [$unitId];
    $queue = [$unitId];
    while ($queue) {
        $placeholders = implode(',', array_fill(0, count($queue), '?'));
        $stmt = $pdo->prepare("SELECT id FROM automation_org_units WHERE parent_id IN ($placeholders)");
        $stmt->execute($queue);
        $children = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        $queue = array_diff($children, $ids);
        $ids = array_merge($ids, $queue);
    }
    return $ids;
}

/**
 * یک انتخابِ گیرنده (letter_recipients یک ردیف با recipient_kind='unit') رو به فهرستِ
 * user_id هایِ واقعی باز می‌کنه، طبقِ distribution_scope.
 */
function automation_resolve_unit_recipients(PDO $pdo, int $unitId, ?string $scope, array $selectedUserIds = []): array
{
    $scope = $scope ?: 'all_members';
    if ($scope === 'unit_manager_only') {
        $stmt = $pdo->prepare('SELECT user_id FROM automation_user_positions WHERE unit_id = ? AND is_unit_manager = 1');
        $stmt->execute([$unitId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }
    if ($scope === 'manager_and_deputy') {
        $stmt = $pdo->prepare('SELECT user_id FROM automation_user_positions WHERE unit_id = ? AND (is_unit_manager = 1 OR is_unit_supervisor = 1)');
        $stmt->execute([$unitId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }
    if ($scope === 'supervisors') {
        $stmt = $pdo->prepare('SELECT user_id FROM automation_user_positions WHERE unit_id = ? AND is_unit_supervisor = 1');
        $stmt->execute([$unitId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }
    if ($scope === 'sub_units') {
        $descendantIds = automation_unit_descendant_ids($pdo, $unitId);
        $placeholders = implode(',', array_fill(0, count($descendantIds), '?'));
        $stmt = $pdo->prepare("SELECT user_id FROM automation_user_positions WHERE unit_id IN ($placeholders)");
        $stmt->execute($descendantIds);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }
    if ($scope === 'selected_members') {
        return array_map('intval', $selectedUserIds);
    }
    // all_members (پیش‌فرض)
    $stmt = $pdo->prepare('SELECT user_id FROM automation_user_positions WHERE unit_id = ?');
    $stmt->execute([$unitId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
}

/**
 * نامه به «رده‌ی سازمانی»: فقط اعضای مستقیمِ واحدهایی که همین رده را دارند (نه واحدهای زیرمجموعه‌شان).
 */
function automation_resolve_level_recipients(PDO $pdo, int $levelId): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT p.user_id FROM automation_user_positions p JOIN automation_org_units u ON u.id = p.unit_id
        JOIN users us ON us.id = p.user_id WHERE u.level_id = ? AND u.is_active = 1 AND us.is_active = 1');
    $stmt->execute([$levelId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
}

// -----------------------------------------------------------------
// جستجویِ گیرنده (برای Search حرفه‌ایِ فرم نامه/ارجاع/رونوشت)
// -----------------------------------------------------------------

/**
 * خروجی: آرایه‌ای از ['kind'=>'user'|'unit'|'level', 'id'=>.., 'label'=>.., 'sub'=>..]
 * ترتیب: اول واحدها و رده‌ها، بعد افراد. افراد فقط با نام/موبایل/سمت پیدا می‌شوند (نه با نامِ واحدشان)،
 * تا وقتی کسی نامِ واحد را می‌زند، خودِ واحد بیاید نه تک‌تکِ اعضایش.
 * کاربرِ «دبیرخانه ریاست» به‌صورتِ خودِ واحدِ دبیرخانه نمایش داده می‌شود و عضویتِ اعضای آن واحد فاش نمی‌شود.
 */
function automation_search_recipients(PDO $pdo, string $q, int $limit = 15): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $like = '%' . $q . '%';
    $out = [];
    $seenUnits = [];

    $stmt2 = $pdo->prepare('SELECT id, title FROM automation_org_units WHERE title LIKE ? AND is_active = 1 ORDER BY title ASC LIMIT ' . (int) $limit);
    $stmt2->execute([$like]);
    foreach ($stmt2->fetchAll() as $r) {
        $isSec = automation_is_secretariat_unit($pdo, (int) $r['id']);
        $seenUnits[(int) $r['id']] = true;
        $out[] = [
            'kind'  => 'unit',
            'id'    => (int) $r['id'],
            'label' => $r['title'],
            'sub'   => $isSec ? 'دبیرخانه — نامه حتماً خوانده می‌شود' : 'واحد سازمانی — فقط اعضای همین واحد',
        ];
    }

    // رده‌های سازمانی (مثلاً «معاونت و سرپرستی»): به اعضای همه‌ی واحدهای این رده، بدونِ زیرمجموعه‌ها
    try {
        $stmt3 = $pdo->prepare('SELECT l.id, l.title, (SELECT COUNT(*) FROM automation_org_units u WHERE u.level_id = l.id AND u.is_active = 1) AS units
            FROM automation_org_levels l WHERE l.title LIKE ? ORDER BY l.weight DESC, l.title ASC LIMIT ' . (int) $limit);
        $stmt3->execute([$like]);
        foreach ($stmt3->fetchAll() as $r) {
            $out[] = ['kind' => 'level', 'id' => (int) $r['id'], 'label' => $r['title'],
                'sub' => 'رده‌ی سازمانی — اعضای ' . (int) $r['units'] . ' واحدِ این رده (بدونِ زیرمجموعه‌ها)'];
        }
    } catch (Throwable $e) {}

    $stmt = $pdo->prepare('SELECT u.id, u.full_name, u.mobile, p.position_title, un.title AS unit_title
        FROM users u
        LEFT JOIN automation_user_positions p ON p.user_id = u.id' . (automation_multi_unit_ready($pdo) ? ' AND p.is_primary = 1' : '') . '
        LEFT JOIN automation_org_units un ON un.id = p.unit_id
        WHERE u.is_active = 1 AND (u.full_name LIKE ? OR u.mobile LIKE ? OR p.position_title LIKE ?)
        ORDER BY u.full_name ASC LIMIT ' . (int) $limit);
    $stmt->execute([$like, $like, $like]);
    $secUsers = automation_secretariat_user_ids($pdo);
    $hidden = automation_hidden_member_map($pdo);
    foreach ($stmt->fetchAll() as $r) {
        $uid = (int) $r['id'];
        // کاربرِ «دبیرخانه ریاست» ← خودِ واحدِ دبیرخانه (هدف همیشه واحد است)
        if (isset($secUsers[$uid]) && ($secUnit = automation_secretariat_unit_id($pdo))) {
            if (isset($seenUnits[$secUnit])) continue;
            $seenUnits[$secUnit] = true;
            $out[] = ['kind' => 'unit', 'id' => $secUnit, 'label' => automation_secretariat_units($pdo)[$secUnit], 'sub' => 'دبیرخانه — نامه حتماً خوانده می‌شود'];
            continue;
        }
        $out[] = [
            'kind'  => 'user',
            'id'    => $uid,
            'label' => $r['full_name'],
            'mobile' => (string) ($r['mobile'] ?? ''),
            // عضوِ دبیرخانه: واحد/سمتش نمایش داده نمی‌شود
            'sub'   => isset($hidden[$uid]) ? '' : trim(($r['position_title'] ?? '') . ($r['unit_title'] ? ' — ' . $r['unit_title'] : '')),
        ];
    }

    return $out;
}

// -----------------------------------------------------------------
// دبیرخانه ریاست: هدفِ نامه همیشه «واحد» است و هویتِ نیرویی که نامه را برمی‌دارد پنهان می‌ماند
// -----------------------------------------------------------------

/** نرمال‌سازی برای تشخیصِ نامِ «دبیرخانه ریاست» (ی/ک عربی، نیم‌فاصله، فاصله‌ها) */
function automation_norm_title(string $s): string
{
    $s = str_replace(['ي', 'ى', 'ك', "\u{200C}", "\u{200F}", "\u{200E}"], ['ی', 'ی', 'ک', '', '', ''], $s);
    return (string) preg_replace('/\s+/u', '', $s);
}

function automation_is_secretariat_title(string $title): bool
{
    $t = automation_norm_title($title);
    return mb_strpos($t, 'دبیرخانه') !== false && mb_strpos($t, 'ریاست') !== false;
}

/** واحدهای «دبیرخانه ریاست»: [unit_id => title] */
function automation_secretariat_units(PDO $pdo): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach ($pdo->query("SELECT id, title FROM automation_org_units WHERE is_active = 1 AND title LIKE '%دبیرخانه%' ORDER BY id") as $r) {
            if (automation_is_secretariat_title((string) $r['title'])) $c[(int) $r['id']] = (string) $r['title'];
        }
    } catch (Throwable $e) {}
    return $c;
}

function automation_secretariat_unit_id(PDO $pdo): int
{
    $u = automation_secretariat_units($pdo);
    return $u ? (int) array_key_first($u) : 0;
}

function automation_is_secretariat_unit(PDO $pdo, int $unitId): bool
{
    return isset(automation_secretariat_units($pdo)[$unitId]);
}

/** کاربر(های) با نامِ «دبیرخانه ریاست»: [user_id => true] */
function automation_secretariat_user_ids(PDO $pdo): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach ($pdo->query("SELECT id, full_name FROM users WHERE full_name LIKE '%دبیرخانه%'") as $r) {
            if (automation_is_secretariat_title((string) $r['full_name'])) $c[(int) $r['id']] = true;
        }
    } catch (Throwable $e) {}
    return $c;
}

/** افرادی که نامشان باید پنهان شود (اعضای واحدِ دبیرخانه + کاربرِ دبیرخانه): [user_id => عنوانِ نمایشی] */
function automation_hidden_member_map(PDO $pdo): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    $units = automation_secretariat_units($pdo);
    $default = $units ? reset($units) : 'دبیرخانه ریاست';
    if ($units) {
        try {
            $in = implode(',', array_map('intval', array_keys($units)));
            foreach ($pdo->query("SELECT user_id, unit_id FROM automation_user_positions WHERE unit_id IN ($in)") as $r) {
                $c[(int) $r['user_id']] = $units[(int) $r['unit_id']] ?? $default;
            }
        } catch (Throwable $e) {}
    }
    foreach (automation_secretariat_user_ids($pdo) as $uid => $_) $c[$uid] = $default;
    return $c;
}

/** آیا این بیننده اجازه‌ی دیدنِ نامِ واقعیِ اعضای دبیرخانه را دارد؟ (خودِ اعضا و مدیرِ ارشد) */
function automation_can_see_hidden_names(PDO $pdo, array $viewer): bool
{
    if (function_exists('is_super_admin') && is_super_admin($viewer)) return true;
    return isset(automation_hidden_member_map($pdo)[(int) ($viewer['id'] ?? 0)]);
}

/** نامِ نمایشیِ یک کاربر برای بیننده: عضوِ دبیرخانه ← نامِ واحدِ دبیرخانه */
function automation_display_name(PDO $pdo, ?int $userId, ?string $name, array $viewer, ?int $letterId = null): string
{
    $name = (string) $name;
    if (!$userId) return $name;
    // نامه‌ای که فرستنده‌اش نامِ خود را پنهان کرده ← نامِ واحدِ فرستنده
    if ($letterId !== null && ($hs = automation_letter_hidden_sender($pdo, $letterId)) && $hs['user_id'] === $userId) return $hs['label'];
    // کاربرِ «دبیرخانه ریاست» همیشه با همین نام است؛ نامِ اعضای واحد فقط در نامه‌های مربوط به دبیرخانه پنهان می‌شود
    if ($letterId !== null && !isset(automation_secretariat_user_ids($pdo)[$userId]) && !automation_letter_involves_secretariat($pdo, $letterId)) return $name;
    $map = automation_hidden_member_map($pdo);
    if (isset($map[$userId]) && !automation_can_see_hidden_names($pdo, $viewer)) return $map[$userId];
    return $name;
}

/**
 * آیا این نامه (یا نامه‌ی ریشه/والدش، مثلاً وقتی پاسخِ دبیرخانه است) با واحدِ دبیرخانه سروکار دارد؟
 * پنهان‌سازیِ نام فقط در همین نامه‌ها انجام می‌شود (نامه‌های شخصیِ دیگرِ همان نیرو دست نمی‌خورد).
 */
function automation_letter_involves_secretariat(PDO $pdo, int $letterId): bool
{
    static $c = [];
    if (isset($c[$letterId])) return $c[$letterId];
    $units = automation_secretariat_units($pdo);
    if (!$units) return $c[$letterId] = false;
    $in = implode(',', array_map('intval', array_keys($units)));
    try {
        $st = $pdo->prepare('SELECT id, parent_letter_id, root_letter_id FROM letters WHERE id = ?');
        $st->execute([$letterId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) return $c[$letterId] = false;
        $ids = array_unique(array_filter([(int) $l['id'], (int) $l['parent_letter_id'], (int) $l['root_letter_id']]));
        $lin = implode(',', $ids);
        $q = $pdo->query("SELECT 1 FROM letter_recipients WHERE letter_id IN ($lin) AND recipient_kind = 'unit' AND unit_id IN ($in)
            UNION SELECT 1 FROM letter_referrals WHERE letter_id IN ($lin) AND referred_to_unit_id IN ($in) LIMIT 1");
        return $c[$letterId] = (bool) $q->fetchColumn();
    } catch (Throwable $e) {
        return $c[$letterId] = false;
    }
}

/** گیرنده‌ی خام (فرد/واحد) هدفش دبیرخانه است؟ */
function automation_is_secretariat_target(PDO $pdo, string $kind, int $id): bool
{
    if ($kind === 'unit') return automation_is_secretariat_unit($pdo, $id);
    if ($kind === 'user') return isset(automation_secretariat_user_ids($pdo)[$id]);
    return false;
}

/** نامه به کاربرِ «دبیرخانه ریاست» ← به خودِ واحدِ دبیرخانه تبدیل می‌شود */
function automation_normalize_recipients(PDO $pdo, array $recipients): array
{
    $secUnit = automation_secretariat_unit_id($pdo);
    if (!$secUnit) return $recipients;
    $out = [];
    $seen = [];
    foreach ($recipients as $r) {
        if (($r['kind'] ?? '') === 'user' && automation_is_secretariat_target($pdo, 'user', (int) ($r['id'] ?? 0))) {
            $r['kind'] = 'unit';
            $r['id'] = $secUnit;
            $r['scope'] = 'all_members';
            $r['selected'] = [];
        }
        $key = ($r['role'] ?? 'to') . '|' . ($r['kind'] ?? '') . '|' . (int) ($r['id'] ?? 0);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $r;
    }
    return $out;
}

/** آیا این نامه (به‌عنوانِ گیرنده یا رونوشت) به دبیرخانه رفته است؟ */
function automation_recipients_include_secretariat(PDO $pdo, array $recipients): bool
{
    foreach ($recipients as $r) {
        if (automation_is_secretariat_target($pdo, (string) ($r['kind'] ?? ''), (int) ($r['id'] ?? 0))) return true;
    }
    return false;
}

/**
 * فهرستِ نمایشیِ گیرندگان/رونوشت‌ها بر اساسِ «انتخابِ خام» (نه اعضای بازشده):
 * فرد ← نامش و وضعیتش؛ واحد ← «واحد: عنوان» (بدونِ نامِ اعضا)؛ رده ← «رده: عنوان».
 * کسانی که بعداً (مثلاً با ارجاع) اضافه شده‌اند و در انتخابِ خام نیستند، جداگانه با نام (یا نامِ دبیرخانه) می‌آیند.
 * @return array{to: array<int,array{label:string,status:?string,kind:string}>, cc: array<int,array{label:string,status:?string,kind:string}>}
 */
function automation_letter_recipient_display(PDO $pdo, int $letterId, array $viewer): array
{
    $out = ['to' => [], 'cc' => []];
    $covered = [];
    $lru = $pdo->prepare('SELECT lru.user_id, lru.role, lru.status, lru.letter_recipient_id, u.full_name FROM letter_recipient_users lru JOIN users u ON u.id = lru.user_id WHERE lru.letter_id = ?');
    $lru->execute([$letterId]);
    $byRec = [];
    $all = $lru->fetchAll() ?: [];
    foreach ($all as $r) $byRec[(int) $r['letter_recipient_id']][] = $r;
    $mu = automation_multi_unit_ready($pdo);
    $st = $pdo->prepare('SELECT lr.*, u.full_name, un.title AS unit_title' . ($mu ? ', lv.title AS level_title' : ', NULL AS level_title') . '
        FROM letter_recipients lr LEFT JOIN users u ON u.id = lr.user_id LEFT JOIN automation_org_units un ON un.id = lr.unit_id'
        . ($mu ? ' LEFT JOIN automation_org_levels lv ON lv.id = lr.level_id' : '') . ' WHERE lr.letter_id = ? ORDER BY lr.id');
    $st->execute([$letterId]);
    $rank = ['ارسال شده' => 1, 'دریافت شده' => 2, 'خوانده شده' => 3, 'در حال اقدام' => 4, 'ارجاع شده' => 5, 'پاسخ داده شده' => 6, 'بسته شده' => 7];
    foreach ($st->fetchAll() ?: [] as $r) {
        $role = $r['role'] === 'cc' ? 'cc' : 'to';
        $members = $byRec[(int) $r['id']] ?? [];
        foreach ($members as $m) $covered[(int) $m['user_id']] = true;
        $status = null;
        foreach ($members as $m) if ($status === null || ($rank[$m['status']] ?? 0) > ($rank[$status] ?? 0)) $status = $m['status'];
        if ($r['recipient_kind'] === 'user') {
            $label = automation_display_name($pdo, (int) $r['user_id'], (string) $r['full_name'], $viewer, $letterId);
            $kind = 'user';
        } elseif ($r['recipient_kind'] === 'unit') {
            $label = 'واحد: ' . (string) $r['unit_title'];
            $kind = 'unit';
        } else {
            $label = 'رده: ' . (string) $r['level_title'];
            $kind = 'level';
        }
        $out[$role][$label] = ['label' => $label, 'status' => $status, 'kind' => $kind];
    }
    foreach ($all as $m) {
        if (isset($covered[(int) $m['user_id']])) continue;
        $role = $m['role'] === 'cc' ? 'cc' : 'to';
        $label = automation_display_name($pdo, (int) $m['user_id'], (string) $m['full_name'], $viewer, $letterId);
        if (!isset($out[$role][$label])) $out[$role][$label] = ['label' => $label, 'status' => $m['status'], 'kind' => 'user'];
    }
    return ['to' => array_values($out['to']), 'cc' => array_values($out['cc'])];
}

// -----------------------------------------------------------------
// تاریخچه / Audit
// -----------------------------------------------------------------

function automation_log_history(PDO $pdo, int $letterId, ?int $userId, string $eventType, string $description = ''): void
{
    $pdo->prepare('INSERT INTO letter_history (letter_id, user_id, event_type, description) VALUES (?,?,?,?)')
        ->execute([$letterId, $userId, $eventType, $description !== '' ? $description : null]);
}

function automation_audit(PDO $pdo, ?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $description = null): void
{
    try {
        $pdo->prepare('INSERT INTO automation_audit_logs (user_id, action, entity_type, entity_id, description, ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$userId, $action, $entityType, $entityId, $description, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // Audit Log هرگز نباید مانعِ عملیاتِ اصلی بشه
    }
}

// -----------------------------------------------------------------
// Badge (تعدادِ نامه‌هایِ خوانده‌نشده)
// -----------------------------------------------------------------

function automation_unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM letter_recipient_users WHERE user_id = ? AND status NOT IN ('خوانده شده','بسته شده') AND status <> 'در انتظار تایید'");
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

// -----------------------------------------------------------------
// ارسالِ نامه (Orchestration اصلی): گیرندگانِ خام رو به کاربرِ واقعی باز می‌کنه،
// برایِ هرکدوم مسیرِ تحویل/تاییدِ لازم رو می‌سازه، و Snapshot ثبت می‌کنه.
// -----------------------------------------------------------------

/**
 * @param array $recipients هر آیتم: ['role'=>'to'|'cc','kind'=>'user'|'unit','id'=>int,'scope'=>?string,'selected'=>int[]]
 */
function automation_send_letter(PDO $pdo, array $letter, array $recipients, int $senderId): int
{
    // نامه به کاربرِ «دبیرخانه ریاست» ← به خودِ واحدِ دبیرخانه
    $recipients = automation_normalize_recipients($pdo, $recipients);
    $pdo->beginTransaction();
    try {
        // نامه «از سوی» کدام واحد؟ (کسی که عضوِ چند واحد است انتخاب می‌کند؛ پیش‌فرض واحدِ اصلی)
        $senderPos = automation_sender_position($pdo, $senderId, !empty($letter['sender_position_id']) ? (int) $letter['sender_position_id'] : null);
        $ins = $pdo->prepare('INSERT INTO letters
            (letter_number, subject, body, letter_type, priority, confidentiality, status, sender_user_id, sender_unit_id, sender_position_title, deadline_at, description, parent_letter_id, root_letter_id)
            VALUES (?,?,?,?,?,?,\'ارسال شده\',?,?,?,?,?,?,?)');
        $ins->execute([
            $letter['letter_number'] ?? null,
            $letter['subject'],
            $letter['body'] ?? null,
            $letter['letter_type'] ?? null,
            $letter['priority'] ?? 'عادی',
            $letter['confidentiality'] ?? 'عادی',
            $senderId,
            $senderPos['unit_id'] ?? null,
            $senderPos['position_title'] ?? null,
            $letter['deadline_at'] ?? null,
            $letter['description'] ?? null,
            $letter['parent_letter_id'] ?? null,
            $letter['root_letter_id'] ?? ($letter['parent_letter_id'] ?? null),
        ]);
        $letterId = (int) $pdo->lastInsertId();
        if (automation_hide_sender_ready($pdo)) {
            // پنهان‌بودنِ نامِ فرستنده: انتخابِ ارسال‌کننده (اگر داده شده)، وگرنه پیش‌فرضِ واحد
            $hide = array_key_exists('hide_sender_name', $letter) && $letter['hide_sender_name'] !== null
                ? (bool) $letter['hide_sender_name'] : automation_unit_hides_sender_by_default($senderPos['unit_title'] ?? null);
            $pdo->prepare('UPDATE letters SET hide_sender_name = ? WHERE id = ?')->execute([$hide ? 1 : 0, $letterId]);
        }
        if (empty($letter['parent_letter_id'])) {
            $pdo->prepare('UPDATE letters SET root_letter_id = ? WHERE id = ?')->execute([$letterId, $letterId]);
        }
        $pdo->prepare('UPDATE letters SET sent_at = NOW() WHERE id = ?')->execute([$letterId]);

        automation_log_history($pdo, $letterId, $senderId, 'created', 'نامه ایجاد و ارسال شد.');

        // -------- بازکردنِ گیرندگانِ خام به فهرستِ نهاییِ کاربران --------
        $finalUsers = []; // user_id => role ('to' غالب بر 'cc')
        foreach ($recipients as $rec) {
            $role = ($rec['role'] ?? 'to') === 'cc' ? 'cc' : 'to';
            $recId = null;
            if (($rec['kind'] ?? '') === 'user' && !empty($rec['id'])) {
                $recStmt = $pdo->prepare('INSERT INTO letter_recipients (letter_id, role, recipient_kind, user_id) VALUES (?,?,\'user\',?)');
                $recStmt->execute([$letterId, $role, (int) $rec['id']]);
                $recId = (int) $pdo->lastInsertId();
                $uid = (int) $rec['id'];
                if (!isset($finalUsers[$uid]) || $role === 'to') {
                    $finalUsers[$uid] = ['role' => $role, 'recipient_id' => $recId];
                }
            } elseif (($rec['kind'] ?? '') === 'unit' && !empty($rec['id'])) {
                $scope = $rec['scope'] ?? 'all_members';
                $recStmt = $pdo->prepare('INSERT INTO letter_recipients (letter_id, role, recipient_kind, unit_id, distribution_scope) VALUES (?,?,\'unit\',?,?)');
                $recStmt->execute([$letterId, $role, (int) $rec['id'], $scope]);
                $recId = (int) $pdo->lastInsertId();
                if ($scope === 'selected_members' && !empty($rec['selected'])) {
                    $selStmt = $pdo->prepare('INSERT INTO letter_recipient_selected_users (letter_recipient_id, user_id) VALUES (?,?)');
                    foreach ($rec['selected'] as $sid) {
                        $selStmt->execute([$recId, (int) $sid]);
                    }
                }
                $memberIds = automation_resolve_unit_recipients($pdo, (int) $rec['id'], $scope, $rec['selected'] ?? []);
                foreach ($memberIds as $uid) {
                    if (!isset($finalUsers[$uid]) || $role === 'to') {
                        $finalUsers[$uid] = ['role' => $role, 'recipient_id' => $recId];
                    }
                }
            } elseif (($rec['kind'] ?? '') === 'level' && !empty($rec['id']) && automation_multi_unit_ready($pdo)) {
                $recStmt = $pdo->prepare('INSERT INTO letter_recipients (letter_id, role, recipient_kind, level_id) VALUES (?,?,\'level\',?)');
                $recStmt->execute([$letterId, $role, (int) $rec['id']]);
                $recId = (int) $pdo->lastInsertId();
                foreach (automation_resolve_level_recipients($pdo, (int) $rec['id']) as $uid) {
                    if (!isset($finalUsers[$uid]) || $role === 'to') {
                        $finalUsers[$uid] = ['role' => $role, 'recipient_id' => $recId];
                    }
                }
            }
        }

        $lruStmt = $pdo->prepare('INSERT INTO letter_recipient_users
            (letter_id, letter_recipient_id, user_id, role, snapshot_position_title, snapshot_unit_title, status)
            VALUES (?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE role = VALUES(role)');
        $pathStmt = $pdo->prepare('INSERT INTO letter_delivery_paths (letter_id, recipient_user_id, requires_approval, status) VALUES (?,?,?,?)');
        $stepStmt = $pdo->prepare('INSERT INTO letter_approval_steps (delivery_path_id, step_order, approver_user_id, snapshot_position_title, snapshot_unit_title, status) VALUES (?,?,?,?,?,\'در انتظار تایید\')');

        foreach ($finalUsers as $uid => $info) {
            if ($uid === $senderId) {
                continue; // خودِ فرستنده نیازی به گیرندهٔ جداگانه نداره
            }
            $pos = automation_user_position($pdo, $uid);

            // ارسال مستقیم: هیچ تأیید مافوق، زنجیره تأیید یا وضعیت «در انتظار تایید» ایجاد نمی‌شود.
            $requiresApproval = 0;
            $initialStatus = 'ارسال شده';

            $lruStmt->execute([
                $letterId, $info['recipient_id'], $uid, $info['role'],
                $pos['position_title'] ?? null, $pos['unit_title'] ?? null,
                $initialStatus,
            ]);

            $pathStmt->execute([$letterId, $uid, 0, 'تحویل مستقیم']);
        }

        $pdo->commit();
        automation_audit($pdo, $senderId, 'letter_send', 'letter', $letterId, 'نامه «' . $letter['subject'] . '» ارسال شد.');
        return $letterId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** رسیدگی به یک مرحله‌یِ تایید (تایید/رد/برگشت) */
function automation_decide_approval_step(PDO $pdo, int $stepId, int $approverId, string $decision, string $note = ''): bool
{
    $stmt = $pdo->prepare('SELECT s.*, p.letter_id, p.recipient_user_id FROM letter_approval_steps s
        JOIN letter_delivery_paths p ON p.id = s.delivery_path_id WHERE s.id = ? LIMIT 1');
    $stmt->execute([$stepId]);
    $step = $stmt->fetch();
    if (!$step || (int) $step['approver_user_id'] !== $approverId || $step['status'] !== 'در انتظار تایید') {
        return false;
    }
    $statusMap = ['approve' => 'تایید شد', 'reject' => 'رد شد', 'return' => 'برگشت برای اصلاح'];
    if (!isset($statusMap[$decision])) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE letter_approval_steps SET status = ?, note = ?, decided_at = NOW() WHERE id = ?')
            ->execute([$statusMap[$decision], $note !== '' ? $note : null, $stepId]);

        $letterId = (int) $step['letter_id'];
        $pathId = (int) $step['delivery_path_id'];

        if ($decision === 'reject') {
            $pdo->prepare('UPDATE letter_delivery_paths SET status = \'رد شد\' WHERE id = ?')->execute([$pathId]);
            automation_log_history($pdo, $letterId, $approverId, 'rejected', 'نامه رد شد.' . ($note !== '' ? ' دلیل: ' . $note : ''));
        } elseif ($decision === 'return') {
            $pdo->prepare('UPDATE letter_delivery_paths SET status = \'رد شد\' WHERE id = ?')->execute([$pathId]);
            $pdo->prepare("UPDATE letters SET status = 'ثبت شده' WHERE id = ?")->execute([$letterId]);
            automation_log_history($pdo, $letterId, $approverId, 'returned', 'نامه برایِ اصلاح برگشت داده شد.' . ($note !== '' ? ' توضیح: ' . $note : ''));
        } else {
            automation_log_history($pdo, $letterId, $approverId, 'approved', 'مرحله‌ای از تایید انجام شد.' . ($note !== '' ? ' توضیح: ' . $note : ''));
            // آیا مرحله‌یِ بعدی هست؟
            $nextStmt = $pdo->prepare('SELECT id FROM letter_approval_steps WHERE delivery_path_id = ? AND status = \'در انتظار تایید\' ORDER BY step_order ASC LIMIT 1');
            $nextStmt->execute([$pathId]);
            $hasNext = $nextStmt->fetchColumn();
            if (!$hasNext) {
                $pdo->prepare('UPDATE letter_delivery_paths SET status = \'تایید شد\' WHERE id = ?')->execute([$pathId]);
                $pdo->prepare("UPDATE letter_recipient_users SET status = 'ارسال شده' WHERE letter_id = ? AND user_id = ?")
                    ->execute([$letterId, (int) $step['recipient_user_id']]);
                automation_log_history($pdo, $letterId, $approverId, 'delivered', 'همه‌ی مراحلِ تایید انجام شد و نامه تحویلِ گیرنده‌ی نهایی شد.');
            }
        }
        $pdo->commit();
        automation_audit($pdo, $approverId, 'approval_' . $decision, 'letter', $letterId);
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function automation_mark_read(PDO $pdo, int $letterId, int $userId): void
{
    $stmt = $pdo->prepare("UPDATE letter_recipient_users SET status = 'خوانده شده', read_at = NOW()
        WHERE letter_id = ? AND user_id = ? AND status NOT IN ('خوانده شده','بسته شده') AND status <> 'در انتظار تایید'");
    $stmt->execute([$letterId, $userId]);
    if ($stmt->rowCount() > 0) {
        automation_log_history($pdo, $letterId, $userId, 'read', 'نامه مطالعه شد.');
    }
    $pdo->prepare("UPDATE letter_declarations SET status = 'مشاهده شد', seen_at = NOW() WHERE letter_id = ? AND user_id = ? AND status <> 'مشاهده شد'")
        ->execute([$letterId, $userId]);
}
