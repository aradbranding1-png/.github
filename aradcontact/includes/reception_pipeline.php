<?php
/**
 * ماژولِ «قیفِ پذیرشِ نیرو» (مسیرِ پیگیریِ متقاضیان)
 * ---------------------------------------------------------------------------
 * هر متقاضیِ واگذارشده به یک نیروی پذیرش، یک «کارت» در این مسیر دارد:
 *
 *   تماس اولیه → دعوت‌شده → (پیگیری اولیه) → یادآوریِ قبل از جلسه → جلسه
 *        → حاضر شد → پیگیری بعد از جلسه → تعیین تکلیف
 *        → غایب شد → عدم حضور → جلسه‌ی مجدد (برگشت به دعوت) / پیگیری / تعیین تکلیف
 *        → «بعداً تماس بگیرید» → پیگیری مجدد (در تاریخِ تعیین‌شده برمی‌گردد)
 *
 * قانونِ اصلی: هر متقاضی یا «تعیین تکلیف» شده، یا یک «اقدامِ بعدی» با زمانِ مشخص دارد
 * (next_action_at / next_action_type). سامانه خودش اقدامِ بعدی را از روی مرحله حساب می‌کند؛
 * نیرو فقط تماس می‌گیرد و نتیجه را با یک دکمه ثبت می‌کند.
 *
 * مراحلِ ذخیره‌شده (stage):  contact | invited | attended | no_show | recall | closed
 * باکس‌های نمایشی (box) از روی مرحله + زمانِ جلسه محاسبه می‌شوند:
 *   contact, invited, reminder, today, awaiting, post, no_show, recall, closed
 *
 * همه‌ی SQLها قابل‌حمل نوشته شده‌اند (زمان‌ها از PHP پاس داده می‌شوند، نه NOW()).
 */

if (!defined('RP_SCHEMA_FLAG')) {
    define('RP_SCHEMA_FLAG', __DIR__ . '/../storage/.reception_pipeline_v1');
}

/* =========================================================================
   تعاریف
   ========================================================================= */

function rp_boxes(): array
{
    return [
        'contact'  => ['label' => 'تماس اولیه',          'icon' => 'fa-phone',              'tone' => 'blue',   'suggest' => 'تماس بگیرید و برای جلسه دعوت کنید'],
        'invited'  => ['label' => 'دعوت‌شده',            'icon' => 'fa-envelope-open-text', 'tone' => 'indigo', 'suggest' => 'پیگیریِ اولیه و اطمینان از زمانِ جلسه'],
        'reminder' => ['label' => 'نیازمند یادآوری',     'icon' => 'fa-bell',               'tone' => 'amber',  'suggest' => 'تماس و یادآوریِ جلسه'],
        'today'    => ['label' => 'جلسه امروز',          'icon' => 'fa-calendar-day',       'tone' => 'teal',   'suggest' => 'آماده‌ی جلسه — بعد از جلسه حضور را ثبت کنید'],
        'awaiting' => ['label' => 'منتظر نتیجه جلسه',    'icon' => 'fa-hourglass-half',     'tone' => 'purple', 'suggest' => 'ثبتِ حضور یا عدمِ حضور'],
        'post'     => ['label' => 'پیگیری بعد از جلسه',  'icon' => 'fa-comments',           'tone' => 'green',  'suggest' => 'تماس و گرفتنِ تصمیمِ نهایی'],
        'no_show'  => ['label' => 'عدم حضور',            'icon' => 'fa-user-xmark',         'tone' => 'red',    'suggest' => 'تعیینِ جلسه‌ی مجدد'],
        'recall'   => ['label' => 'پیگیری مجدد',         'icon' => 'fa-rotate',             'tone' => 'orange', 'suggest' => 'تماس در تاریخِ تعیین‌شده'],
        'closed'   => ['label' => 'تعیین تکلیف',         'icon' => 'fa-flag-checkered',     'tone' => 'slate',  'suggest' => 'پرونده بسته شده است'],
    ];
}

function rp_outcomes(): array
{
    return [
        'joined'         => ['label' => 'پذیرفته شد / پیوست',   'color' => 'success',   'icon' => 'fa-circle-check'],
        'not_interested' => ['label' => 'انصراف / عدم تمایل',    'color' => 'danger',    'icon' => 'fa-hand'],
        'rejected'       => ['label' => 'رد صلاحیت',             'color' => 'dark',      'icon' => 'fa-ban'],
        'unreachable'    => ['label' => 'عدم پاسخگویی مکرر',     'color' => 'warning',   'icon' => 'fa-phone-slash'],
        'wrong_number'   => ['label' => 'شماره اشتباه',          'color' => 'secondary', 'icon' => 'fa-hashtag'],
        'other'          => ['label' => 'سایر',                  'color' => 'secondary', 'icon' => 'fa-ellipsis'],
        'archived'       => ['label' => 'بایگانیِ خودکار (قدیمی)', 'color' => 'light',   'icon' => 'fa-box-archive'],
    ];
}

function rp_noshow_reasons(): array
{
    return [
        'forgot'        => 'فراموش کرده',
        'bad_time'      => 'زمان مناسب نبود',
        'internet'      => 'مشکل اینترنت / لینک',
        'unreachable'   => 'پاسخگو نبود',
        'changed_mind'  => 'منصرف شد',
        'new_time'      => 'درخواستِ زمانِ جدید',
        'unknown'       => 'دلیل نامشخص',
    ];
}

function rp_reminder_states(): array
{
    return [
        'none'      => ['label' => 'پیگیری نشده',  'color' => 'secondary'],
        'initial'   => ['label' => 'پیگیری اولیه شد', 'color' => 'info'],
        'reminded'  => ['label' => 'یادآوری شد',  'color' => 'primary'],
        'no_answer' => ['label' => 'پاسخ نداد',   'color' => 'warning'],
        'confirmed' => ['label' => 'تأیید حضور',  'color' => 'success'],
    ];
}

function rp_action_types(): array
{
    return [
        'call'       => 'تماسِ اولیه',
        'retry'      => 'تماسِ مجدد (پاسخ نداده بود)',
        'reinvite'   => 'تعیینِ جلسه‌ی مجدد',
        'initial'    => 'پیگیریِ اولیه‌ی دعوت',
        'remind'     => 'یادآوریِ جلسه',
        'attendance' => 'ثبتِ حضور / عدمِ حضور',
        'post'       => 'پیگیریِ بعد از جلسه',
        'recall'     => 'پیگیریِ مجدد',
    ];
}

function rp_event_labels(): array
{
    return [
        'assigned'           => 'ورود به مسیر (واگذاری به نیرو)',
        'call'               => 'تماس',
        'call_no_answer'     => 'تماس — پاسخ نداد',
        'invite'             => 'دعوت به جلسه',
        'meeting_cancelled'  => 'لغوِ جلسه',
        'reinvite_requested' => 'درخواستِ جلسه‌ی مجدد',
        'initial_done'       => 'پیگیریِ اولیه انجام شد',
        'reminded'           => 'یادآوریِ جلسه انجام شد',
        'reminder_no_answer' => 'یادآوری — پاسخ نداد',
        'confirmed'          => 'تأییدِ حضور',
        'attended'           => 'حاضر شد',
        'no_show'            => 'غایب شد',
        'followup_set'       => 'تعیینِ پیگیری',
        'closed'             => 'تعیین تکلیف',
        'reopened'           => 'بازگشاییِ پرونده',
        'reassigned'         => 'واگذاریِ مجدد به نیروی دیگر',
    ];
}

/* =========================================================================
   تنظیمات
   ========================================================================= */

function rp_default_settings(): array
{
    return [
        'remind_morning_hour'  => 8,   // یادآوری از ساعتِ ۸ صبحِ روزِ جلسه در لیست می‌آید
        'remind_min_before'    => 2,   // …و حداقل ۲ ساعت قبل از جلسه
        'await_minutes'        => 60,  // ۶۰ دقیقه بعد از شروعِ جلسه → «منتظر نتیجه»
        'stale_hours'          => 48,  // پرونده‌ی بدونِ اقدام بیش از ۴۸ ساعت → «رهاشده»
        'retry_hours'          => 3,   // «پاسخ نداد» → ۳ ساعت بعد دوباره در لیست
        'initial_followup'     => 1,   // پیگیریِ اولیه برای جلساتِ بیش از یک روز بعد
    ];
}

function rp_settings(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = rp_default_settings();
    try {
        $raw = $pdo->query('SELECT settings_json FROM reception_pipeline_settings WHERE id = 1')->fetchColumn();
        $saved = $raw ? json_decode((string) $raw, true) : null;
        if (is_array($saved)) {
            foreach ($cache as $k => $v) {
                if (isset($saved[$k]) && is_numeric($saved[$k])) {
                    $cache[$k] = (int) $saved[$k];
                }
            }
        }
    } catch (Throwable $e) {
    }
    return $cache;
}

function rp_save_settings(PDO $pdo, array $in): void
{
    $s = rp_default_settings();
    $bounds = [
        'remind_morning_hour' => [5, 14], 'remind_min_before' => [0, 12], 'await_minutes' => [15, 480],
        'stale_hours' => [12, 240], 'retry_hours' => [1, 48], 'initial_followup' => [0, 1],
    ];
    foreach ($s as $k => $v) {
        if (isset($in[$k]) && is_numeric(normalize_digits((string) $in[$k]))) {
            [$lo, $hi] = $bounds[$k];
            $s[$k] = max($lo, min($hi, (int) normalize_digits((string) $in[$k])));
        }
    }
    $json = json_encode($s);
    $up = $pdo->prepare('UPDATE reception_pipeline_settings SET settings_json = ? WHERE id = 1');
    $up->execute([$json]);
    if ($up->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO reception_pipeline_settings (id, settings_json) VALUES (1, ?)')->execute([$json]);
        } catch (Throwable $e) {
        }
    }
    rp_settings($pdo, true);
}

/* =========================================================================
   ساختار دیتابیس (خودترمیم)
   ========================================================================= */

