<?php
/**
 * ═══════════════════════════════════════════════════════════════════════
 *  افزوده‌های ماژولِ پذیرشِ نیرو (بدونِ حذفِ هیچ امکانِ قبلی)
 * ═══════════════════════════════════════════════════════════════════════
 *  ۱) صفِ تماس با اولویت: شماره‌ای که مدیر «اختصاصی» به یک کارشناس داده، قبل از شماره‌های عمومی به همان کارشناس می‌رسد.
 *  ۲) اطلاعاتِ بیشترِ هر شماره هنگامِ ورود از اکسل: شهر، نوعِ آگهی، منبعِ ورود + «باکسِ پذیرش» (حضوری / دورکار / زبانی).
 *  ۳) ارجاع به سرپرست بعد از حضور در مصاحبه‌ی حضوری.
 *  ۴) اعلامِ حضورِ جلسه‌رونده (برگزارکننده) برای هر جلسه.
 *  آمار و داشبوردِ واحد ← includes/reception_metrics.php
 */

if (!defined('RX_SCHEMA_FLAG')) {
    define('RX_SCHEMA_FLAG', __DIR__ . '/../storage/.reception_extras_v1');
}

/** ساختِ ستون‌ها/جدول‌های جدید (یک‌بار، خودکار) */
function rx_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    if (!function_exists('reception_module_ready') || !reception_module_ready($pdo)) return $ready = false;
    if (function_exists('rp_ready') && !rp_ready($pdo)) return $ready = false; // جدولِ مسیرِ پیگیری در شرطِ صف استفاده می‌شود
    if (is_file(RX_SCHEMA_FLAG)) return $ready = true;
    $ok = true;
    $addCol = static function (string $table, string $col, string $ddl) use ($pdo, &$ok): void {
        if (!reception_table_exists($pdo, $table) || reception_column_exists($pdo, $table, $col)) return;
        try { $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl"); } catch (Throwable $e) { $ok = false; error_log("rx_ready $table.$col: " . $e->getMessage()); }
    };
    $addCol('reception_applicants', 'pulled_at', '`pulled_at` DATETIME DEFAULT NULL');
    $addCol('reception_applicants', 'city', '`city` VARCHAR(100) DEFAULT NULL');
    $addCol('reception_applicants', 'ad_type', '`ad_type` VARCHAR(150) DEFAULT NULL');
    $addCol('reception_applicants', 'lead_source', '`lead_source` VARCHAR(150) DEFAULT NULL');
    $addCol('reception_applicants', 'intake_box', '`intake_box` VARCHAR(20) DEFAULT NULL');
    try { $pdo->exec('ALTER TABLE reception_applicants ADD KEY idx_ra_queue (assigned_agent_id, intake_box, pulled_at)'); } catch (Throwable $e) {}
    $addCol('reception_excel_import_rows', 'extra_json', '`extra_json` TEXT DEFAULT NULL');
    if (function_exists('reception_inperson_table_ready') && reception_inperson_table_ready($pdo)) {
        $addCol('reception_inperson_interviews', 'referred_supervisor_id', '`referred_supervisor_id` INT UNSIGNED DEFAULT NULL');
        $addCol('reception_inperson_interviews', 'referred_at', '`referred_at` DATETIME DEFAULT NULL');
        $addCol('reception_inperson_interviews', 'referred_by', '`referred_by` INT UNSIGNED DEFAULT NULL');
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `reception_host_checkins` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `kind` VARCHAR(10) NOT NULL,
          `host_user_id` INT UNSIGNED NOT NULL,
          `meet_date` DATE NOT NULL,
          `meet_time` VARCHAR(8) NOT NULL,
          `checked_at` DATETIME NOT NULL,
          `checked_by` INT UNSIGNED DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_rhc` (`kind`, `host_user_id`, `meet_date`, `meet_time`),
          KEY `idx_rhc_date` (`meet_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        $ok = false;
        error_log('rx_ready checkins: ' . $e->getMessage());
    }
    if (!$ok) return $ready = false;
    if (!is_dir(dirname(RX_SCHEMA_FLAG))) @mkdir(dirname(RX_SCHEMA_FLAG), 0755, true);
    @file_put_contents(RX_SCHEMA_FLAG, (string) time());
    return $ready = true;
}