function rp_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!function_exists('reception_module_ready') || !reception_module_ready($pdo)) {
        return $ready = false;
    }
    if (is_file(RP_SCHEMA_FLAG)) {
        return $ready = true;
    }
    $ddl = [
        "CREATE TABLE IF NOT EXISTS `reception_pipeline` (
          `applicant_id` INT UNSIGNED NOT NULL,
          `stage` VARCHAR(20) NOT NULL DEFAULT 'contact',
          `hint` VARCHAR(20) DEFAULT NULL,
          `meeting_kind` VARCHAR(10) DEFAULT NULL,
          `meeting_ref` INT UNSIGNED DEFAULT NULL,
          `meeting_at` DATETIME DEFAULT NULL,
          `host_user_id` INT UNSIGNED DEFAULT NULL,
          `invited_by` INT UNSIGNED DEFAULT NULL,
          `invited_at` DATETIME DEFAULT NULL,
          `invite_count` INT UNSIGNED NOT NULL DEFAULT 0,
          `initial_done_at` DATETIME DEFAULT NULL,
          `reminder_status` VARCHAR(20) NOT NULL DEFAULT 'none',
          `reminder_due_at` DATETIME DEFAULT NULL,
          `reminder_at` DATETIME DEFAULT NULL,
          `reminder_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
          `attendance` VARCHAR(10) DEFAULT NULL,
          `attendance_at` DATETIME DEFAULT NULL,
          `attendance_by` INT UNSIGNED DEFAULT NULL,
          `no_show_reason` VARCHAR(30) DEFAULT NULL,
          `followup_at` DATETIME DEFAULT NULL,
          `followup_note` VARCHAR(255) DEFAULT NULL,
          `outcome` VARCHAR(30) DEFAULT NULL,
          `outcome_note` VARCHAR(255) DEFAULT NULL,
          `closed_at` DATETIME DEFAULT NULL,
          `next_action_at` DATETIME DEFAULT NULL,
          `next_action_type` VARCHAR(20) DEFAULT NULL,
          `stage_changed_at` DATETIME NOT NULL,
          `last_action_at` DATETIME DEFAULT NULL,
          `last_action_by` INT UNSIGNED DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`applicant_id`),
          KEY `idx_rp_stage` (`stage`),
          KEY `idx_rp_next` (`next_action_at`),
          KEY `idx_rp_meeting` (`meeting_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `reception_pipeline_events` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `applicant_id` INT UNSIGNED NOT NULL,
          `agent_user_id` INT UNSIGNED DEFAULT NULL,
          `actor_user_id` INT UNSIGNED DEFAULT NULL,
          `event` VARCHAR(30) NOT NULL,
          `from_stage` VARCHAR(20) DEFAULT NULL,
          `to_stage` VARCHAR(20) DEFAULT NULL,
          `reason` VARCHAR(30) DEFAULT NULL,
          `note` VARCHAR(500) DEFAULT NULL,
          `meeting_at` DATETIME DEFAULT NULL,
          `created_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_rpe_applicant` (`applicant_id`),
          KEY `idx_rpe_event_time` (`event`, `created_at`),
          KEY `idx_rpe_agent` (`agent_user_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `reception_pipeline_settings` (
          `id` INT UNSIGNED NOT NULL,
          `settings_json` TEXT,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('rp_ready ddl: ' . $e->getMessage());
        }
    }
    // حضور/غیابِ جلساتِ آنلاین روی خودِ رزرو هم ثبت می‌شود (برای صفحه‌ی برگزارکننده)
    if (reception_table_exists($pdo, 'reception_meeting_bookings')) {
        foreach ([
            'attendance'        => 'ALTER TABLE reception_meeting_bookings ADD COLUMN attendance VARCHAR(10) DEFAULT NULL',
            'attendance_reason' => 'ALTER TABLE reception_meeting_bookings ADD COLUMN attendance_reason VARCHAR(30) DEFAULT NULL',
            'attendance_by'     => 'ALTER TABLE reception_meeting_bookings ADD COLUMN attendance_by INT UNSIGNED DEFAULT NULL',
            'attendance_at'     => 'ALTER TABLE reception_meeting_bookings ADD COLUMN attendance_at DATETIME DEFAULT NULL',
        ] as $col => $sql) {
            if (!reception_column_exists($pdo, 'reception_meeting_bookings', $col)) {
                try { $pdo->exec($sql); } catch (Throwable $e) {}
            }
        }
    }
    try {
        $pdo->query('SELECT applicant_id FROM reception_pipeline LIMIT 1');
        $pdo->query('SELECT id FROM reception_pipeline_events LIMIT 1');
        $pdo->query('SELECT id FROM reception_pipeline_settings LIMIT 1');
    } catch (Throwable $e) {
        return $ready = false;
    }
    if (function_exists('reception_inperson_table_ready')) {
        reception_inperson_table_ready($pdo);
    }
    if (!is_dir(dirname(RP_SCHEMA_FLAG))) {
        @mkdir(dirname(RP_SCHEMA_FLAG), 0755, true);
    }
    @file_put_contents(RP_SCHEMA_FLAG, (string) time());
    return $ready = true;
}

function rp_bookings_have_attendance(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $pdo->query('SELECT attendance FROM reception_meeting_bookings LIMIT 1');
            $has = true;
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/* =========================================================================
   ابزارهای زمان
   ========================================================================= */

function rp_now(): string
{
    return date('Y-m-d H:i:s');
}

function rp_ts(?string $dt): ?int
{
    if ($dt === null || $dt === '' || strpos($dt, '0000-00-00') === 0) {
        return null;
    }
    $t = strtotime($dt);
    return $t === false ? null : $t;
}

function rp_dt(int $ts): string
{
    return date('Y-m-d H:i:s', $ts);
}

/** «۱۰:۳۰»، «10.30»، «ساعت ۱۶» … → HH:MM:00 */
function rp_parse_time(?string $raw, string $default = '09:00:00'): string
{
    $s = normalize_digits(trim((string) $raw));
    if (preg_match('/(\d{1,2})\s*[:.٫]\s*(\d{2})/u', $s, $m)) {
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h <= 23 && $i <= 59) {
            return sprintf('%02d:%02d:00', $h, $i);
        }
    }
    if (preg_match('/\b(\d{1,2})\b/', $s, $m) && (int) $m[1] <= 23) {
        return sprintf('%02d:00:00', (int) $m[1]);
    }
    return $default;
}

function rp_meeting_datetime(string $dateYmd, ?string $time): string
{
    return substr($dateYmd, 0, 10) . ' ' . rp_parse_time($time);
}

/** «۲ ساعت و ۱۰ دقیقه دیگر» / «۴۵ دقیقه پیش» */
function rp_relative(?string $dt, ?int $now = null): string
{
    $t = rp_ts($dt);
    if ($t === null) {
        return '';
    }
    $now = $now ?? time();
    $diff = $t - $now;
    $abs = abs($diff);
    if ($abs < 60) {
        return 'همین حالا';
    }
    $d = intdiv($abs, 86400);
    $h = intdiv($abs % 86400, 3600);
    $m = intdiv($abs % 3600, 60);
    $parts = [];
    if ($d > 0) {
        $parts[] = $d . ' روز';
        if ($h > 0) $parts[] = $h . ' ساعت';
    } elseif ($h > 0) {
        $parts[] = $h . ' ساعت';
        if ($m > 0) $parts[] = $m . ' دقیقه';
    } else {
        $parts[] = $m . ' دقیقه';
    }
    return to_persian_digits(implode(' و ', $parts)) . ($diff > 0 ? ' دیگر' : ' پیش');
}

function rp_jdt(?string $dt, bool $withTime = true): string
{
    if (!$dt) {
        return '—';
    }
    $out = to_jalali(substr($dt, 0, 10));
    if ($withTime && strlen($dt) >= 16) {
        $out .= ' ' . to_persian_digits(substr($dt, 11, 5));
    }
    return $out;
}

/** پیشنهادهای سریعِ زمانِ پیگیری (برای نیرویی که نمی‌خواهد تقویم باز کند) */
function rp_quick_followups(): array
{
    return [
        'h2'       => '۲ ساعت بعد',
        'evening'  => 'امروز عصر',
        'tomorrow' => 'فردا صبح',
        'd2'       => 'پس‌فردا',
        'w1'       => 'هفته‌ی بعد',
    ];
}

function rp_resolve_followup(string $quick, string $jalaliDate = '', string $time = ''): ?string
{
    $now = time();
    switch ($quick) {
        case 'h2':       return rp_dt($now + 7200);
        case 'evening':  $t = strtotime(date('Y-m-d') . ' 17:00:00'); return rp_dt($t > $now ? $t : $now + 3600);
        case 'tomorrow': return date('Y-m-d', strtotime('+1 day')) . ' 10:00:00';
        case 'd2':       return date('Y-m-d', strtotime('+2 days')) . ' 10:00:00';
        case 'w1':       return date('Y-m-d', strtotime('+7 days')) . ' 10:00:00';
    }
    $jalaliDate = trim(normalize_digits($jalaliDate));
    if ($jalaliDate !== '') {
        $g = to_gregorian($jalaliDate);
        if ($g) {
            return $g . ' ' . rp_parse_time($time, '10:00:00');
        }
    }
    return null;
}

/* =========================================================================
   محاسبه‌ی «اقدامِ بعدی»
   ========================================================================= */

function rp_reminder_due(string $meetingAt, string $invitedAt, array $s): string
{
    $meet = rp_ts($meetingAt) ?? time();
    $inv = rp_ts($invitedAt) ?? time();
    $morning = strtotime(date('Y-m-d', $meet) . sprintf(' %02d:00:00', (int) $s['remind_morning_hour']));
    $due = min($morning, $meet - ((int) $s['remind_min_before']) * 3600);
    if ($due < $inv + 1800) {
        // جلسه خیلی نزدیک به زمانِ دعوت است؛ یادآوری کمی قبل از جلسه
        $due = max($inv + 1800, $meet - 3600);
        $due = min($due, $meet - 900);
        if ($due < $inv) {
            $due = $inv;
        }
    }
    return rp_dt($due);
}

/** @return array{0:?string,1:?string} [next_action_at, next_action_type] */
function rp_compute_next(array $r, array $s): array
{
    $stage = (string) ($r['stage'] ?? 'contact');
    switch ($stage) {
        case 'closed':
            return [null, null];
        case 'contact':
            $type = ($r['hint'] ?? '') === 'reinvite' ? 'reinvite' : ((($r['hint'] ?? '') === 'retry') ? 'retry' : 'call');
            return [$r['followup_at'] ?: $r['stage_changed_at'], $type];
        case 'invited':
            $meet = (string) ($r['meeting_at'] ?? '');
            if ($meet === '') {
                return [$r['stage_changed_at'], 'reinvite'];
            }
            $meetTs = rp_ts($meet) ?? time();
            if (in_array($r['reminder_status'] ?? 'none', ['reminded', 'confirmed'], true) || $meetTs + ((int) $s['await_minutes']) * 60 <= time()) {
                return [rp_dt((rp_ts($meet) ?? time()) + ((int) $s['await_minutes']) * 60), 'attendance'];
            }
            if (($r['reminder_status'] ?? '') === 'no_answer' && !empty($r['followup_at'])) {
                return [$r['followup_at'], 'remind'];
            }
            $remindDue = (string) ($r['reminder_due_at'] ?: $meet);
            if ((int) $s['initial_followup'] === 1 && empty($r['initial_done_at']) && !empty($r['invited_at'])) {
                $initialDue = (rp_ts($r['invited_at']) ?? time()) + 86400;
                if ($initialDue < (rp_ts($remindDue) ?? 0)) {
                    return [rp_dt($initialDue), 'initial'];
                }
            }
            return [$remindDue, 'remind'];
        case 'attended':
            return [$r['followup_at'] ?: ($r['attendance_at'] ?: $r['stage_changed_at']), 'post'];
        case 'no_show':
            return [$r['followup_at'] ?: ($r['attendance_at'] ?: $r['stage_changed_at']), 'reinvite'];
        case 'recall':
            return [$r['followup_at'] ?: $r['stage_changed_at'], 'recall'];
    }
    return [$r['stage_changed_at'] ?? rp_now(), 'call'];
}

/* =========================================================================
   خواندن / نوشتنِ کارت
   ========================================================================= */

function rp_row(PDO $pdo, int $applicantId): ?array
{
    $st = $pdo->prepare('SELECT * FROM reception_pipeline WHERE applicant_id = ?');
    $st->execute([$applicantId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function rp_agent_of(PDO $pdo, int $applicantId): ?int
{
    $st = $pdo->prepare('SELECT assigned_agent_id FROM reception_applicants WHERE id = ?');
    $st->execute([$applicantId]);
    $v = $st->fetchColumn();
    return $v ? (int) $v : null;
}

function rp_event(PDO $pdo, int $applicantId, ?int $actor, string $event, ?string $from, ?string $to, ?string $reason = null, ?string $note = null, ?string $meetingAt = null): void
{
    try {
        $pdo->prepare('INSERT INTO reception_pipeline_events (applicant_id, agent_user_id, actor_user_id, event, from_stage, to_stage, reason, note, meeting_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$applicantId, rp_agent_of($pdo, $applicantId), $actor, $event, $from, $to, $reason,
                $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null, $meetingAt, rp_now()]);
    } catch (Throwable $e) {
        error_log('rp_event: ' . $e->getMessage());
    }
}

/** کارتِ متقاضی را (اگر نیست) در مرحله‌ی «تماس اولیه» می‌سازد. */
function rp_ensure(PDO $pdo, int $applicantId, ?int $actor = null, bool $logEvent = true): ?array
{
    if (!rp_ready($pdo)) {
        return null;
    }
    $row = rp_row($pdo, $applicantId);
    if ($row) {
        return $row;
    }
    $now = rp_now();
    try {
        $pdo->prepare("INSERT INTO reception_pipeline (applicant_id, stage, reminder_status, next_action_at, next_action_type, stage_changed_at, created_at) VALUES (?, 'contact', 'none', ?, 'call', ?, ?)")
            ->execute([$applicantId, $now, $now, $now]);
        if ($logEvent) {
            rp_event($pdo, $applicantId, $actor, 'assigned', null, 'contact');
        }
    } catch (Throwable $e) {
        // رقابت: هم‌زمان ساخته شد
    }
    return rp_row($pdo, $applicantId);
}

/** ذخیره‌ی تغییرات + محاسبه‌ی دوباره‌ی اقدامِ بعدی */
function rp_update(PDO $pdo, array $row, array $changes, ?int $actor): array
{
    $new = array_merge($row, $changes);
    if (isset($changes['stage']) && $changes['stage'] !== $row['stage']) {
        $new['stage_changed_at'] = $changes['stage_changed_at'] ?? rp_now();
    }
    if ($actor !== null) {
        $new['last_action_at'] = rp_now();
        $new['last_action_by'] = $actor;
    }
    [$new['next_action_at'], $new['next_action_type']] = rp_compute_next($new, rp_settings($pdo));
    $cols = ['stage', 'hint', 'meeting_kind', 'meeting_ref', 'meeting_at', 'host_user_id', 'invited_by', 'invited_at', 'invite_count',
             'initial_done_at', 'reminder_status', 'reminder_due_at', 'reminder_at', 'reminder_attempts', 'attendance', 'attendance_at',
             'attendance_by', 'no_show_reason', 'followup_at', 'followup_note', 'outcome', 'outcome_note', 'closed_at',
             'next_action_at', 'next_action_type', 'stage_changed_at', 'last_action_at', 'last_action_by'];
    $set = [];
    $vals = [];
    foreach ($cols as $c) {
        $set[] = "`$c` = ?";
        $vals[] = $new[$c] ?? null;
    }
    $vals[] = (int) $row['applicant_id'];
    $pdo->prepare('UPDATE reception_pipeline SET ' . implode(', ', $set) . ' WHERE applicant_id = ?')->execute($vals);
    if ($actor !== null) {
        try {
            $pdo->prepare('UPDATE reception_applicants SET last_activity_at = ? WHERE id = ?')->execute([rp_now(), (int) $row['applicant_id']]);
        } catch (Throwable $e) {
        }
    }
    return $new;
}

/** هماهنگ‌کردنِ وضعیتِ قدیمیِ متقاضی (برای فیلترها و گزارش‌های موجود) */
function rp_sync_legacy_status(PDO $pdo, int $applicantId, string $newStatus, ?int $actor): void
{
    try {
        $st = $pdo->prepare('SELECT status FROM reception_applicants WHERE id = ?');
        $st->execute([$applicantId]);
        $old = (string) $st->fetchColumn();
        if ($old === $newStatus) {
            return;
        }
        // مراحلِ نهاییِ قدیمی (ارجاع به سرپرست / پذیرش) با اقدامِ قیف بازنویسی نشوند
        if (in_array($old, ['accepted', 'referred_to_supervisor', 'introduced_to_supervisor'], true) && $newStatus !== 'accepted') {
            return;
        }
        $pdo->prepare('UPDATE reception_applicants SET status = ? WHERE id = ?')->execute([$newStatus, $applicantId]);
        if (function_exists('reception_record_status_change')) {
            reception_record_status_change($pdo, $applicantId, $old, $newStatus, $actor);
        }
    } catch (Throwable $e) {
    }
}

/* ---------- هماهنگی با جداولِ جلسه ---------- */

function rp_cancel_linked_meeting(PDO $pdo, array $row): void
{
    $ref = (int) ($row['meeting_ref'] ?? 0);
    if ($ref <= 0) {
        return;
    }
    try {
        if (($row['meeting_kind'] ?? '') === 'online') {
            $pdo->prepare("UPDATE reception_meeting_bookings SET status = 'cancelled' WHERE id = ? AND status = 'booked'")->execute([$ref]);
        } elseif (($row['meeting_kind'] ?? '') === 'inperson') {
            $pdo->prepare("UPDATE reception_inperson_interviews SET status = 'cancelled' WHERE id = ? AND status = 'scheduled'")->execute([$ref]);
        }
    } catch (Throwable $e) {
    }
}

function rp_write_meeting_attendance(PDO $pdo, array $row, string $attendance, ?string $reason, int $actor): void
{
    $ref = (int) ($row['meeting_ref'] ?? 0);
    if ($ref <= 0) {
        return;
    }
    $now = rp_now();
    try {
        if (($row['meeting_kind'] ?? '') === 'online' && rp_bookings_have_attendance($pdo)) {
            $pdo->prepare('UPDATE reception_meeting_bookings SET attendance = ?, attendance_reason = ?, attendance_by = ?, attendance_at = ? WHERE id = ?')
                ->execute([$attendance, $reason, $actor, $now, $ref]);
        } elseif (($row['meeting_kind'] ?? '') === 'inperson') {
            $noteLabel = $reason ? (rp_noshow_reasons()[$reason] ?? $reason) : null;
            $pdo->prepare('UPDATE reception_inperson_interviews SET status = ?, result_note = COALESCE(?, result_note), status_changed_by = ?, status_changed_at = ? WHERE id = ?')
                ->execute([$attendance === 'attended' ? 'done' : 'no_show', $noteLabel, $actor, $now, $ref]);
        }
    } catch (Throwable $e) {
    }
}

/* =========================================================================
   رویدادهای ورودی از بقیه‌ی ماژولِ پذیرش (hookها)
   ========================================================================= */

function rp_on_invite(PDO $pdo, int $applicantId, string $kind, int $ref, string $meetingAt, ?int $hostId, ?int $actor): void
{
    if (!rp_ready($pdo)) {
        return;
    }
    try {
        $row = rp_ensure($pdo, $applicantId, $actor, true);
        if (!$row) {
            return;
        }
        $now = rp_now();
        $s = rp_settings($pdo);
        $sameDay = substr($meetingAt, 0, 10) <= date('Y-m-d', strtotime('+1 day'));
        $from = (string) $row['stage'];
        rp_update($pdo, $row, [
            'stage' => 'invited', 'hint' => null,
            'meeting_kind' => $kind, 'meeting_ref' => $ref, 'meeting_at' => $meetingAt, 'host_user_id' => $hostId ?: null,
            'invited_by' => $actor, 'invited_at' => $now, 'invite_count' => (int) $row['invite_count'] + 1,
            'initial_done_at' => $sameDay ? $now : null,
            'reminder_status' => 'none', 'reminder_due_at' => rp_reminder_due($meetingAt, $now, $s), 'reminder_at' => null, 'reminder_attempts' => 0,
            'attendance' => null, 'attendance_at' => null, 'attendance_by' => null, 'no_show_reason' => null,
            'followup_at' => null, 'followup_note' => null, 'outcome' => null, 'outcome_note' => null, 'closed_at' => null,
            'stage_changed_at' => $now,
        ], $actor);
        rp_event($pdo, $applicantId, $actor, 'invite', $from, 'invited', $kind, (int) $row['invite_count'] > 0 ? 'جلسه‌ی شماره‌ی ' . ((int) $row['invite_count'] + 1) : null, $meetingAt);
    } catch (Throwable $e) {
        error_log('rp_on_invite: ' . $e->getMessage());
    }
}

function rp_on_meeting_cancelled(PDO $pdo, int $applicantId, string $kind, int $ref, ?int $actor): void
{
    if (!rp_ready($pdo)) {
        return;
    }
    try {
        $row = rp_row($pdo, $applicantId);
        if (!$row || $row['stage'] !== 'invited' || (string) $row['meeting_kind'] !== $kind || (int) $row['meeting_ref'] !== $ref) {
            return;
        }
        rp_update($pdo, $row, ['stage' => 'contact', 'hint' => 'reinvite', 'followup_at' => rp_now()], $actor);
        rp_event($pdo, $applicantId, $actor, 'meeting_cancelled', 'invited', 'contact', $kind);
    } catch (Throwable $e) {
    }
}

/** نتیجه‌ی جلسه که از صفحه‌ی مصاحبه‌ی حضوری (یا برگزارکننده) ثبت شده */
function rp_on_meeting_result(PDO $pdo, int $applicantId, string $kind, int $ref, string $result, ?int $actor, ?string $reason = null): void
{
    if (!rp_ready($pdo)) {
        return;
    }
    try {
        $row = rp_row($pdo, $applicantId);
        if (!$row || (string) $row['meeting_kind'] !== $kind || (int) $row['meeting_ref'] !== $ref) {
            return;
        }
        if ($result === 'cancelled') {
            rp_on_meeting_cancelled($pdo, $applicantId, $kind, $ref, $actor);
            return;
        }
        if ($result === 'scheduled' && in_array($row['stage'], ['attended', 'no_show'], true)) {
            rp_update($pdo, $row, ['stage' => 'invited', 'attendance' => null, 'attendance_at' => null, 'no_show_reason' => null], $actor);
            return;
        }
        if (in_array($result, ['attended', 'no_show'], true)) {
            rp_apply_attendance($pdo, $row, $result, $reason, (int) $actor, false);
        }
    } catch (Throwable $e) {
    }
}

function rp_apply_attendance(PDO $pdo, array $row, string $result, ?string $reason, int $actor, bool $syncMeeting = true): array
{
    $from = (string) $row['stage'];
    if ($result === 'attended') {
        $new = rp_update($pdo, $row, [
            'stage' => 'attended', 'attendance' => 'attended', 'attendance_at' => rp_now(), 'attendance_by' => $actor,
            'no_show_reason' => null, 'followup_at' => null, 'hint' => null,
        ], $actor);
        rp_event($pdo, (int) $row['applicant_id'], $actor, 'attended', $from, 'attended', $row['meeting_kind'] ?? null, null, $row['meeting_at'] ?? null);
    } else {
        $reason = isset(rp_noshow_reasons()[(string) $reason]) ? (string) $reason : 'unknown';
        $new = rp_update($pdo, $row, [
            'stage' => 'no_show', 'attendance' => 'no_show', 'attendance_at' => rp_now(), 'attendance_by' => $actor,
            'no_show_reason' => $reason, 'followup_at' => null, 'hint' => null,
        ], $actor);
        rp_event($pdo, (int) $row['applicant_id'], $actor, 'no_show', $from, 'no_show', $reason, null, $row['meeting_at'] ?? null);
    }
    if ($syncMeeting) {
        rp_write_meeting_attendance($pdo, $row, $result, $result === 'no_show' ? ($new['no_show_reason'] ?? null) : null, $actor);
    }
    return $new;
}

/** تماسِ ثبت‌شده از فرمِ «ثبت تماس» پرونده */
function rp_on_call(PDO $pdo, int $applicantId, string $result, ?int $actor): void
{
    if (!rp_ready($pdo)) {
        return;
    }
    try {
        $row = rp_ensure($pdo, $applicantId, $actor, true);
        if (!$row) {
            return;
        }
        $from = (string) $row['stage'];
        if ($result === 'cancelled') {
            rp_close($pdo, $row, 'not_interested', 'از طریقِ ثبتِ تماس', (int) $actor, false);
            return;
        }
        if ($result === 'wrong_number') {
            rp_close($pdo, $row, 'wrong_number', 'از طریقِ ثبتِ تماس', (int) $actor, false);
            return;
        }
        if ($result === 'no_answer' && in_array($from, ['contact', 'recall', 'no_show', 'attended'], true)) {
            $s = rp_settings($pdo);
            rp_update($pdo, $row, ['followup_at' => rp_dt(time() + ((int) $s['retry_hours']) * 3600), 'hint' => $from === 'contact' ? 'retry' : $row['hint']], $actor);
            rp_event($pdo, $applicantId, $actor, 'call_no_answer', $from, $from);
            return;
        }
        if ($result === 'followup' && in_array($from, ['contact', 'no_show'], true)) {
            rp_update($pdo, $row, ['stage' => 'recall', 'followup_at' => date('Y-m-d', strtotime('+1 day')) . ' 10:00:00', 'hint' => null], $actor);
            rp_event($pdo, $applicantId, $actor, 'followup_set', $from, 'recall', null, 'از طریقِ ثبتِ تماس');
            return;
        }
        rp_update($pdo, $row, [], $actor);
        rp_event($pdo, $applicantId, $actor, 'call', $from, $from, $result);
    } catch (Throwable $e) {
        error_log('rp_on_call: ' . $e->getMessage());
    }
}

/** ارجاعِ متقاضی به سرپرست (= پیوستن به تیم) */
function rp_on_joined(PDO $pdo, int $applicantId, ?int $actor, string $note = ''): void
{
    if (!rp_ready($pdo)) {
        return;
    }
    try {
        $row = rp_ensure($pdo, $applicantId, $actor, true);
        if ($row && $row['stage'] !== 'closed') {
            rp_close($pdo, $row, 'joined', $note, (int) $actor, false);
        }
    } catch (Throwable $e) {
    }
}

function rp_close(PDO $pdo, array $row, string $outcome, string $note, int $actor, bool $cancelMeeting = true): array
{
    if (!isset(rp_outcomes()[$outcome])) {
        $outcome = 'other';
    }
    if ($cancelMeeting && $row['stage'] === 'invited') {
        rp_cancel_linked_meeting($pdo, $row);
    }
    $from = (string) $row['stage'];
    $new = rp_update($pdo, $row, [
        'stage' => 'closed', 'outcome' => $outcome, 'outcome_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
        'closed_at' => rp_now(), 'followup_at' => null, 'hint' => null,
    ], $actor);
    rp_event($pdo, (int) $row['applicant_id'], $actor, 'closed', $from, 'closed', $outcome, $note);
    $legacy = ['not_interested' => 'cancelled', 'rejected' => 'rejected', 'wrong_number' => 'wrong_number', 'unreachable' => 'no_answer'];
    if (isset($legacy[$outcome])) {
        rp_sync_legacy_status($pdo, (int) $row['applicant_id'], $legacy[$outcome], $actor);
    }
    return $new;
}

/* =========================================================================
   اقدام‌های نیرو (یک‌کلیکی)
   ========================================================================= */

/** اقدام‌هایی که در هر باکس به نیرو پیشنهاد می‌شود (ترتیب = اولویت) */
function rp_box_actions(string $box): array
{
    $map = [
        'contact'  => ['invite', 'no_answer', 'followup', 'close'],
        'invited'  => ['initial_done', 'confirmed', 'no_answer', 'reinvite', 'close'],
        'reminder' => ['reminded', 'confirmed', 'no_answer', 'reinvite', 'close'],
        'today'    => ['confirmed', 'attended', 'no_show', 'reinvite'],
        'awaiting' => ['attended', 'no_show'],
        'post'     => ['joined', 'not_interested', 'followup', 'reinvite'],
        'no_show'  => ['reinvite', 'followup', 'close'],
        'recall'   => ['invite', 'no_answer', 'followup', 'close'],
        'closed'   => ['reopen'],
    ];
    return $map[$box] ?? [];
}

function rp_action_meta(): array
{
    return [
        'invite'         => ['label' => 'دعوت به جلسه',   'icon' => 'fa-calendar-plus',   'style' => 'primary',   'kind' => 'link'],
        'initial_done'   => ['label' => 'پیگیری اولیه شد', 'icon' => 'fa-check',          'style' => 'info',      'kind' => 'direct'],
        'reminded'       => ['label' => 'یادآوری شد',      'icon' => 'fa-bell',            'style' => 'primary',   'kind' => 'direct'],
        'confirmed'      => ['label' => 'تأیید حضور',      'icon' => 'fa-circle-check',    'style' => 'success',   'kind' => 'direct'],
        'no_answer'      => ['label' => 'پاسخ نداد',       'icon' => 'fa-phone-slash',     'style' => 'warning',   'kind' => 'direct'],
        'attended'       => ['label' => 'حاضر شد',         'icon' => 'fa-user-check',      'style' => 'success',   'kind' => 'direct'],
        'no_show'        => ['label' => 'غایب شد',         'icon' => 'fa-user-xmark',      'style' => 'danger',    'kind' => 'reason'],
        'reinvite'       => ['label' => 'جلسه مجدد',       'icon' => 'fa-calendar-rotate', 'style' => 'primary',   'kind' => 'link'],
        'followup'       => ['label' => 'پیگیری بعدی',     'icon' => 'fa-clock',           'style' => 'secondary', 'kind' => 'date'],
        'close'          => ['label' => 'تعیین تکلیف',     'icon' => 'fa-flag-checkered',  'style' => 'dark',      'kind' => 'outcome'],
        'joined'         => ['label' => 'پذیرفته شد',      'icon' => 'fa-handshake',       'style' => 'success',   'kind' => 'direct'],
        'not_interested' => ['label' => 'تمایل ندارد',     'icon' => 'fa-hand',            'style' => 'danger',    'kind' => 'direct'],
        'reopen'         => ['label' => 'بازگشایی',        'icon' => 'fa-folder-open',     'style' => 'secondary', 'kind' => 'direct'],
    ];
}

/**
 * اجرای یک اقدام روی کارتِ متقاضی.
 * @return array{ok:bool, message:string, redirect?:string, stage?:string}
 */
function rp_act(PDO $pdo, int $applicantId, string $action, array $data, int $actor): array
{
    if (!rp_ready($pdo)) {
        return ['ok' => false, 'message' => 'ماژولِ مسیرِ پیگیری آماده نیست.'];
    }
    $row = rp_ensure($pdo, $applicantId, $actor, true);
    if (!$row) {
        return ['ok' => false, 'message' => 'پرونده پیدا نشد.'];
    }
    $stage = (string) $row['stage'];
    $s = rp_settings($pdo);
    $now = rp_now();
    $open = $stage !== 'closed';
    $applicantUrl = 'reception_applicant.php?id=' . $applicantId;

    switch ($action) {
        case 'invite':
            return ['ok' => true, 'message' => 'برای تعیینِ جلسه، پرونده باز می‌شود.', 'redirect' => $applicantUrl . '&focus=meeting#meeting-section'];

        case 'initial_done':
            if ($stage !== 'invited') break;
            rp_update($pdo, $row, ['initial_done_at' => $now, 'reminder_status' => $row['reminder_status'] === 'none' ? 'initial' : $row['reminder_status']], $actor);
            rp_event($pdo, $applicantId, $actor, 'initial_done', $stage, $stage, null, null, $row['meeting_at']);
            return ['ok' => true, 'message' => 'پیگیریِ اولیه ثبت شد. یادآوری در روزِ جلسه به لیست برمی‌گردد.'];

        case 'reminded':
        case 'confirmed':
            if ($stage !== 'invited') break;
            $ch = ['reminder_status' => $action, 'reminder_at' => $row['reminder_at'] ?: $now, 'followup_at' => null];
            if (empty($row['initial_done_at'])) $ch['initial_done_at'] = $now;
            rp_update($pdo, $row, $ch, $actor);
            rp_event($pdo, $applicantId, $actor, $action, $stage, $stage, null, null, $row['meeting_at']);
            return ['ok' => true, 'message' => $action === 'confirmed' ? 'حضورِ متقاضی تأیید شد.' : 'یادآوری ثبت شد.'];

        case 'no_answer':
            if (!$open) break;
            if ($stage === 'invited') {
                $meet = rp_ts($row['meeting_at']) ?? time();
                $retry = min(time() + 3600, max(time() + 900, $meet - 900));
                rp_update($pdo, $row, ['reminder_status' => 'no_answer', 'reminder_attempts' => (int) $row['reminder_attempts'] + 1, 'followup_at' => rp_dt($retry)], $actor);
                rp_event($pdo, $applicantId, $actor, 'reminder_no_answer', $stage, $stage, null, null, $row['meeting_at']);
                return ['ok' => true, 'message' => 'ثبت شد؛ ' . rp_relative(rp_dt($retry)) . ' دوباره در لیستِ یادآوری می‌آید.'];
            }
            $retryAt = rp_dt(time() + ((int) $s['retry_hours']) * 3600);
            rp_update($pdo, $row, ['followup_at' => $retryAt, 'hint' => $stage === 'contact' ? 'retry' : $row['hint']], $actor);
            rp_event($pdo, $applicantId, $actor, 'call_no_answer', $stage, $stage);
            return ['ok' => true, 'message' => 'ثبت شد؛ ' . to_persian_digits((string) $s['retry_hours']) . ' ساعتِ دیگر دوباره در لیست می‌آید.'];

        case 'attended':
        case 'no_show':
            if (!in_array($stage, ['invited', 'attended', 'no_show'], true)) break;
            if ($stage === 'invited' && !empty($row['meeting_at']) && (rp_ts($row['meeting_at']) ?? 0) > time() + 3600) {
                return ['ok' => false, 'message' => 'هنوز زمانِ جلسه نرسیده است.'];
            }
            rp_apply_attendance($pdo, $row, $action, $data['reason'] ?? null, $actor, true);
            return ['ok' => true, 'message' => $action === 'attended' ? 'حضور ثبت شد → پیگیریِ بعد از جلسه.' : 'عدمِ حضور ثبت شد → برای جلسه‌ی مجدد در لیست «عدم حضور» است.'];

        case 'reinvite':
            if ($stage === 'invited') {
                rp_cancel_linked_meeting($pdo, $row);
            }
            rp_update($pdo, $row, ['stage' => 'contact', 'hint' => 'reinvite', 'followup_at' => $now, 'outcome' => null, 'closed_at' => null], $actor);
            rp_event($pdo, $applicantId, $actor, 'reinvite_requested', $stage, 'contact', $stage === 'invited' ? 'reschedule' : null);
            return ['ok' => true, 'message' => 'برای انتخابِ جلسه‌ی جدید، پرونده باز می‌شود.', 'redirect' => $applicantUrl . '&focus=meeting#meeting-section'];

        case 'followup':
            $at = rp_resolve_followup((string) ($data['quick'] ?? ''), (string) ($data['date'] ?? ''), (string) ($data['time'] ?? ''));
            if (!$at) {
                return ['ok' => false, 'message' => 'زمانِ پیگیری را انتخاب کنید.'];
            }
            $note = trim((string) ($data['note'] ?? ''));
            $toStage = $stage === 'attended' ? 'attended' : 'recall';
            rp_update($pdo, $row, ['stage' => $toStage, 'followup_at' => $at, 'followup_note' => $note !== '' ? mb_substr($note, 0, 255) : null,
                'hint' => null, 'outcome' => null, 'closed_at' => null], $actor);
            rp_event($pdo, $applicantId, $actor, 'followup_set', $stage, $toStage, null, $note, $at);
            if ($stage !== 'attended') {
                rp_sync_legacy_status($pdo, $applicantId, 'followup', $actor);
            }
            return ['ok' => true, 'message' => 'پیگیری برای ' . rp_jdt($at) . ' تنظیم شد.'];

        case 'close':
        case 'joined':
        case 'not_interested':
            $outcome = $action === 'close' ? (string) ($data['outcome'] ?? '') : $action;
            if (!isset(rp_outcomes()[$outcome]) || $outcome === 'archived') {
                return ['ok' => false, 'message' => 'نتیجه‌ی تعیین تکلیف را انتخاب کنید.'];
            }
            rp_close($pdo, $row, $outcome, trim((string) ($data['note'] ?? '')), $actor, true);
            return ['ok' => true, 'message' => 'پرونده تعیین تکلیف شد: ' . rp_outcomes()[$outcome]['label']];

        case 'reopen':
            if ($stage !== 'closed') break;
            rp_update($pdo, $row, ['stage' => 'contact', 'outcome' => null, 'outcome_note' => null, 'closed_at' => null, 'followup_at' => $now, 'hint' => null], $actor);
            rp_event($pdo, $applicantId, $actor, 'reopened', 'closed', 'contact');
            if (in_array((string) $row['outcome'], ['not_interested', 'rejected', 'wrong_number', 'unreachable'], true)) {
                rp_sync_legacy_status($pdo, $applicantId, 'calling', $actor);
            }
            return ['ok' => true, 'message' => 'پرونده دوباره باز شد و در «تماس اولیه» است.'];
    }
    return ['ok' => false, 'message' => 'این اقدام در مرحله‌ی فعلیِ پرونده مجاز نیست. صفحه را تازه کنید.'];
}

/** آیا این کاربر اجازه‌ی اقدام روی این متقاضی را دارد؟ */
function rp_is_manager(array $user): bool
{
    return user_can('admin_reception_candidates', $user) || user_can('admin_reception_reports', $user) || user_can('admin_reception_staff', $user);
}

function rp_can_act(PDO $pdo, array $user, int $applicantId, string $action): bool
{
    if (rp_is_manager($user)) {
        return true;
    }
    $agent = rp_agent_of($pdo, $applicantId);
    if ($agent !== null && $agent === (int) $user['id'] && user_can('reception_agent_panel', $user)) {
        return true;
    }
    // برگزارکننده‌ی جلسه فقط حضور/غیاب را ثبت می‌کند
    if (in_array($action, ['attended', 'no_show'], true)) {
        $row = rp_row($pdo, $applicantId);
        if ($row && (int) ($row['host_user_id'] ?? 0) === (int) $user['id']) {
            return true;
        }
    }
    return false;
}

/* =========================================================================
   همگام‌سازی / ساختِ کارت برای متقاضیانِ قبلی
   ========================================================================= */

/**
 * برای متقاضیانِ واگذارشده‌ای که هنوز کارت ندارند، کارت می‌سازد و مرحله را از روی سوابقِ
 * موجود (رزروِ آنلاین، مصاحبه‌ی حضوری، وضعیت) حدس می‌زند. در هر فراخوانی حداکثر $limit ردیف.
 */
function rp_sync(PDO $pdo, int $limit = 1500): int
{
    if (!rp_ready($pdo)) {
        return 0;
    }
    try {
        $st = $pdo->query("SELECT ra.id, ra.status, ra.assigned_at, ra.last_activity_at, ra.created_at
            FROM reception_applicants ra
            LEFT JOIN reception_pipeline p ON p.applicant_id = ra.id
            WHERE ra.assigned_agent_id IS NOT NULL AND p.applicant_id IS NULL
            ORDER BY ra.id ASC LIMIT " . (int) $limit);
        $apps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 0;
    }
    if (!$apps) {
        return 0;
    }
    $ids = array_map(static fn($a) => (int) $a['id'], $apps);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $online = [];
    if (reception_meeting_slots_ready($pdo)) {
        try {
            $q = $pdo->prepare("SELECT bk.id, bk.applicant_id, bk.agent_user_id, bk.created_at, s.slot_date, s.start_time, s.supervisor_user_id
                FROM reception_meeting_bookings bk JOIN reception_meeting_slots s ON s.id = bk.slot_id
                WHERE bk.status = 'booked' AND bk.applicant_id IN ($ph) ORDER BY s.slot_date ASC");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $b) {
                $online[(int) $b['applicant_id']] = $b; // آخرین (دیرترین) جلسه
            }
        } catch (Throwable $e) {
        }
    }
    $inperson = [];
    if (function_exists('reception_inperson_table_ready') && reception_inperson_table_ready($pdo)) {
        try {
            $q = $pdo->prepare("SELECT id, applicant_id, agent_user_id, supervisor_user_id, interview_date, interview_time, status, created_at, status_changed_at
                FROM reception_inperson_interviews WHERE status <> 'cancelled' AND applicant_id IN ($ph) ORDER BY interview_date ASC, id ASC");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $iv) {
                $inperson[(int) $iv['applicant_id']] = $iv;
            }
        } catch (Throwable $e) {
        }
    }

    $s = rp_settings($pdo);
    $now = time();
    $closedMap = [
        'accepted' => 'joined', 'referred_to_supervisor' => 'joined', 'introduced_to_supervisor' => 'joined', 'pending_supervisor' => 'joined',
        'rejected' => 'rejected', 'cancelled' => 'not_interested', 'wrong_number' => 'wrong_number',
    ];
    $ins = $pdo->prepare('INSERT INTO reception_pipeline (applicant_id, stage, hint, meeting_kind, meeting_ref, meeting_at, host_user_id, invited_by, invited_at, invite_count,
        initial_done_at, reminder_status, reminder_due_at, attendance, attendance_at, outcome, closed_at, followup_at, next_action_at, next_action_type, stage_changed_at, last_action_at, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $done = 0;
    foreach ($apps as $a) {
        $id = (int) $a['id'];
        $last = (string) ($a['last_activity_at'] ?: ($a['assigned_at'] ?: $a['created_at']));
        $r = [
            'stage' => 'contact', 'hint' => null, 'meeting_kind' => null, 'meeting_ref' => null, 'meeting_at' => null, 'host_user_id' => null,
            'invited_by' => null, 'invited_at' => null, 'invite_count' => 0, 'initial_done_at' => null, 'reminder_status' => 'none',
            'reminder_due_at' => null, 'attendance' => null, 'attendance_at' => null, 'outcome' => null, 'closed_at' => null,
            'followup_at' => null, 'stage_changed_at' => $last, 'last_action_at' => $a['last_activity_at'] ?: null,
        ];
        $status = (string) $a['status'];
        $meeting = null;
        $b = $online[$id] ?? null;
        $iv = $inperson[$id] ?? null;
        if ($b) {
            $meeting = ['kind' => 'online', 'ref' => (int) $b['id'], 'at' => rp_meeting_datetime((string) $b['slot_date'], (string) $b['start_time']),
                'host' => (int) $b['supervisor_user_id'], 'by' => (int) $b['agent_user_id'], 'inv' => (string) $b['created_at'], 'status' => 'scheduled'];
        }
        if ($iv) {
            $ivAt = rp_meeting_datetime((string) $iv['interview_date'], (string) ($iv['interview_time'] ?? ''));
            if (!$meeting || $ivAt > $meeting['at']) {
                $meeting = ['kind' => 'inperson', 'ref' => (int) $iv['id'], 'at' => $ivAt, 'host' => (int) ($iv['supervisor_user_id'] ?? 0),
                    'by' => (int) $iv['agent_user_id'], 'inv' => (string) $iv['created_at'], 'status' => (string) $iv['status'],
                    'changed' => (string) ($iv['status_changed_at'] ?? '')];
            }
        }
        if ($meeting) {
            $r['meeting_kind'] = $meeting['kind'];
            $r['meeting_ref'] = $meeting['ref'];
            $r['meeting_at'] = $meeting['at'];
            $r['host_user_id'] = $meeting['host'] ?: null;
            $r['invited_by'] = $meeting['by'] ?: null;
            $r['invited_at'] = $meeting['inv'];
            $r['invite_count'] = 1;
            $r['initial_done_at'] = $meeting['inv'];
            $r['reminder_due_at'] = rp_reminder_due($meeting['at'], $meeting['inv'], $s);
        }

        if (isset($closedMap[$status])) {
            $r['stage'] = 'closed';
            $r['outcome'] = $closedMap[$status];
            $r['closed_at'] = $last;
        } elseif ($meeting && $meeting['status'] === 'done') {
            $r['stage'] = 'attended';
            $r['attendance'] = 'attended';
            $r['attendance_at'] = $meeting['changed'] ?: $meeting['at'];
        } elseif ($meeting && $meeting['status'] === 'no_show') {
            $r['stage'] = 'no_show';
            $r['attendance'] = 'no_show';
            $r['attendance_at'] = $meeting['changed'] ?: $meeting['at'];
        } elseif ($meeting && (rp_ts($meeting['at']) ?? 0) >= $now - 14 * 86400) {
            $r['stage'] = 'invited';
            $r['stage_changed_at'] = $meeting['inv'] ?: $last;
        } elseif ($status === 'followup') {
            $r['stage'] = 'recall';
            $r['followup_at'] = rp_dt($now);
        }
        // پرونده‌های باز که بیش از ۳۰ روز هیچ فعالیتی نداشته‌اند، بایگانیِ خودکار می‌شوند تا
        // لیستِ کارِ نیروها با سوابقِ خیلی قدیمی پر نشود (از گزارشِ مدیر قابلِ بازگشایی است).
        if ($r['stage'] !== 'closed' && $r['stage'] !== 'invited' && (rp_ts($last) ?? $now) < $now - 30 * 86400) {
            $r['stage'] = 'closed';
            $r['outcome'] = 'archived';
            $r['closed_at'] = rp_dt($now);
        }
        [$nextAt, $nextType] = rp_compute_next($r, $s);
        try {
            $ins->execute([$id, $r['stage'], $r['hint'], $r['meeting_kind'], $r['meeting_ref'], $r['meeting_at'], $r['host_user_id'], $r['invited_by'],
                $r['invited_at'], $r['invite_count'], $r['initial_done_at'], $r['reminder_status'], $r['reminder_due_at'], $r['attendance'],
                $r['attendance_at'], $r['outcome'], $r['closed_at'], $r['followup_at'], $nextAt, $nextType, $r['stage_changed_at'],
                $r['last_action_at'], rp_now()]);
            $done++;
        } catch (Throwable $e) {
        }
    }
    return $done;
}

/* =========================================================================
   کوئری‌ها: باکس‌ها، شمارنده‌ها، لیست‌ها
   ========================================================================= */

/** زمان‌های مرجع (یک‌بار در هر درخواست) */
function rp_clock(PDO $pdo): array
{
    $s = rp_settings($pdo);
    $now = time();
    $today = date('Y-m-d', $now);
    return [
        'now'         => rp_dt($now),
        'today'       => $today . ' 00:00:00',
        'tomorrow'    => date('Y-m-d', strtotime('+1 day', $now)) . ' 00:00:00',
        'after_tmrw'  => date('Y-m-d', strtotime('+2 days', $now)) . ' 00:00:00',
        'await_cut'   => rp_dt($now - ((int) $s['await_minutes']) * 60),
        'stale_cut'   => rp_dt($now - ((int) $s['stale_hours']) * 3600),
    ];
}

/** عبارتِ SQLِ باکسِ نمایشی (مقادیرِ زمانی از PHP و quote‌شده) */
function rp_box_sql(PDO $pdo, array $c, string $p = 'p'): string
{
    $q = static fn($v) => $pdo->quote($v);
    return "CASE
        WHEN $p.stage = 'closed' THEN 'closed'
        WHEN $p.stage = 'recall' THEN 'recall'
        WHEN $p.stage = 'no_show' THEN 'no_show'
        WHEN $p.stage = 'attended' THEN 'post'
        WHEN $p.stage = 'invited' AND $p.meeting_at IS NOT NULL AND $p.meeting_at < {$q($c['await_cut'])} THEN 'awaiting'
        WHEN $p.stage = 'invited' AND $p.reminder_status NOT IN ('reminded','confirmed') AND ($p.meeting_at IS NULL OR $p.meeting_at < {$q($c['tomorrow'])} OR COALESCE($p.reminder_due_at, $p.meeting_at) <= {$q($c['now'])}) THEN 'reminder'
        WHEN $p.stage = 'invited' AND $p.meeting_at >= {$q($c['today'])} AND $p.meeting_at < {$q($c['tomorrow'])} THEN 'today'
        WHEN $p.stage = 'invited' THEN 'invited'
        ELSE 'contact' END";
}

/**
 * دامنه‌ی متقاضیان: [] = همه، [id,…] = فقط این نیروها
 * @return array{0:string,1:array}
 */
function rp_scope_sql(array $agentIds, string $ra = 'ra'): array
{
    $agentIds = array_values(array_filter(array_map('intval', $agentIds)));
    if (!$agentIds) {
        return ["$ra.assigned_agent_id IS NOT NULL", []];
    }
    return ["$ra.assigned_agent_id IN (" . implode(',', array_fill(0, count($agentIds), '?')) . ')', $agentIds];
}

/** شمارنده‌های باکس‌ها + «امروزِ من» */
function rp_counts(PDO $pdo, array $agentIds = []): array
{
    $boxes = array_fill_keys(array_keys(rp_boxes()), 0);
    $due = array_fill_keys(array_keys(rp_boxes()), 0);
    $out = ['boxes' => $boxes, 'due' => $due, 'overdue' => 0, 'today' => 0, 'due_now' => 0, 'reminders' => 0, 'new' => 0, 'stale' => 0,
            'meetings_today' => 0, 'mt_none' => 0, 'mt_reminded' => 0, 'mt_confirmed' => 0, 'mt_no_answer' => 0, 'open' => 0];
    if (!rp_ready($pdo)) {
        return $out;
    }
    $c = rp_clock($pdo);
    $q = static fn($v) => $pdo->quote($v);
    [$scope, $params] = rp_scope_sql($agentIds);
    $box = rp_box_sql($pdo, $c);
    $sql = "SELECT $box AS box, COUNT(*) AS n,
              SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at <= {$q($c['now'])} THEN 1 ELSE 0 END) AS due_now,
              SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at < {$q($c['today'])} THEN 1 ELSE 0 END) AS overdue,
              SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at >= {$q($c['today'])} AND p.next_action_at < {$q($c['tomorrow'])} THEN 1 ELSE 0 END) AS today,
              SUM(CASE WHEN p.stage = 'contact' AND p.last_action_at IS NULL THEN 1 ELSE 0 END) AS fresh,
              SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at <= {$q($c['now'])} AND COALESCE(p.last_action_at, p.stage_changed_at) < {$q($c['stale_cut'])} THEN 1 ELSE 0 END) AS stale
            FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id
            WHERE $scope GROUP BY $box";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $b = (string) $r['box'];
            if (!isset($out['boxes'][$b])) continue;
            $out['boxes'][$b] = (int) $r['n'];
            $out['due'][$b] = (int) $r['due_now'];
            $out['overdue'] += (int) $r['overdue'];
            $out['today'] += (int) $r['today'];
            $out['due_now'] += (int) $r['due_now'];
            $out['new'] += (int) $r['fresh'];
            $out['stale'] += (int) $r['stale'];
            if ($b !== 'closed') $out['open'] += (int) $r['n'];
        }
        $out['reminders'] = $out['boxes']['reminder'];

        // جلساتِ امروز (همه، با وضعیتِ پیگیری)
        $st = $pdo->prepare("SELECT p.reminder_status, COUNT(*) n FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id
            WHERE $scope AND p.stage = 'invited' AND p.meeting_at >= {$q($c['today'])} AND p.meeting_at < {$q($c['tomorrow'])} GROUP BY p.reminder_status");
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $n = (int) $r['n'];
            $out['meetings_today'] += $n;
            $k = 'mt_' . ((string) $r['reminder_status'] === 'initial' ? 'none' : (string) $r['reminder_status']);
            if (isset($out[$k])) $out[$k] += $n;
        }
        // حاضر/غایبِ امروز هم جزوِ «جلساتِ امروز» حساب شوند
        $st = $pdo->prepare("SELECT COUNT(*) FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id
            WHERE $scope AND p.stage IN ('attended','no_show') AND p.meeting_at >= {$q($c['today'])} AND p.meeting_at < {$q($c['tomorrow'])}");
        $st->execute($params);
        $out['meetings_today_done'] = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('rp_counts: ' . $e->getMessage());
    }
    return $out;
}

/**
 * لیستِ افرادِ یک باکس.
 * $box: یکی از rp_boxes() یا 'tasks' (همه‌ی کارهای سررسیدشده) یا 'meetings_today' یا 'stale'
 * $day: all | today | tomorrow | overdue
 */
function rp_list(PDO $pdo, string $box, array $agentIds = [], string $day = 'all', string $search = '', int $limit = 200, int $offset = 0): array
{
    if (!rp_ready($pdo)) {
        return ['rows' => [], 'total' => 0];
    }
    $c = rp_clock($pdo);
    $q = static fn($v) => $pdo->quote($v);
    [$scope, $params] = rp_scope_sql($agentIds);
    $boxSql = rp_box_sql($pdo, $c);
    $where = [$scope];
    $meetingBoxes = ['invited', 'reminder', 'today', 'awaiting', 'meetings_today'];

    if ($box === 'tasks') {
        $where[] = "p.stage <> 'closed' AND p.next_action_at <= " . $q($c['now']);
    } elseif ($box === 'meetings_today') {
        $where[] = "p.stage IN ('invited','attended','no_show') AND p.meeting_at >= {$q($c['today'])} AND p.meeting_at < {$q($c['tomorrow'])}";
    } elseif ($box === 'stale') {
        $where[] = "p.stage <> 'closed' AND p.next_action_at <= {$q($c['now'])} AND COALESCE(p.last_action_at, p.stage_changed_at) < " . $q($c['stale_cut']);
    } elseif (isset(rp_boxes()[$box])) {
        $where[] = "($boxSql) = " . $q($box);
    }

    $col = in_array($box, $meetingBoxes, true) ? 'p.meeting_at' : 'p.next_action_at';
    if ($day === 'today') {
        $where[] = "$col >= {$q($c['today'])} AND $col < {$q($c['tomorrow'])}";
    } elseif ($day === 'tomorrow') {
        $where[] = "$col >= {$q($c['tomorrow'])} AND $col < {$q($c['after_tmrw'])}";
    } elseif ($day === 'overdue') {
        $where[] = "p.stage <> 'closed' AND p.next_action_at < " . $q($c['today']);
    }
    if ($search !== '') {
        $like = '%' . normalize_digits($search) . '%';
        $where[] = "(ra.first_name LIKE ? OR ra.last_name LIKE ? OR (ra.first_name || ' ' || ra.last_name) LIKE ? OR ra.mobile LIKE ? OR ra.mobile_normalized LIKE ?)";
        array_push($params, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    // CONCAT برای MySQL (|| در MySQL به‌معنای OR است)
    if (rp_is_mysql($pdo)) {
        $whereSql = str_replace("(ra.first_name || ' ' || ra.last_name)", "CONCAT(ra.first_name, ' ', ra.last_name)", $whereSql);
    }

    if ($box === 'closed') {
        $order = 'p.closed_at DESC';
    } elseif (in_array($box, $meetingBoxes, true)) {
        $order = 'p.meeting_at ASC';
    } else {
        $order = 'p.next_action_at ASC';
    }
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id WHERE $whereSql");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT p.*, $boxSql AS box, ra.first_name, ra.last_name, ra.mobile, ra.mobile_normalized, ra.assigned_agent_id, ra.call_count,
                ag.full_name AS agent_name, ho.full_name AS host_name, iv.full_name AS inviter_name
            FROM reception_pipeline p
            JOIN reception_applicants ra ON ra.id = p.applicant_id
            LEFT JOIN users ag ON ag.id = ra.assigned_agent_id
            LEFT JOIN users ho ON ho.id = p.host_user_id
            LEFT JOIN users iv ON iv.id = p.invited_by
            WHERE $whereSql ORDER BY $order, p.applicant_id ASC LIMIT $limit OFFSET $offset");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('rp_list: ' . $e->getMessage());
        return ['rows' => [], 'total' => 0];
    }
    $stale = rp_ts($c['stale_cut']);
    $nowTs = time();
    foreach ($rows as &$r) {
        $last = rp_ts($r['last_action_at'] ?: $r['stage_changed_at']);
        $r['is_stale'] = $r['stage'] !== 'closed' && $last !== null && $last < $stale && (rp_ts($r['next_action_at']) ?? PHP_INT_MAX) <= $nowTs;
        $r['is_overdue'] = $r['stage'] !== 'closed' && (rp_ts($r['next_action_at']) ?? PHP_INT_MAX) < strtotime($c['today']);
        $r['is_due'] = $r['stage'] !== 'closed' && (rp_ts($r['next_action_at']) ?? PHP_INT_MAX) <= $nowTs;
        if ($r['box'] === 'awaiting') {
            // زمانِ جلسه گذشته؛ اقدامِ بعدی دیگر یادآوری نیست، ثبتِ نتیجه است
            $r['next_action_type'] = 'attendance';
        }
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total];
}

/** باکسِ نمایشیِ فعلیِ یک متقاضی */
function rp_box_of(PDO $pdo, int $applicantId): ?string
{
    if (!rp_ready($pdo)) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT ' . rp_box_sql($pdo, rp_clock($pdo)) . ' FROM reception_pipeline p WHERE p.applicant_id = ?');
        $st->execute([$applicantId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    } catch (Throwable $e) {
        return null;
    }
}

function rp_is_mysql(PDO $pdo): bool
{
    try {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    } catch (Throwable $e) {
        return true;
    }
}

/** رویدادهای یک متقاضی (برای Timeline) */
function rp_events_of(PDO $pdo, int $applicantId): array
{
    if (!rp_ready($pdo)) {
        return [];
    }
    try {
        $st = $pdo->prepare('SELECT e.*, u.full_name AS actor_name FROM reception_pipeline_events e LEFT JOIN users u ON u.id = e.actor_user_id WHERE e.applicant_id = ? ORDER BY e.created_at ASC, e.id ASC');
        $st->execute([$applicantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function rp_event_summary(array $e): string
{
    $labels = rp_event_labels();
    $txt = $labels[$e['event']] ?? $e['event'];
    if ($e['event'] === 'invite') {
        $txt .= ' ' . (($e['reason'] ?? '') === 'inperson' ? '(حضوری)' : '(آنلاین)');
        if (!empty($e['meeting_at'])) $txt .= ' برای ' . rp_jdt($e['meeting_at']);
    } elseif ($e['event'] === 'no_show' && !empty($e['reason'])) {
        $txt .= ' — ' . (rp_noshow_reasons()[$e['reason']] ?? $e['reason']);
    } elseif ($e['event'] === 'closed' && !empty($e['reason'])) {
        $txt .= ': ' . (rp_outcomes()[$e['reason']]['label'] ?? $e['reason']);
    } elseif ($e['event'] === 'followup_set' && !empty($e['meeting_at'])) {
        $txt .= ' برای ' . rp_jdt($e['meeting_at']);
    }
    return $txt;
}

/* =========================================================================
   گزارشِ قیف (مدیریت)
   ========================================================================= */

/** نیروهای پذیرش (برای فیلتر و گزارش) */
function rp_agents(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT id, full_name, is_active FROM users
            WHERE service_access_role IN ('reception_agent','reception_admin')
               OR id IN (SELECT DISTINCT assigned_agent_id FROM reception_applicants WHERE assigned_agent_id IS NOT NULL)
            ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $rows = $pdo->query('SELECT id, full_name, 1 AS is_active FROM users WHERE id IN (SELECT DISTINCT assigned_agent_id FROM reception_applicants WHERE assigned_agent_id IS NOT NULL) ORDER BY full_name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            $rows = [];
        }
    }
    return $rows;
}

/**
 * قیف در بازه‌ی زمانی (بر اساسِ رویدادهای ثبت‌شده در بازه)
 * @return array{steps:array, per_agent:array, reasons:array, outcomes:array}
 */
function rp_funnel(PDO $pdo, string $from, string $to, int $agentId = 0): array
{
    $fromDt = $from . ' 00:00:00';
    $toDt = $to . ' 23:59:59';
    $agentIds = $agentId > 0 ? [$agentId] : array_map(static fn($a) => (int) $a['id'], rp_agents($pdo));
    $metrics = ['calls' => 0, 'contacted' => 0, 'invited' => 0, 'followed' => 0, 'confirmed' => 0, 'attended' => 0, 'no_show' => 0, 'decided' => 0, 'joined' => 0];
    $per = [];
    $blank = $metrics + ['advanced' => 0, 'open' => 0, 'overdue' => 0, 'stale' => 0, 'name' => ''];

    // ۱) تماس‌ها (ورودیِ کالیزر) + متقاضیانِ تماس‌گرفته‌شده (ثبتِ تماس در پرونده)
    if ($agentIds) {
        $ph = implode(',', array_fill(0, count($agentIds), '?'));
        try {
            // تماس‌های کالیزر با متقاضی (تماس با مشتری جداست)
            $st = (function_exists('rx_cc_ready') && rx_cc_ready($pdo))
                ? $pdo->prepare("SELECT agent_user_id AS a, COUNT(*) n FROM reception_callizer_calls WHERE agent_user_id IN ($ph) AND call_date >= ? AND call_date <= ? GROUP BY agent_user_id")
                : $pdo->prepare("SELECT created_by AS a, COUNT(*) n FROM followups WHERE source = 'call_import' AND created_by IN ($ph) AND followup_date >= ? AND followup_date <= ? GROUP BY created_by");
            $st->execute(array_merge($agentIds, [$from, $to]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $per[(int) $r['a']]['calls'] = (int) $r['n'];
            }
        } catch (Throwable $e) {
        }
        try {
            $st = $pdo->prepare("SELECT agent_user_id AS a, COUNT(*) n, COUNT(DISTINCT applicant_id) d FROM reception_calls WHERE agent_user_id IN ($ph) AND started_at >= ? AND started_at <= ? GROUP BY agent_user_id");
            $st->execute(array_merge($agentIds, [$fromDt, $toDt]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $a = (int) $r['a'];
                $per[$a]['contacted'] = (int) $r['d'];
                // اگر کالیزر آپلود نشده، تعدادِ تماس از ثبتِ تماسِ پرونده‌ها
                if (empty($per[$a]['calls'])) $per[$a]['calls'] = (int) $r['n'];
            }
        } catch (Throwable $e) {
        }
    }

    // ۲) رویدادهای قیف
    $eventMetric = ['invite' => 'invited', 'confirmed' => 'confirmed', 'attended' => 'attended', 'no_show' => 'no_show', 'closed' => 'decided'];
    try {
        $sql = "SELECT e.agent_user_id AS a, e.event, e.reason, COUNT(DISTINCT e.applicant_id) n
                FROM reception_pipeline_events e
                WHERE e.created_at >= ? AND e.created_at <= ? AND e.event IN ('invite','confirmed','attended','no_show','closed')";
        $params = [$fromDt, $toDt];
        if ($agentId > 0) {
            $sql .= ' AND e.agent_user_id = ?';
            $params[] = $agentId;
        }
        $sql .= ' GROUP BY e.agent_user_id, e.event, e.reason';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $a = (int) $r['a'];
            $m = $eventMetric[$r['event']] ?? null;
            if (!$m) continue;
            if ($m === 'decided' && ($r['reason'] ?? '') === 'archived') continue;
            $per[$a][$m] = ($per[$a][$m] ?? 0) + (int) $r['n'];
            if ($r['event'] === 'closed' && ($r['reason'] ?? '') === 'joined') {
                $per[$a]['joined'] = ($per[$a]['joined'] ?? 0) + (int) $r['n'];
            }
        }
        // «پیگیری/یادآوری‌شده»: متقاضیانِ یکتا با هر کدام از سه رویداد
        $sql = "SELECT e.agent_user_id AS a, COUNT(DISTINCT e.applicant_id) n FROM reception_pipeline_events e
                WHERE e.created_at >= ? AND e.created_at <= ? AND e.event IN ('initial_done','reminded','confirmed')";
        $params = [$fromDt, $toDt];
        if ($agentId > 0) { $sql .= ' AND e.agent_user_id = ?'; $params[] = $agentId; }
        $st = $pdo->prepare($sql . ' GROUP BY e.agent_user_id');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $per[(int) $r['a']]['followed'] = (int) $r['n'];
        }
        // «جلو بردن»: هر حرکتِ رو به جلو (دعوت، تأیید، حضور، پذیرش) توسطِ خودِ نیرو
        $sql = "SELECT e.actor_user_id AS a, COUNT(*) n FROM reception_pipeline_events e
                WHERE e.created_at >= ? AND e.created_at <= ? AND (e.event IN ('invite','confirmed','attended') OR (e.event = 'closed' AND e.reason = 'joined'))";
        $params = [$fromDt, $toDt];
        if ($agentId > 0) { $sql .= ' AND e.actor_user_id = ?'; $params[] = $agentId; }
        $st = $pdo->prepare($sql . ' GROUP BY e.actor_user_id');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $per[(int) $r['a']]['advanced'] = (int) $r['n'];
        }
    } catch (Throwable $e) {
        error_log('rp_funnel: ' . $e->getMessage());
    }

    // ۳) وضعیتِ لحظه‌ایِ هر نیرو: باز، عقب‌افتاده، رهاشده
    try {
        $c = rp_clock($pdo);
        $q = static fn($v) => $pdo->quote($v);
        $sql = "SELECT ra.assigned_agent_id AS a,
                    SUM(CASE WHEN p.stage <> 'closed' THEN 1 ELSE 0 END) AS open_n,
                    SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at < {$q($c['today'])} THEN 1 ELSE 0 END) AS overdue_n,
                    SUM(CASE WHEN p.stage <> 'closed' AND p.next_action_at <= {$q($c['now'])} AND COALESCE(p.last_action_at, p.stage_changed_at) < {$q($c['stale_cut'])} THEN 1 ELSE 0 END) AS stale_n
                FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id
                WHERE ra.assigned_agent_id IS NOT NULL" . ($agentId > 0 ? ' AND ra.assigned_agent_id = ' . (int) $agentId : '') . '
                GROUP BY ra.assigned_agent_id';
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $a = (int) $r['a'];
            $per[$a]['open'] = (int) $r['open_n'];
            $per[$a]['overdue'] = (int) $r['overdue_n'];
            $per[$a]['stale'] = (int) $r['stale_n'];
        }
    } catch (Throwable $e) {
    }

    $names = [];
    foreach (rp_agents($pdo) as $ag) {
        $names[(int) $ag['id']] = (string) $ag['full_name'];
    }
    $perOut = [];
    foreach ($per as $a => $vals) {
        // فقط نیروهای پذیرش (برگزارکننده‌ای که حضور ثبت کرده جزوِ جدولِ نیروها نیست)
        if ($a <= 0 || ($agentId > 0 && $a !== $agentId) || !isset($names[$a])) continue;
        $row = array_merge($blank, $vals);
        $row['name'] = $names[$a] ?? ('#' . $a);
        $row['id'] = $a;
        $perOut[$a] = $row;
        foreach ($metrics as $k => $_) {
            $metrics[$k] += (int) $row[$k];
        }
    }
    uasort($perOut, static fn($x, $y) => ($y['advanced'] <=> $x['advanced']) ?: ($y['invited'] <=> $x['invited']));

    $steps = [
        ['key' => 'calls',     'label' => 'تماس',               'n' => $metrics['calls']],
        ['key' => 'contacted', 'label' => 'متقاضیِ تماس‌گرفته', 'n' => $metrics['contacted']],
        ['key' => 'invited',   'label' => 'دعوت به جلسه',       'n' => $metrics['invited']],
        ['key' => 'followed',  'label' => 'پیگیری / یادآوری',   'n' => $metrics['followed']],
        ['key' => 'confirmed', 'label' => 'تأیید حضور',          'n' => $metrics['confirmed']],
        ['key' => 'attended',  'label' => 'حاضر در جلسه',        'n' => $metrics['attended']],
        ['key' => 'decided',   'label' => 'تعیین تکلیف',         'n' => $metrics['decided']],
        ['key' => 'joined',    'label' => 'پذیرفته / پیوست',     'n' => $metrics['joined']],
    ];

    // دلایلِ عدم حضور و نتایجِ تعیین تکلیف
    $reasons = [];
    $outcomes = [];
    try {
        $sql = "SELECT e.event, e.reason, COUNT(*) n FROM reception_pipeline_events e WHERE e.created_at >= ? AND e.created_at <= ? AND e.event IN ('no_show','closed')";
        $params = [$fromDt, $toDt];
        if ($agentId > 0) { $sql .= ' AND e.agent_user_id = ?'; $params[] = $agentId; }
        $st = $pdo->prepare($sql . ' GROUP BY e.event, e.reason');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if ($r['event'] === 'no_show') {
                $reasons[(string) ($r['reason'] ?: 'unknown')] = (int) $r['n'];
            } elseif (($r['reason'] ?? '') !== 'archived') {
                $outcomes[(string) ($r['reason'] ?: 'other')] = (int) $r['n'];
            }
        }
        arsort($reasons);
        arsort($outcomes);
    } catch (Throwable $e) {
    }

    return ['steps' => $steps, 'metrics' => $metrics, 'per_agent' => $perOut, 'reasons' => $reasons, 'outcomes' => $outcomes];
}

/* =========================================================================
   رقابت و امتیازدهی — بر اساسِ کیفیت (جلو بردنِ افراد در قیف)، نه تعدادِ تماس
   ========================================================================= */

/** قواعدِ امتیاز: تماسِ خالی امتیاز ندارد؛ هر قدمِ جلوتر در قیف امتیازِ بیشتری دارد */
function rp_points_rules(): array
{
    return [
        'invited'   => ['label' => 'دعوت به جلسه',        'points' => 5],
        'followed'  => ['label' => 'پیگیری / یادآوری',     'points' => 1],
        'confirmed' => ['label' => 'تأیید حضور',           'points' => 3],
        'attended'  => ['label' => 'حضورِ متقاضی در جلسه', 'points' => 10],
        'joined'    => ['label' => 'پذیرفته / پیوست',      'points' => 20],
    ];
}

/** بازه‌های رقابت (هفته و ماهِ شمسی) */
function rp_leaderboard_ranges(): array
{
    $today = date('Y-m-d');
    $sinceSat = ((int) date('w') + 1) % 7; // شنبه = شروعِ هفته
    [$jy, $jm] = gregorian_to_jalali_arr((int) date('Y'), (int) date('m'), (int) date('d'));
    $monthStart = to_gregorian(sprintf('%04d/%02d/01', $jy, $jm)) ?: date('Y-m-01');
    return [
        'today' => [$today, $today, 'امروز'],
        'week'  => [date('Y-m-d', strtotime("-$sinceSat days")), $today, 'این هفته'],
        'month' => [$monthStart, $today, 'این ماه'],
    ];
}

/**
 * جدولِ رقابت: همه‌ی نیروهای فعالِ پذیرش با امتیاز، رتبه و قیفِ شخصی.
 * امتیاز به نیروی صاحبِ پرونده می‌رسد (حتی اگر حضور را برگزارکننده ثبت کرده باشد).
 */
function rp_leaderboard(PDO $pdo, string $from, string $to): array
{
    $rules = rp_points_rules();
    $f = rp_funnel($pdo, $from, $to, 0);
    $rows = [];
    foreach (rp_agents($pdo) as $ag) {
        if (isset($ag['is_active']) && !(int) $ag['is_active']) continue;
        $id = (int) $ag['id'];
        $p = $f['per_agent'][$id] ?? ['calls' => 0, 'contacted' => 0, 'invited' => 0, 'followed' => 0, 'confirmed' => 0, 'attended' => 0, 'no_show' => 0, 'decided' => 0, 'joined' => 0];
        $pts = 0;
        foreach ($rules as $k => $r) {
            $pts += (int) ($p[$k] ?? 0) * $r['points'];
        }
        $held = (int) $p['attended'] + (int) $p['no_show'];
        $rows[] = [
            'id' => $id, 'name' => (string) $ag['full_name'], 'points' => $pts,
            'calls' => (int) $p['calls'], 'invited' => (int) $p['invited'], 'followed' => (int) $p['followed'],
            'confirmed' => (int) $p['confirmed'], 'attended' => (int) $p['attended'], 'no_show' => (int) $p['no_show'],
            'decided' => (int) $p['decided'], 'joined' => (int) $p['joined'],
            'att_rate' => $held > 0 ? (int) round(100 * $p['attended'] / $held) : null,
            'inv_rate' => $p['calls'] > 0 ? round(100 * $p['invited'] / $p['calls'], 1) : null,
        ];
    }
    usort($rows, static fn($a, $b) => ($b['points'] <=> $a['points']) ?: ($b['attended'] <=> $a['attended']) ?: ($b['invited'] <=> $a['invited']) ?: strcmp($a['name'], $b['name']));
    $rank = 0;
    $prevPts = null;
    foreach ($rows as $i => &$r) {
        if ($prevPts === null || $r['points'] !== $prevPts) {
            $rank = $i + 1;
        }
        $r['rank'] = $rank;
        $prevPts = $r['points'];
    }
    unset($r);

    // نشان‌ها (فقط وقتی واقعاً فعالیتی هست)
    $badges = [];
    $best = static function (array $rows, string $key, int $min = 1) {
        $top = null;
        foreach ($rows as $r) {
            if (($r[$key] ?? 0) >= $min && ($top === null || $r[$key] > $top[$key])) $top = $r;
        }
        return $top;
    };
    if ($t = $best($rows, 'invited')) $badges[$t['id']][] = ['icon' => 'fa-envelope-open-text', 'label' => 'بیشترین دعوت'];
    if ($t = $best($rows, 'attended')) $badges[$t['id']][] = ['icon' => 'fa-user-check', 'label' => 'بیشترین حضور'];
    if ($t = $best($rows, 'joined')) $badges[$t['id']][] = ['icon' => 'fa-handshake', 'label' => 'بیشترین جذب'];
    $rateTop = null;
    foreach ($rows as $r) {
        if ($r['att_rate'] !== null && ($r['attended'] + $r['no_show']) >= 3 && ($rateTop === null || $r['att_rate'] > $rateTop['att_rate'])) $rateTop = $r;
    }
    if ($rateTop) $badges[$rateTop['id']][] = ['icon' => 'fa-bullseye', 'label' => 'بهترین نرخِ حضور'];

    return ['rows' => $rows, 'badges' => $badges, 'rules' => $rules];
}

/** رتبه و امتیازِ یک نیرو + فاصله تا رتبه‌ی بالاتر */
function rp_my_rank(array $board, int $userId): ?array
{
    $rows = $board['rows'];
    foreach ($rows as $i => $r) {
        if ($r['id'] === $userId) {
            $gap = null;
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($rows[$j]['points'] > $r['points']) { $gap = $rows[$j]['points'] - $r['points']; break; }
            }
            return $r + ['of' => count($rows), 'gap' => $gap];
        }
    }
    return null;
}

/** واگذاریِ مجددِ پرونده‌های باز از یک نیرو به نیروی دیگر (تاریخچه حفظ می‌شود) */
function rp_reassign(PDO $pdo, int $fromAgent, int $toAgent, string $box, int $limit, int $actor): int
{
    if (!rp_ready($pdo) || $fromAgent <= 0 || $toAgent <= 0 || $fromAgent === $toAgent) {
        return 0;
    }
    $c = rp_clock($pdo);
    $where = "ra.assigned_agent_id = ? AND p.stage <> 'closed'";
    if ($box !== '' && $box !== 'all' && isset(rp_boxes()[$box])) {
        $where .= ' AND (' . rp_box_sql($pdo, $c) . ') = ' . $pdo->quote($box);
    } elseif ($box === 'stale') {
        $where .= " AND p.next_action_at <= " . $pdo->quote($c['now']) . " AND COALESCE(p.last_action_at, p.stage_changed_at) < " . $pdo->quote($c['stale_cut']);
    } elseif ($box === 'overdue') {
        $where .= " AND p.next_action_at < " . $pdo->quote($c['today']);
    }
    $limit = max(1, min(5000, $limit));
    $st = $pdo->prepare("SELECT p.applicant_id, p.stage FROM reception_pipeline p JOIN reception_applicants ra ON ra.id = p.applicant_id WHERE $where ORDER BY p.next_action_at ASC LIMIT $limit");
    $st->execute([$fromAgent]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return 0;
    }
    $n = 0;
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE reception_applicants SET assigned_agent_id = ?, assigned_at = ? WHERE id = ? AND assigned_agent_id = ?');
        foreach ($rows as $r) {
            $up->execute([$toAgent, rp_now(), (int) $r['applicant_id'], $fromAgent]);
            if ($up->rowCount() > 0) {
                $n++;
                rp_event($pdo, (int) $r['applicant_id'], $actor, 'reassigned', $r['stage'], $r['stage'], null, 'از #' . $fromAgent . ' به #' . $toAgent);
                if (function_exists('reception_log_action')) {
                    reception_log_action($pdo, (int) $r['applicant_id'], $actor, 'reassign_agent', 'واگذاریِ مجدد از #' . $fromAgent . ' به #' . $toAgent);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('rp_reassign: ' . $e->getMessage());
        return 0;
    }
    return $n;
}