/* =========================================================================
   باکس‌های پذیرش و اطلاعاتِ اضافه‌ی هر شماره
   ========================================================================= */

/** ماهِ شمسی: $offset = 0 این ماه، -1 ماهِ قبل → [اولِ ماه، آخرِ ماه] میلادی */
function rx_jalali_month(int $offset = 0): array
{
    [$jy, $jm] = array_map('intval', explode('/', normalize_digits(to_jalali(date('Y-m-d')))));
    $jm += $offset;
    while ($jm < 1) { $jm += 12; $jy--; }
    while ($jm > 12) { $jm -= 12; $jy++; }
    [$ny, $nm] = $jm === 12 ? [$jy + 1, 1] : [$jy, $jm + 1];
    $start = (string) to_gregorian(sprintf('%04d/%02d/01', $jy, $jm));
    $end = date('Y-m-d', strtotime(to_gregorian(sprintf('%04d/%02d/01', $ny, $nm)) . ' -1 day'));
    return [$start, $end];
}

function rx_intake_boxes(): array
{
    return ['inperson' => 'حضوری', 'remote' => 'دورکار', 'language' => 'زبانی'];
}

function rx_intake_box_label(?string $code): string
{
    return rx_intake_boxes()[(string) $code] ?? 'عمومی';
}

/** «حضوری» / «دور کار» / «زبان» … ← کدِ باکس (برای ستونِ اکسل) */
function rx_parse_intake_box(string $raw): ?string
{
    $t = str_replace(['‌', ' ', 'ي', 'ك'], ['', '', 'ی', 'ک'], mb_strtolower(trim($raw)));
    if ($t === '') return null;
    if (isset(rx_intake_boxes()[$t])) return $t;
    if (str_contains($t, 'حضور') || $t === 'onsite') return 'inperson';
    if (str_contains($t, 'دورکار') || str_contains($t, 'دور') || str_contains($t, 'remote')) return 'remote';
    if (str_contains($t, 'زبان') || str_contains($t, 'language') || str_contains($t, 'english')) return 'language';
    return null;
}

/** فیلدهای اضافه‌ی هر شماره: [کلید => برچسب] */
function rx_extra_fields(): array
{
    return ['city' => 'شهر', 'ad_type' => 'نوع آگهی', 'lead_source' => 'منبع ورود', 'intake_box' => 'باکس'];
}

/**
 * ستون‌های اختیاریِ فایلِ اکسل ← اندیسِ ستون. عنوان‌های قابلِ قبول (فاصله/نیم‌فاصله مهم نیست):
 *   شهر / استان و شهر | نوع آگهی / آگهی / عنوان آگهی | منبع / منبع ورود / منبع آگهی | باکس / نوع همکاری / باکس پذیرش
 */
function rx_detect_extra_columns(array $header): array
{
    $norm = static fn($h) => str_replace(['‌', ' ', 'ي', 'ك', 'ـ'], ['', '', 'ی', 'ک', ''], mb_strtolower(trim((string) $h)));
    $aliases = [
        'city'        => ['شهر', 'استانوشهر', 'شهرمحلسکونت', 'محلسکونت', 'city'],
        'ad_type'     => ['نوعآگهی', 'آگهی', 'عنوانآگهی', 'نوعاستخدام', 'adtype', 'ad'],
        'lead_source' => ['منبع', 'منبعورود', 'منبعآگهی', 'سایت', 'source'],
        'intake_box'  => ['باکس', 'باکسپذیرش', 'نوعهمکاری', 'نوعفعالیت', 'box'],
    ];
    $out = [];
    foreach ($header as $idx => $h) {
        $n = $norm($h);
        foreach ($aliases as $key => $list) {
            if (!isset($out[$key]) && in_array($n, $list, true)) $out[$key] = $idx;
        }
    }
    return $out;
}

/** مقادیرِ اضافه‌ی یک سطر: مقدارِ داخلِ فایل، وگرنه پیش‌فرضِ فرم */
function rx_row_extra(array $row, array $cols, array $defaults): array
{
    $out = [];
    foreach (rx_extra_fields() as $key => $_) {
        $v = isset($cols[$key]) ? trim(preg_replace('/\s+/u', ' ', (string) ($row[$cols[$key]] ?? '')) ?? '') : '';
        if ($v === '') $v = trim((string) ($defaults[$key] ?? ''));
        if ($key === 'intake_box') $v = (string) (rx_parse_intake_box($v) ?? '');
        if ($v !== '') $out[$key] = mb_substr($v, 0, $key === 'city' ? 100 : 150);
    }
    return $out;
}

/** ذخیره‌ی اطلاعاتِ اضافه روی پرونده‌ی متقاضی (فقط فیلدهای خالیِ قبلی پر می‌شوند، مگر $overwrite) */
function rx_apply_extra(PDO $pdo, int $applicantId, array $extra, bool $overwrite = false): void
{
    if (!$extra || !rx_ready($pdo)) return;
    $sets = [];
    $params = [];
    foreach (rx_extra_fields() as $key => $_) {
        if (!isset($extra[$key]) || $extra[$key] === '') continue;
        $sets[] = $overwrite ? "`$key` = ?" : "`$key` = COALESCE(NULLIF(`$key`, ''), ?)";
        $params[] = (string) $extra[$key];
    }
    if (!$sets) return;
    $params[] = $applicantId;
    try {
        $pdo->prepare('UPDATE reception_applicants SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    } catch (Throwable $e) {
        error_log('rx_apply_extra: ' . $e->getMessage());
    }
}

/* =========================================================================
   صفِ تماس با اولویت
   ========================================================================= */

/**
 * شرطِ «هنوز به کارشناس نرسیده»: در صف مانده، تماسی نگرفته و در مسیرِ پیگیری اقدامی رویش ثبت نشده.
 * شماره‌های اختصاصی‌ای که قبل از این نسخه وارد شده‌اند (pulled_at خالی) هم همین‌طور شناخته می‌شوند.
 */
function rx_untouched_sql(string $ra = 'ra'): string
{
    return "$ra.pulled_at IS NULL AND $ra.status = 'new' AND $ra.call_count = 0
        AND NOT EXISTS (SELECT 1 FROM reception_pipeline rxp WHERE rxp.applicant_id = $ra.id AND rxp.last_action_at IS NOT NULL)
        AND NOT EXISTS (SELECT 1 FROM reception_calls rxc WHERE rxc.applicant_id = $ra.id)";
}

/** تعدادِ شماره‌های اختصاصیِ منتظرِ همین کارشناس (+ عمومیِ هر باکس) */
function rx_queue_counts(PDO $pdo, int $agentId): array
{
    $out = ['mine' => 0, 'mine_by_box' => [], 'shared' => 0, 'shared_by_box' => []];
    if (!rx_ready($pdo)) return $out;
    try {
        $st = $pdo->prepare('SELECT COALESCE(ra.intake_box, \'\') b, COUNT(*) n FROM reception_applicants ra WHERE ra.assigned_agent_id = ? AND ' . rx_untouched_sql() . ' GROUP BY b');
        $st->execute([$agentId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) { $out['mine'] += (int) $r['n']; $out['mine_by_box'][(string) $r['b']] = (int) $r['n']; }
        foreach ($pdo->query("SELECT COALESCE(intake_box, '') b, COUNT(*) n FROM reception_applicants WHERE assigned_agent_id IS NULL GROUP BY b")->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out['shared'] += (int) $r['n'];
            $out['shared_by_box'][(string) $r['b']] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        error_log('rx_queue_counts: ' . $e->getMessage());
    }
    return $out;
}

/**
 * «درخواستِ پذیرنده» با اولویت:
 *   ۱) شماره‌های اختصاص‌داده‌شده به همین کارشناس که هنوز سراغشان نرفته (قدیمی‌ترین اول)
 *   ۲) شماره‌های عمومیِ بدونِ کارشناس (قدیمی‌ترین اول)
 * $box: '' = همه‌ی باکس‌ها، یا inperson / remote / language (فقط شماره‌های همان باکس)
 * @return array{ok:bool, applicant_id:?int, message:string, reserved?:bool}
 */
function rx_pull_next(PDO $pdo, int $agentUserId, string $box = ''): array
{
    $box = isset(rx_intake_boxes()[$box]) ? $box : '';
    $boxSql = $box !== '' ? ' AND ra.intake_box = ' . $pdo->quote($box) : '';
    try {
        $pdo->beginTransaction();
        // ۱) اختصاصیِ خودِ کارشناس
        $st = $pdo->prepare('SELECT ra.id FROM reception_applicants ra WHERE ra.assigned_agent_id = ? AND ' . rx_untouched_sql() . $boxSql
            . ' ORDER BY COALESCE(ra.assigned_at, ra.created_at) ASC, ra.id ASC LIMIT 1 FOR UPDATE');
        $st->execute([$agentUserId]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $id = (int) $id;
            $pdo->prepare("UPDATE reception_applicants SET pulled_at = NOW(), status = IF(status = 'new', 'calling', status), last_activity_at = NOW() WHERE id = ?")->execute([$id]);
            reception_record_status_change($pdo, $id, 'new', 'calling', $agentUserId);
            reception_log_action($pdo, $id, $agentUserId, 'pull_reserved', 'دریافتِ شماره‌ی اختصاصی از صف (اولویتِ ۱)');
            $pdo->commit();
            if (function_exists('rp_ensure')) rp_ensure($pdo, $id, $agentUserId, true);
            return ['ok' => true, 'applicant_id' => $id, 'reserved' => true, 'message' => 'شماره‌ی اختصاصیِ شما دریافت شد.'];
        }
        // ۲) عمومی
        $st = $pdo->query('SELECT ra.id FROM reception_applicants ra WHERE ra.assigned_agent_id IS NULL' . $boxSql . ' ORDER BY ra.created_at ASC, ra.id ASC LIMIT 1 FOR UPDATE');
        $id = $st->fetchColumn();
        if ($id === false) {
            $pdo->commit();
            return ['ok' => false, 'applicant_id' => null, 'message' => $box !== ''
                ? 'در باکسِ «' . rx_intake_box_label($box) . '» شماره‌ی آزادی نمانده است.'
                : 'در حالِ حاضر متقاضیِ بدونِ کارشناسی در صف وجود ندارد.'];
        }
        $id = (int) $id;
        $upd = $pdo->prepare("UPDATE reception_applicants SET assigned_agent_id = ?, assigned_at = NOW(), pulled_at = NOW(), status = IF(status = 'new', 'calling', status), last_activity_at = NOW() WHERE id = ? AND assigned_agent_id IS NULL");
        $upd->execute([$agentUserId, $id]);
        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'applicant_id' => null, 'message' => 'این متقاضی هم‌زمان توسطِ کارشناسِ دیگری برداشته شد. دوباره تلاش کنید.'];
        }
        reception_record_status_change($pdo, $id, 'new', 'calling', $agentUserId);
        reception_log_action($pdo, $id, $agentUserId, 'assign_agent', 'واگذاریِ متقاضی به کارشناسِ #' . $agentUserId);
        $pdo->commit();
        if (function_exists('rp_ensure')) rp_ensure($pdo, $id, $agentUserId, true);
        return ['ok' => true, 'applicant_id' => $id, 'reserved' => false, 'message' => 'متقاضی با موفقیت به شما اختصاص یافت.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rx_pull_next: ' . $e->getMessage());
        return ['ok' => false, 'applicant_id' => null, 'message' => 'خطا در واگذاریِ متقاضی. دوباره تلاش کنید.'];
    }
}

/* =========================================================================
   ارجاع به سرپرست (همان منطقِ «ارجاع به سرپرست» در پرونده‌ی متقاضی) — از پرونده،
   یا بعد از «حاضر شد» در مصاحبه‌ی حضوری؛ مصاحبه هم علامت می‌خورد که به کدام سرپرست رفت.
   ========================================================================= */

/** سرپرست‌های قابلِ انتخاب (با نامِ تیم) */
function rx_supervisors(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT u.id, u.full_name, t.id AS team_id, t.name AS team_name FROM users u LEFT JOIN teams t ON t.leader_user_id = u.id
            WHERE u.role = 'leader' AND u.is_active = 1 ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try { return $pdo->query("SELECT id, full_name, NULL AS team_id, NULL AS team_name FROM users WHERE role = 'leader' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
        catch (Throwable $e2) { return []; }
    }
}

/**
 * @return array{ok:bool, message:string}
 */
function rx_refer_to_supervisor(PDO $pdo, int $applicantId, int $supervisorId, array $actor, ?int $interviewId = null): array
{
    $applicant = reception_get_applicant($pdo, $applicantId);
    if (!$applicant) return ['ok' => false, 'message' => 'متقاضی پیدا نشد.'];
    $chk = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'leader' LIMIT 1");
    $chk->execute([$supervisorId]);
    $supervisor = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$supervisor) return ['ok' => false, 'message' => 'سرپرستِ انتخاب‌شده معتبر نیست.'];
    $actorId = (int) $actor['id'];
    try {
        $pdo->beginTransaction();
        $oldStatus = $applicant['status'];
        $pdo->prepare("UPDATE reception_applicants SET supervisor_user_id = ?, referred_at = NOW(), referred_by = ?, status = 'referred_to_supervisor', last_activity_at = NOW() WHERE id = ?")
            ->execute([$supervisorId, $actorId, $applicantId]);
        reception_record_status_change($pdo, $applicantId, $oldStatus, 'referred_to_supervisor', $actorId);
        // افزودنِ کارشناس به تیمِ سرپرست (در صورتِ وجودِ حسابِ کاربریِ متقاضی و جدولِ teams)
        if (!empty($applicant['user_id'])) {
            try {
                $teamStmt = $pdo->prepare('SELECT id FROM teams WHERE leader_user_id = ? LIMIT 1');
                $teamStmt->execute([$supervisorId]);
                $teamId = $teamStmt->fetchColumn();
                if ($teamId !== false) $pdo->prepare('UPDATE users SET team_id = ? WHERE id = ?')->execute([(int) $teamId, (int) $applicant['user_id']]);
            } catch (Throwable $e) {
            }
        }
        // مصاحبه‌ی حضوری: به کدام سرپرست رفت (برای آمارِ «تخصیص به سرپرست/واحد»)؛
        // اگر مصاحبه مشخص نشده، آخرین مصاحبه‌ی حضوریِ «حاضر شد» همین فرد که هنوز ارجاع نخورده
        if (!$interviewId && rx_ready($pdo)) {
            $q = $pdo->prepare("SELECT id FROM reception_inperson_interviews WHERE applicant_id = ? AND status = 'done' AND referred_supervisor_id IS NULL ORDER BY interview_date DESC, id DESC LIMIT 1");
            $q->execute([$applicantId]);
            $interviewId = (int) $q->fetchColumn() ?: null;
        }
        if ($interviewId && rx_ready($pdo)) {
            $pdo->prepare('UPDATE reception_inperson_interviews SET referred_supervisor_id = ?, referred_at = NOW(), referred_by = ? WHERE id = ? AND applicant_id = ?')
                ->execute([$supervisorId, $actorId, $interviewId, $applicantId]);
        }
        reception_notify($pdo, $supervisorId, $applicantId, 'ارجاعِ کارشناسِ جدید',
            trim($applicant['first_name'] . ' ' . $applicant['last_name']) . ' (' . $applicant['mobile'] . ') توسطِ ' . ($actor['full_name'] ?? '') . ' در تاریخِ ' . to_jalali(date('Y-m-d H:i:s')) . ' به شما ارجاع داده شد.',
            '../reception_applicant.php?id=' . $applicantId);
        if (!empty($applicant['user_id']) && !empty($supervisor['meeting_url'])) {
            reception_send_chat_message($pdo, $supervisorId, (int) $applicant['user_id'],
                reception_supervisor_welcome_message($applicant['first_name'], $supervisor['full_name'], $supervisor['meeting_url'], $supervisor['mobile']));
        }
        if (!empty($applicant['user_id'])) {
            reception_send_chat_message($pdo, (int) $applicant['user_id'], $supervisorId,
                reception_specialist_join_message(trim($applicant['first_name'] . ' ' . $applicant['last_name']), $applicant['mobile']));
        }
        reception_log_action($pdo, $applicantId, $actorId, 'assign_supervisor', 'ارجاع به سرپرست #' . $supervisorId . ($interviewId ? ' (بعد از مصاحبه‌ی حضوریِ #' . $interviewId . ')' : ''));
        $pdo->commit();
        if (function_exists('rp_on_joined')) rp_on_joined($pdo, $applicantId, $actorId, 'ارجاع به سرپرست: ' . $supervisor['full_name']);
        return ['ok' => true, 'message' => trim($applicant['first_name'] . ' ' . $applicant['last_name']) . ' به سرپرست «' . $supervisor['full_name'] . '» ارجاع داده شد.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rx_refer_to_supervisor: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'خطا در ارجاع به سرپرست.'];
    }
}

/* =========================================================================
   اعلامِ حضورِ جلسه‌رونده (برگزارکننده)
   ========================================================================= */

/** «۱۰:۳۰»، «10:30:00» … ← «10:30» */
function rx_hhmm(string $t): string
{
    $t = normalize_digits(trim($t));
    if (preg_match('/^(\d{1,2}):(\d{2})/', $t, $m)) return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    return '00:00';
}

/** برگزارکننده برای یک جلسه (نوع + تاریخ + ساعت) اعلامِ حضور کرده؟ [کلید => زمان] برای یک روز/بازه */
function rx_checkins(PDO $pdo, string $from, string $to): array
{
    $out = [];
    if (!rx_ready($pdo)) return $out;
    try {
        $st = $pdo->prepare('SELECT kind, host_user_id, meet_date, meet_time, checked_at FROM reception_host_checkins WHERE meet_date BETWEEN ? AND ?');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[$r['kind'] . '|' . (int) $r['host_user_id'] . '|' . $r['meet_date'] . '|' . $r['meet_time']] = (string) $r['checked_at'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * اعلامِ حضور: از ۳۰ دقیقه قبل از شروعِ جلسه تا پایانِ همان روز.
 * @return array{ok:bool, message:string}
 */
function rx_host_checkin(PDO $pdo, string $kind, int $hostId, string $date, string $time, int $by): array
{
    if (!rx_ready($pdo) || !in_array($kind, ['online', 'inperson'], true) || $hostId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'درخواست نامعتبر است.'];
    }
    $hm = rx_hhmm($time);
    $start = strtotime($date . ' ' . $hm . ':00');
    if ($start === false || time() < $start - 1800) return ['ok' => false, 'message' => 'اعلامِ حضور از ۳۰ دقیقه قبل از شروعِ جلسه ممکن است.'];
    if (date('Y-m-d') > $date) return ['ok' => false, 'message' => 'روزِ این جلسه گذشته است؛ اعلامِ حضور فقط در همان روز ثبت می‌شود.'];
    try {
        $pdo->prepare('INSERT INTO reception_host_checkins (kind, host_user_id, meet_date, meet_time, checked_at, checked_by) VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE checked_at = checked_at')->execute([$kind, $hostId, $date, $hm, date('Y-m-d H:i:s'), $by]);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'ثبت نشد.'];
    }
    return ['ok' => true, 'message' => 'حضورِ شما برای جلسه‌ی ساعتِ ' . to_persian_digits($hm) . ' ثبت شد. بعد از جلسه «حاضر / غایب» افراد را هم بزنید.'];
}
