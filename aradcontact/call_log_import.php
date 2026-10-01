<?php
// ===== لاگِ موقتِ عیب‌یابی — بعد از پیداکردنِ مشکل حتماً این تابع و کالِ‌هاش حذف بشن =====
function __dbg(string $msg): void
{
    @file_put_contents(__DIR__ . '/call_import_debug.log', date('c') . ' ' . $msg . ' | mem=' . round(memory_get_usage(true) / 1048576, 1) . 'MB peak=' . round(memory_get_peak_usage(true) / 1048576, 1) . "MB\n", FILE_APPEND);
}
__dbg('--- request start --- method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' action=' . ($_POST['action'] ?? $_GET['continue_import'] ?? '-'));
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        __dbg('SHUTDOWN last_error type=' . $e['type'] . ' msg=' . $e['message'] . ' at ' . $e['file'] . ':' . $e['line']);
    } else {
        __dbg('SHUTDOWN clean (no error_get_last)');
    }
});
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/spreadsheet_reader.php';
require_once __DIR__ . '/includes/call_conference_functions.php';

if (!function_exists('__call_import_phone_normalize')) {
    function __call_import_phone_normalize($phone) {
        $raw = normalize_digits((string) $phone);
        $norm = normalize_phone_for_match($raw);
        if ($norm !== null) {
            return $norm;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) { return null; }
        if (substr($digits, 0, 4) === '0098') {
            $digits = substr($digits, 4);
        } elseif (substr($digits, 0, 2) === '98' && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') { return null; }
        return '0' . $digits;
    }
}

$user = require_login();
$pdo  = db();
try { contact_type_backfill_v1($pdo); } catch (Throwable $e) {} // یک‌بار: اصلاحِ همکار/خانواده‌هایی که «مشتری» مانده‌اند
__dbg('after requires+login, user_id=' . ($user['id'] ?? '?') . ' role=' . ($user['role'] ?? '?'));

@set_time_limit(240);
@ini_set('max_execution_time', '240');
@ini_set('memory_limit', '512M');

$SESS_UPLOAD   = 'call_import_data';
$SESS_NEWSTEP  = 'call_import_new_step';
$SESS_MATCHED  = 'call_import_matched_step';
$SESS_PROGRESS = 'call_import_progress';
$MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
$MAX_IMPORT_ROWS = 20000;
$NEW_LEAD_FOLLOWUP_DAYS = 3;
$step = 'upload';
$errors = [];
$result = null;

if (isset($_GET['continue_import']) && !empty($_SESSION[$SESS_PROGRESS])) {
    $ctx = $_SESSION[$SESS_PROGRESS]['ctx'];
    session_write_close();
    $cont = __call_import_continue($pdo, $user, $ctx);
    session_start();
    if ($cont['done']) {
        unset($_SESSION[$SESS_PROGRESS]);
        $out = $cont['out'];
        if ($out['new_candidates']) {
            $_SESSION[$SESS_NEWSTEP] = ['candidates' => $out['new_candidates'], 'stats' => $out['stats']];
            $step = 'new_customers';
        } else {
            $result = $out['stats'];
            $step = 'result';
        }
    } else {
        $_SESSION[$SESS_PROGRESS] = ['ctx' => $cont['ctx']];
        $step = 'processing';
    }
}

function __call_import_existing_globally(PDO $pdo, array $keys): array
{
    $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => $k !== ''));
    if (!$keys) return [];
    $found = [];
    foreach (array_chunk($keys, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        try {
            if (customer_phone_normalized_ready($pdo)) {
                $st = $pdo->prepare("SELECT c.id, c.full_name, c.mobile_normalized AS n1, c.mobile2_normalized AS n2, u.full_name AS owner_name
                                     FROM customers c LEFT JOIN users u ON u.id = c.owner_user_id
                                     WHERE c.mobile_normalized IN ($ph) OR c.mobile2_normalized IN ($ph)");
                $st->execute(array_merge($chunk, $chunk));
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    foreach (['n1', 'n2'] as $col) {
                        if ($r[$col] !== null && in_array($r[$col], $chunk, true) && !isset($found[$r[$col]])) {
                            $found[$r[$col]] = ['id' => (int) $r['id'], 'name' => (string) $r['full_name'], 'owner' => (string) ($r['owner_name'] ?? '')];
                        }
                    }
                }
            }
            if (customer_relations_ready($pdo)) {
                $st = $pdo->prepare("SELECT p.phone_normalized AS n, c.id, c.full_name, u.full_name AS owner_name
                                     FROM customer_phones p JOIN customers c ON c.id = p.customer_id LEFT JOIN users u ON u.id = c.owner_user_id
                                     WHERE p.phone_normalized IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    if (!isset($found[$r['n']])) {
                        $found[$r['n']] = ['id' => (int) $r['id'], 'name' => (string) $r['full_name'], 'owner' => (string) ($r['owner_name'] ?? '')];
                    }
                }
            }
        } catch (Throwable $e) {}
    }
    return $found;
}

function __call_import_staff_options(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE role != 'admin' AND is_active = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function __call_import_row_time(array $row, ?int $dateCol, ?int $timeCol): ?string
{
    $rawDate = $dateCol !== null ? (string) ($row[$dateCol] ?? '') : '';
    $t = $rawDate !== '' ? parse_row_time_from_string($rawDate) : null;
    if ($t === null && $timeCol !== null) {
        $rawTime = trim((string) ($row[$timeCol] ?? ''));
        if ($rawTime !== '') {
            $t = parse_row_time_from_string($rawTime);
        }
    }
    return $t;
}

function __call_import_min_sec(): int
{
    return defined('STAFF_REPORT_CONNECTED_MIN') ? (int) STAFF_REPORT_CONNECTED_MIN : 10;
}

function __call_import_recon_add(array &$s, int $seconds, int $ownerId, string $date): void
{
    if (!isset($s['recon'])) {
        $s['recon'] = ['short_seconds' => 0, 'short_count' => 0, 'owner' => [], 'date' => []];
    }
    if ($seconds <= 0) return;
    if ($seconds <= __call_import_min_sec()) {
        $s['recon']['short_seconds'] += $seconds;
        $s['recon']['short_count']++;
        return;
    }
    $s['seconds_by_contact_type']['customer'] = ($s['seconds_by_contact_type']['customer'] ?? 0) + $seconds;
    $s['recon']['owner'][$ownerId] = ($s['recon']['owner'][$ownerId] ?? 0) + $seconds;
    $s['recon']['date'][$date] = ($s['recon']['date'][$date] ?? 0) + $seconds;
}

function __call_import_uploader_relation(PDO $pdo, int $customerId, int $userId, array &$cache): ?int
{
    $key = $customerId . ':' . $userId;
    if (!array_key_exists($key, $cache)) {
        $rel = get_or_create_relation($pdo, $customerId, $userId, 'call_import', false);
        $cache[$key] = isset($rel['id']) ? (int) $rel['id'] : null;
    }
    return $cache[$key];
}

function __call_import_is_placeholder_name(?string $name, ?string $phone = null): bool
{
    $name = trim((string) $name);
    if ($name === '' || strcasecmp($name, 'unknown') === 0) return true;
    if ($phone !== null && $phone !== '') {
        $nameDigits = preg_replace('/\D+/', '', $name);
        $phoneDigits = preg_replace('/\D+/', '', (string) $phone);
        if ($nameDigits !== '' && $phoneDigits !== '') {
            $nameNorm = __call_import_phone_normalize($nameDigits);
            $phoneNorm = __call_import_phone_normalize($phoneDigits);
            if ($nameNorm !== null && $phoneNorm !== null && $nameNorm === $phoneNorm) return true;
        }
    }
    return false;
}

function __call_import_pick_customer(array $candidates, int $effectiveUserId): array
{
    foreach ($candidates as $idx => $c) {
        if ((int) ($c['owner_user_id'] ?? -1) === $effectiveUserId) {
            return [$idx, $c];
        }
    }
    return [0, $candidates[0]];
}

function __call_import_scan(array $rows, array $phoneMap, ?int $phoneCol, ?int $nameCol, ?int $durationCol, ?int $dateCol, ?int $statusCol, int $effectiveUserId, ?int $timeCol = null): array
{
    $newCandidates = [];
    $matchedPreview = [];
    foreach ($rows as $row) {
        $rawPhone = trim((string) ($row[$phoneCol] ?? ''));
        $normPhone = $rawPhone !== '' ? __call_import_phone_normalize($rawPhone) : null;
        if ($normPhone === null) continue;
        if (empty($phoneMap[$normPhone])) {
            $rawName = $nameCol !== null ? trim((string) ($row[$nameCol] ?? '')) : '';
            if (__call_import_is_placeholder_name($rawName, $rawPhone)) $rawName = $normPhone;
            $rawDuration = $durationCol !== null ? (string) ($row[$durationCol] ?? '') : '';
            $durationSec = parse_call_duration_to_seconds($rawDuration);
            $rawStatus = $statusCol !== null ? (string) ($row[$statusCol] ?? '') : null;
            $connected = infer_call_connected($rawStatus, $durationSec);
            $rawDate = $dateCol !== null ? (string) ($row[$dateCol] ?? '') : '';
            $followupDateG = $rawDate !== '' ? parse_row_date_to_gregorian($rawDate) : null;
            if (!$followupDateG) $followupDateG = date('Y-m-d');
            $eventTime = __call_import_row_time($row, $dateCol, $timeCol);
            if (!isset($newCandidates[$normPhone])) {
                $newCandidates[$normPhone] = ['raw_phone' => (__call_import_phone_normalize($rawPhone) ?: $rawPhone), 'name' => $rawName, 'occurrences' => []];
            } elseif ($newCandidates[$normPhone]['name'] === '' && $rawName !== '') {
                $newCandidates[$normPhone]['name'] = $rawName;
            }
            $newCandidates[$normPhone]['occurrences'][] = [
                'date'        => $followupDateG,
                'time'        => $eventTime,
                'duration'    => $connected ? $durationSec : null,
                'connected'   => $connected,
                'description' => build_call_import_description($connected, $durationSec),
            ];
            continue;
        }
        [, $customer] = __call_import_pick_customer($phoneMap[$normPhone], $effectiveUserId);
        $cid = (int) $customer['id'];
        if (!isset($matchedPreview[$cid])) {
            $matchedPreview[$cid] = ['customer' => $customer, 'count' => 0];
        }
        $matchedPreview[$cid]['count']++;
    }
    return ['new_candidates' => $newCandidates, 'matched_preview' => $matchedPreview];
}

if (isset($_GET['cancel'])) {
    unset($_SESSION[$SESS_UPLOAD], $_SESSION[$SESS_NEWSTEP], $_SESSION[$SESS_MATCHED], $_SESSION[$SESS_PROGRESS]);
    redirect('call_log_import.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    unset($_SESSION[$SESS_NEWSTEP]);
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } elseif (empty($_FILES['call_file']) || $_FILES['call_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'لطفا یک فایل اکسل یا CSV انتخاب کنید.';
    } elseif ($_FILES['call_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'خطا در آپلود فایل. دوباره تلاش کنید.';
    } elseif ($_FILES['call_file']['size'] > $MAX_UPLOAD_BYTES) {
        $errors[] = 'حجم فایل بیش از حد مجاز (۵۰ مگابایت) است.';
    } else {
        $parseError = null;
        $rows = read_uploaded_spreadsheet($_FILES['call_file']['tmp_name'], $_FILES['call_file']['name'], $parseError);
        if ($rows === null) {
            $errors[] = $parseError ?: 'خواندن فایل ممکن نشد.';
        } elseif (count($rows) < 1) {
            $errors[] = 'فایل خالی است.';
        } elseif (count($rows) > $MAX_IMPORT_ROWS) {
            $errors[] = 'تعداد ردیف‌های فایل (' . to_persian_digits((string) count($rows)) . ') بیش از حد مجاز (' . to_persian_digits((string) $MAX_IMPORT_ROWS) . ' ردیف) است.';
        } else {
            $hasHeader = !isset($_POST['no_header']);
            $header = $hasHeader ? array_shift($rows) : null;
            if (!$rows) {
                $errors[] = 'هیچ ردیف داده‌ای در فایل یافت نشد.';
            } else {
                $colCount = count($rows[0]);
                if (!$header) {
                    $header = [];
                    for ($i = 0; $i < $colCount; $i++) {
                        $header[] = 'ستون ' . to_persian_digits((string) ($i + 1));
                    }
                }
                $keywords = call_log_column_keywords();
                $dateColAuto = $hasHeader ? (
                    (($dateTimeIdx = array_search('Date Time', $header, true)) !== false)
                        ? $dateTimeIdx
                        : detect_call_log_column($header, $keywords['date'])
                ) : null;
                $durationColAuto = $hasHeader ? detect_call_duration_column($header) : null;
                $timeColAuto = null;
                if ($hasHeader) {
                    foreach ($header as $idx => $h) {
                        if ($idx === $dateColAuto || $idx === $durationColAuto) continue;
                        if (preg_match('/duration|talk ?time|مدت/iu', (string) $h)) continue;
                        $hNorm = mb_strtolower(trim((string) $h));
                        if ($hNorm === '') continue;
                        foreach (['ساعت', 'زمان', 'time'] as $kw) {
                            if (mb_strpos($hNorm, mb_strtolower($kw)) !== false) {
                                $timeColAuto = $idx;
                                break 2;
                            }
                        }
                    }
                }
                $autoMap = [
                    'phone'    => $hasHeader ? detect_phone_column($header) : 0,
                    'duration' => $durationColAuto,
                    'date'     => $dateColAuto,
                    'time'     => $timeColAuto,
                    'status'   => $hasHeader ? detect_call_log_column($header, $keywords['status']) : null,
                    'name'     => $hasHeader ? detect_call_log_column($header, $keywords['name']) : null,
                ];

                $_SESSION[$SESS_UPLOAD] = [
                    'header'      => $header,
                    'rows'        => $rows,
                    'auto_map'    => $autoMap,
                    'file_name'   => $_FILES['call_file']['name'],
                    'uploaded_at' => time(),
                ];
                redirect('call_log_import.php');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } elseif (empty($_SESSION[$SESS_UPLOAD])) {
        $errors[] = 'اطلاعات فایل آپلودشده در دسترس نیست؛ لطفا دوباره فایل را آپلود کنید.';
    } else {
        __dbg('action=process start');
        $data = $_SESSION[$SESS_UPLOAD];
        $rows = $data['rows'];
        __dbg('action=process rows_count=' . count($rows));

        $phoneCol    = isset($_POST['phone_col']) && $_POST['phone_col'] !== '' ? (int) $_POST['phone_col'] : null;
        $durationCol = isset($_POST['duration_col']) && $_POST['duration_col'] !== '' ? (int) $_POST['duration_col'] : null;
        $dateCol     = isset($_POST['date_col']) && $_POST['date_col'] !== '' ? (int) $_POST['date_col'] : null;
        $timeCol     = isset($_POST['time_col']) && $_POST['time_col'] !== '' ? (int) $_POST['time_col'] : null;
        $statusCol   = isset($_POST['status_col']) && $_POST['status_col'] !== '' ? (int) $_POST['status_col'] : null;
        $nameCol     = isset($_POST['name_col']) && $_POST['name_col'] !== '' ? (int) $_POST['name_col'] : null;
        $scopeAll    = ($user['role'] === 'admin') && isset($_POST['scope_all']);

        $actAsUserId = null;
        if ($user['role'] === 'admin' && !empty($_POST['act_as_user_id'])) {
            $candidateId = (int) $_POST['act_as_user_id'];
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role != 'admin' AND is_active = 1 LIMIT 1");
            $chk->execute([$candidateId]);
            if ($chk->fetchColumn()) $actAsUserId = $candidateId;
        }

        if ($phoneCol === null) {
            $errors[] = 'انتخاب ستون «شماره موبایل» الزامی است.';
        }

        if (!$errors) {
            __dbg('before build_phone_match_map scope_all=' . ($scopeAll ? '1' : '0') . ' act_as=' . ($actAsUserId ?? 'null'));
            $phoneMap = build_phone_match_map($pdo, $scopeAll, (int) ($actAsUserId ?? $user['id']));
            __dbg('after build_phone_match_map phoneMap_keys=' . count($phoneMap));

            $scan = __call_import_scan($rows, $phoneMap, $phoneCol, $nameCol, $durationCol, $dateCol, $statusCol, $actAsUserId ?? (int) $user['id'], $timeCol);
            __dbg('after __call_import_scan matched=' . count($scan['matched_preview']) . ' new=' . count($scan['new_candidates']));

            $_SESSION[$SESS_MATCHED] = [
                'rows' => $rows,
                'phone_col' => $phoneCol, 'duration_col' => $durationCol, 'date_col' => $dateCol,
                'time_col' => $timeCol,
                'status_col' => $statusCol, 'name_col' => $nameCol, 'scope_all' => $scopeAll,
                'act_as_user_id' => $actAsUserId,
                'new_candidates' => $scan['new_candidates'],
            ];

            if ($scan['matched_preview']) {
                $matchedForDisplay = [];
                foreach ($scan['matched_preview'] as $cid => $info) {
                    $matchedForDisplay[$cid] = [
                        'full_name' => $info['customer']['full_name'],
                        'mobile' => $info['customer']['mobile'],
                        'status' => $info['customer']['status'],
                        'count' => $info['count'],
                    ];
                }
                $_SESSION[$SESS_MATCHED]['matched_preview'] = $matchedForDisplay;
                unset($_SESSION[$SESS_UPLOAD]);
                $step = 'matched_customers';
                __dbg('=> step matched_customers');
            } else {
                unset($_SESSION[$SESS_UPLOAD]);
                $matchedDataForFinalize = $_SESSION[$SESS_MATCHED];
                unset($_SESSION[$SESS_MATCHED]);
                __dbg('direct finalize begin, new_candidates=' . count($matchedDataForFinalize['new_candidates'] ?? []));
                session_write_close();
                $begin = __call_import_begin($pdo, $user, $matchedDataForFinalize, []);
                __dbg('direct finalize begin done=' . ($begin['done'] ? '1' : '0'));
                session_start();
                if ($begin['done']) {
                    $out = $begin['out'];
                    if ($out['new_candidates']) {
                        $_SESSION[$SESS_NEWSTEP] = ['candidates' => $out['new_candidates'], 'stats' => $out['stats']];
                        $step = 'new_customers';
                    } else {
                        $result = $out['stats'];
                        $step = 'result';
                    }
                } else {
                    $_SESSION[$SESS_PROGRESS] = ['ctx' => $begin['ctx']];
                    $step = 'processing';
                }
            }
        }
    }
    if ($errors && !empty($_SESSION[$SESS_UPLOAD])) {
        $step = 'map';
    }
}

function __call_import_prepare_ctx(PDO $pdo, array $user, array $stepData, array $overrides): array
{
    __dbg('prepare_ctx enter rows=' . count($stepData['rows'] ?? []));
    $rows = $stepData['rows'];
    $phoneCol = $stepData['phone_col'];
    $durationCol = $stepData['duration_col'];
    $dateCol = $stepData['date_col'];
    $timeCol = $stepData['time_col'] ?? null;
    $statusCol = $stepData['status_col'];
    $nameCol = $stepData['name_col'] ?? null;
    $scopeAll = $stepData['scope_all'];
    $actAsUserId = $stepData['act_as_user_id'] ?? null;
    $newCandidates = $stepData['new_candidates'];

    $phoneMap = build_phone_match_map($pdo, $scopeAll, (int) ($actAsUserId ?? $user['id']));

    $statusOptionsForOverride = status_options_for_role($user['role']);
    if ($overrides) {
        $updStatusStmt = $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?');
        foreach ($overrides as $cid => $newStatus) {
            $cid = (int) $cid;
            $newStatus = trim((string) $newStatus);
            if ($newStatus === '' || $newStatus === 'جلسه برگزار شد' || !in_array($newStatus, $statusOptionsForOverride, true)) {
                continue;
            }
            $updStatusStmt->execute([$newStatus, $cid]);
            foreach ($phoneMap as $norm => $matches) {
                foreach ($matches as $idx => $m) {
                    if ((int) $m['id'] === $cid) {
                        $phoneMap[$norm][$idx]['status'] = $newStatus;
                    }
                }
            }
        }
    }

    $conferenceOverride = [];
    $conferenceSkip = [];

    return [
        'rows' => $rows,
        'phone_col' => $phoneCol,
        'duration_col' => $durationCol,
        'date_col' => $dateCol,
        'time_col' => $timeCol,
        'status_col' => $statusCol,
        'name_col' => $nameCol,
        'scope_all' => $scopeAll,
        'act_as_user_id' => $actAsUserId,
        'new_candidates' => $newCandidates,
        'phone_map' => $phoneMap,
        'conference_override' => $conferenceOverride,
        'conference_skip' => $conferenceSkip,
        'cc_batch' => 'call_import-' . $user['id'] . '-' . time(),
        'offset' => 0,
        'total' => count($rows),
        'relation_cache' => [],
        'phone_id_cache' => [],
        'stats' => [
            'total_rows' => 0, 'invalid_phone' => 0, 'matched_customers' => 0,
            'ambiguous_phone' => 0, 'inserted' => 0, 'duplicate' => 0,
            'connected' => 0, 'not_connected' => 0, 'name_fixed' => 0, 'duration_fixed' => 0,
            'seconds_by_contact_type' => ['customer' => 0, 'family' => 0, 'colleague' => 0],
            'cc_min_date' => null, 'cc_max_date' => null,
        ],
        'done' => count($rows) === 0,
    ];
}

function __call_import_run_chunk(PDO $pdo, array $user, array &$ctx, int $maxRows = 3000, int $maxSeconds = 15): bool
{
    __dbg('run_chunk enter offset=' . ($ctx['offset'] ?? '?') . ' total=' . ($ctx['total'] ?? '?'));
    $rows = $ctx['rows'];
    $phoneCol = $ctx['phone_col'];
    $durationCol = $ctx['duration_col'];
    $dateCol = $ctx['date_col'];
    $timeCol = $ctx['time_col'] ?? null;
    $statusCol = $ctx['status_col'];
    $nameCol = $ctx['name_col'];
    $effectiveUserId = $ctx['act_as_user_id'] ?? (int) $user['id'];
    $conferenceOverride = $ctx['conference_override'];
    $conferenceSkip = $ctx['conference_skip'];
    $phoneMap = $ctx['phone_map'];
    $relationIdByCustomer = $ctx['relation_cache'];
    $phoneIdCache = $ctx['phone_id_cache'];

    $ccReady = false;
    $ccGlobalCustomerMap = [];
    $ccStaffPhoneMap = [];
    $ccBatch = $ctx['cc_batch'];

    $dirReady = call_direction_ready($pdo);
    $nameFixStmt = $pdo->prepare('UPDATE customers SET full_name = ? WHERE id = ?');

    // ⭐⭐⭐ INSERT با ستون‌های denormalized (contact_type, is_phone_call)
    $insStmt = $pdo->prepare('INSERT INTO followups
        (customer_id, customer_phone_id, relation_id, followup_number, followup_date, event_time, description, status_after, next_followup_date, call_duration_seconds' . ($dirReady ? ', call_direction' : '') . ', source, created_by, contact_type, is_phone_call)
        VALUES (?,?,?,?,?,?,?,?,?,?' . ($dirReady ? ',?' : '') . ',\'call_import\',?,?,?)');

    $dupStmt = $pdo->prepare('SELECT id FROM followups WHERE customer_id = ? AND source = \'call_import\' AND followup_date = ? AND call_duration_seconds <=> ? AND created_by IN (?, ?) LIMIT 1');
    $dupTimeStmt = $pdo->prepare('SELECT id, call_duration_seconds, description, created_by FROM followups WHERE customer_id = ? AND source = \'call_import\' AND followup_date = ? AND event_time = ? AND created_by IN (?, ?) ORDER BY (created_by = ?) DESC LIMIT 1');
    $dupLegacyStmt = $pdo->prepare('SELECT id FROM followups WHERE customer_id = ? AND source = \'call_import\' AND followup_date = ? AND event_time IS NULL AND call_duration_seconds <=> ? AND created_by IN (?, ?) LIMIT 1');
    $fixDurStmt = $pdo->prepare('UPDATE followups SET call_duration_seconds = ?, description = ? WHERE id = ?');
    $fixOwnerStmt = $pdo->prepare('UPDATE followups SET created_by = ?, relation_id = COALESCE(?, relation_id) WHERE id = ?');
    $updStmt = $pdo->prepare('UPDATE customers SET followup_count = followup_count + 1 WHERE id = ?');
    $relationsReady = customer_relations_ready($pdo);

    $total = $ctx['total'];
    $s = &$ctx['stats'];

    $pdo->beginTransaction();
    ignore_user_abort(true);
    $txBatchSize = 2000;
    $txCounter = 0;
    $processedInChunk = 0;
    $chunkStart = microtime(true);

    $i = $ctx['offset'];
    for (; $i < $total; $i++) {
        if ($processedInChunk >= $maxRows || (microtime(true) - $chunkStart) >= $maxSeconds) break;
        $processedInChunk++;

        $txCounter++;
        if ($txCounter % $txBatchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            if (connection_aborted()) break;
        }

        $rowIdx = $i;
        $row = $rows[$rowIdx];
        $s['total_rows']++;

        if (isset($conferenceSkip[$rowIdx])) continue;

        $rawPhone = trim((string) ($row[$phoneCol] ?? ''));
        $normPhone = $rawPhone !== '' ? __call_import_phone_normalize($rawPhone) : null;

        if ($normPhone === null) {
            $s['invalid_phone']++;
            continue;
        }

        $rawDuration = $durationCol !== null ? (string) ($row[$durationCol] ?? '') : '';
        $durationSec = parse_call_duration_to_seconds($rawDuration);

        $rawStatus = $statusCol !== null ? (string) ($row[$statusCol] ?? '') : null;
        $connected = infer_call_connected($rawStatus, $durationSec);
        $callDirection = $dirReady ? infer_call_direction($rawStatus) : null;

        $rawDate = $dateCol !== null ? (string) ($row[$dateCol] ?? '') : '';
        $followupDateG = $rawDate !== '' ? parse_row_date_to_gregorian($rawDate) : null;
        if (!$followupDateG) $followupDateG = date('Y-m-d');
        $eventTime = __call_import_row_time($row, $dateCol, $timeCol);

        $description = build_call_import_description($connected, $durationSec);

        if (isset($conferenceOverride[$rowIdx])) {
            $ov = $conferenceOverride[$rowIdx];
            $durationSec = $ov['duration'];
            $eventTime = date('H:i:s', $ov['start']);
            $followupDateG = date('Y-m-d', $ov['start']);
            $connected = true;
            $description = build_call_import_description(true, $durationSec) . ' ' . $ov['note'];
        }

        if ($ccReady) {
            if ($s['cc_min_date'] === null || $followupDateG < $s['cc_min_date']) { $s['cc_min_date'] = $followupDateG; }
            if ($s['cc_max_date'] === null || $followupDateG > $s['cc_max_date']) { $s['cc_max_date'] = $followupDateG; }
            call_conference_capture_row($pdo, $effectiveUserId, $rawPhone, $normPhone, $ccGlobalCustomerMap, $ccStaffPhoneMap, $followupDateG, $eventTime, $durationSec, $connected, $ccBatch);
        }

        if (empty($phoneMap[$normPhone])) continue;

        $connected ? $s['connected']++ : $s['not_connected']++;

        [$custIdx, $customer] = __call_import_pick_customer($phoneMap[$normPhone], $effectiveUserId);
        if (count($phoneMap[$normPhone]) > 1) $s['ambiguous_phone']++;
        $s['matched_customers']++;

        if ($nameCol !== null && __call_import_is_placeholder_name((string) ($customer['full_name'] ?? ''), $rawPhone)) {
            $rawName = trim((string) ($row[$nameCol] ?? ''));
            if ($rawName !== '' && !__call_import_is_placeholder_name($rawName, $rawPhone)) {
                $nameFixStmt->execute([$rawName, $customer['id']]);
                $customer['full_name'] = $rawName;
                $phoneMap[$normPhone][$custIdx]['full_name'] = $rawName;
                $s['name_fixed']++;
            }
        }

        // ⭐ محاسبه‌ی ستون‌های denormalized
        $__ct = (string) ($customer['contact_type'] ?? 'customer');
        if (!in_array($__ct, ['customer', 'family', 'colleague'], true)) $__ct = 'customer';
        $__isPhoneCall = ($__ct === 'customer' && $connected && (int) $durationSec > 10) ? 1 : 0;

        if ($connected) {
            $ct = $customer['contact_type'] ?? 'customer';
            if (!isset($s['seconds_by_contact_type'][$ct])) $ct = 'customer';
            if ($ct === 'customer') {
                __call_import_recon_add($s, (int) $durationSec, (int) $effectiveUserId, (string) $followupDateG);
            } elseif ($durationSec > __call_import_min_sec()) {
                $s['seconds_by_contact_type'][$ct] += $durationSec;
            }
        }

        $storedDuration = $connected ? $durationSec : null;
        $ownerId = (int) $customer['owner_user_id'];
        if ($eventTime !== null && $eventTime !== '') {
            $dupTimeStmt->execute([$customer['id'], $followupDateG, $eventTime, $effectiveUserId, $ownerId, $effectiveUserId]);
            $existing = $dupTimeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $dupLegacyStmt->execute([$customer['id'], $followupDateG, $storedDuration, $effectiveUserId, $ownerId]);
                $existing = $dupLegacyStmt->fetch(PDO::FETCH_ASSOC) ? ['id' => 0] : null;
            }
            if ($existing) {
                $changed = false;
                $oldDur = array_key_exists('call_duration_seconds', $existing) && $existing['call_duration_seconds'] !== null ? (int) $existing['call_duration_seconds'] : null;
                if ((int) $existing['id'] > 0 && $oldDur !== $storedDuration) {
                    $fixDurStmt->execute([$storedDuration, $description, (int) $existing['id']]);
                    $s['duration_fixed']++;
                    $changed = true;
                }
                if ((int) $existing['id'] > 0 && (int) ($existing['created_by'] ?? 0) !== (int) $effectiveUserId) {
                    $relFix = $relationsReady ? __call_import_uploader_relation($pdo, (int) $customer['id'], (int) $effectiveUserId, $relationIdByCustomer) : null;
                    $fixOwnerStmt->execute([(int) $effectiveUserId, $relFix, (int) $existing['id']]);
                    $s['owner_fixed'] = ($s['owner_fixed'] ?? 0) + 1;
                    $changed = true;
                }
                if (!$changed) $s['duplicate']++;
                continue;
            }
        } else {
            $dupStmt->execute([$customer['id'], $followupDateG, $storedDuration, $effectiveUserId, $ownerId]);
            if ($dupStmt->fetch()) {
                $s['duplicate']++;
                continue;
            }
        }

        $nextFollowupNumber = (int) $customer['followup_count'] + 1;

        $relationId = null;
        $phoneId = null;
        if ($relationsReady) {
            $cid = (int) $customer['id'];
            $relationId = __call_import_uploader_relation($pdo, $cid, (int) $effectiveUserId, $relationIdByCustomer);

            $phoneCacheKey = $cid . ':' . $normPhone;
            if (!array_key_exists($phoneCacheKey, $phoneIdCache)) {
                $phoneLookup = $pdo->prepare('SELECT id FROM customer_phones WHERE customer_id = ? AND phone_normalized = ? LIMIT 1');
                $phoneLookup->execute([$cid, $normPhone]);
                $phoneIdCache[$phoneCacheKey] = $phoneLookup->fetchColumn() ?: null;
            }
            $phoneId = $phoneIdCache[$phoneCacheKey];
        }

        // ⭐ execute با دو مقدار جدید
        $insStmt->execute(array_merge([
            $customer['id'],
            $phoneId,
            $relationId,
            $nextFollowupNumber,
            $followupDateG,
            $eventTime,
            $description,
            $customer['status'],
            null,
            $connected ? $durationSec : null,
        ], $dirReady ? [$callDirection] : [], [
            $effectiveUserId,
            $__ct,
            $__isPhoneCall,
        ]));
        $updStmt->execute([$customer['id']]);
        if ($connected) {
            try { require_once __DIR__ . '/includes/performance_functions.php'; ps_note_interaction($pdo, (int) $customer['id'], (int) $effectiveUserId, 'calizer'); } catch (Throwable $e) {}
        }
        $phoneMap[$normPhone][$custIdx]['followup_count'] = $nextFollowupNumber;
        $s['inserted']++;
    }
    $pdo->commit();

    $ctx['offset'] = $i;
    $ctx['phone_map'] = $phoneMap;
    $ctx['relation_cache'] = $relationIdByCustomer;
    $ctx['phone_id_cache'] = $phoneIdCache;
    $ctx['done'] = ($i >= $total);

    return $ctx['done'];
}

function __call_import_ctx_to_stats(array $ctx): array
{
    $s = $ctx['stats'];
    $s['scope_all'] = $ctx['scope_all'];
    $s['act_as_user_id'] = $ctx['act_as_user_id'] ?? null;
    $s['conference_calls'] = count($ctx['conference_override']);
    $s['conference_legs_skipped'] = count($ctx['conference_skip']);
    $s['created'] = 0;
    $s['created_skipped'] = 0;
    return $s;
}

function __call_import_begin(PDO $pdo, array $user, array $stepData, array $overrides): array
{
    $ctx = __call_import_prepare_ctx($pdo, $user, $stepData, $overrides);
    $done = __call_import_run_chunk($pdo, $user, $ctx);
    if ($done) {
        $out = ['stats' => __call_import_ctx_to_stats($ctx), 'new_candidates' => $ctx['new_candidates']];
        if (!empty($out['stats']['cc_min_date'])) {
            try { call_conference_reprocess_range($pdo, $out['stats']['cc_min_date'], $out['stats']['cc_max_date']); } catch (Throwable $e) {}
        }
        return ['done' => true, 'out' => $out];
    }
    return ['done' => false, 'ctx' => $ctx];
}

function __call_import_continue(PDO $pdo, array $user, array $ctx): array
{
    $done = __call_import_run_chunk($pdo, $user, $ctx);
    if ($done) {
        $out = ['stats' => __call_import_ctx_to_stats($ctx), 'new_candidates' => $ctx['new_candidates']];
        if (!empty($out['stats']['cc_min_date'])) {
            try { call_conference_reprocess_range($pdo, $out['stats']['cc_min_date'], $out['stats']['cc_max_date']); } catch (Throwable $e) {}
        }
        return ['done' => true, 'out' => $out];
    }
    return ['done' => false, 'ctx' => $ctx];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize_matched') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } elseif (empty($_SESSION[$SESS_MATCHED])) {
        $errors[] = 'اطلاعات این مرحله در دسترس نیست؛ لطفا دوباره فایل را آپلود کنید.';
    } else {
        $overrides = json_decode((string) ($_POST['status_overrides'] ?? '{}'), true);
        if (!is_array($overrides)) $overrides = [];
        $matchedDataForFinalize = $_SESSION[$SESS_MATCHED];
        unset($_SESSION[$SESS_MATCHED]);
        session_write_close();
        $begin = __call_import_begin($pdo, $user, $matchedDataForFinalize, $overrides);
        session_start();
        if ($begin['done']) {
            $out = $begin['out'];
            if ($out['new_candidates']) {
                $_SESSION[$SESS_NEWSTEP] = ['candidates' => $out['new_candidates'], 'stats' => $out['stats']];
                $step = 'new_customers';
            } else {
                $result = $out['stats'];
                $step = 'result';
            }
        } else {
            $_SESSION[$SESS_PROGRESS] = ['ctx' => $begin['ctx']];
            $step = 'processing';
        }
    }
    if ($errors && !empty($_SESSION[$SESS_MATCHED])) $step = 'matched_customers';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_new') {
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } elseif (empty($_SESSION[$SESS_NEWSTEP])) {
        $errors[] = 'اطلاعات مشتریان جدید در دسترس نیست؛ لطفا دوباره فایل را آپلود کنید.';
    } else {
        $stepData = $_SESSION[$SESS_NEWSTEP];
        $candidates = $stepData['candidates'];
        $stats = $stepData['stats'];
        $doAction = $_POST['do'] ?? 'create';
        $effectiveUserId = $stats['act_as_user_id'] ?? (int) $user['id'];

        $payload = json_decode((string) ($_POST['payload'] ?? '[]'), true);
        if (!is_array($payload)) $payload = [];
        $payloadByPhone = [];
        foreach ($payload as $entry) {
            if (is_array($entry) && isset($entry['phone'])) {
                $payloadByPhone[(string) $entry['phone']] = $entry;
            }
        }

        $statusOptions = status_options_for_role($user['role']);
        $defaultStatus = $statusOptions[0];

        $createdCount = 0;
        $skippedCount = 0;
        if (!isset($stats['seconds_by_contact_type'])) {
            $stats['seconds_by_contact_type'] = ['customer' => 0, 'family' => 0, 'colleague' => 0];
        }

        $custInsStmt = $pdo->prepare('INSERT INTO customers
            (owner_user_id, full_name, mobile, initial_contact_date, next_followup_date, status, description)
            VALUES (?,?,?,?,?,?,?)');

        // ⭐ INSERT با ستون‌های denormalized — همیشه customer و 0
        $fuInsStmt = $pdo->prepare('INSERT INTO followups
            (customer_id, followup_number, followup_date, event_time, description, status_after, next_followup_date, call_duration_seconds, source, created_by, contact_type, is_phone_call)
            VALUES (?,?,?,?,?,?,?,?,\'call_import\',?,?,?)');

        $fuCountStmt = $pdo->prepare('UPDATE customers SET followup_count = ? WHERE id = ?');

        session_write_close();
        $txBatchSize = 2000;
        $txCounter = 0;
        $pdo->beginTransaction();
        ignore_user_abort(true);

        $__existingGlobal = __call_import_existing_globally($pdo, array_keys($candidates));
        foreach ($candidates as $phoneKey => $cand) {
            $txCounter++;
            if ($txCounter % $txBatchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                if (connection_aborted()) break;
            }

            $entry = $payloadByPhone[$phoneKey] ?? null;
            $checked = ($doAction === 'create') && $entry !== null && !empty($entry['create']);
            if (!$checked) {
                $skippedCount++;
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if (mb_strlen($name) < 2 || __call_import_is_placeholder_name($name, $cand['raw_phone'])) {
                $name = $cand['raw_phone'];
            }
            $rowStatus = trim((string) ($entry['status'] ?? ''));
            if (!in_array($rowStatus, $statusOptions, true)) $rowStatus = $defaultStatus;
            $mobile = (string) $phoneKey;
            if (substr($mobile, 0, 1) !== '0') $mobile = '0' . $mobile;

            $occurrences = $cand['occurrences'];
            usort($occurrences, function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });

            $firstOccurrence = $occurrences[0];
            $initialDate = $firstOccurrence['date'];
            $nextFollowupDate = date('Y-m-d', strtotime($initialDate . ' +' . $NEW_LEAD_FOLLOWUP_DAYS . ' days'));

            $custInsStmt->execute([
                $effectiveUserId, $name, $mobile, $initialDate, $nextFollowupDate, $rowStatus,
                'ایجاد خودکار از فایل تماس روزانه - اطلاعات تکمیلی را در صورت نیاز ویرایش کنید.',
            ]);
            $newCustomerId = (int) $pdo->lastInsertId();
            record_meeting_flag_if_needed($pdo, $newCustomerId, $rowStatus);
            sync_customer_phone_normalized($pdo, $newCustomerId, $mobile, null);
            sync_customer_phone_entries($pdo, $newCustomerId, $mobile, null);
            $newRelation = get_or_create_relation($pdo, $newCustomerId, $effectiveUserId, 'call_import', true);
            $newRelationId = $newRelation['id'] ?? null;
            $newPhoneIdStmt = $pdo->prepare('SELECT id FROM customer_phones WHERE customer_id = ? AND phone = ? LIMIT 1');
            $newPhoneIdStmt->execute([$newCustomerId, $mobile]);
            $newPhoneId = $newPhoneIdStmt->fetchColumn() ?: null;

            $followupNumber = 0;
            foreach ($occurrences as $occ) {
                $followupNumber++;
                // ⭐ execute با دو مقدار جدید: 'customer' و 0
                $fuInsStmt->execute([
                    $newCustomerId,
                    $followupNumber,
                    $occ['date'],
                    $occ['time'] ?? null,
                    $occ['description'],
                    $rowStatus,
                    null,
                    $occ['duration'],
                    $effectiveUserId,
                    'customer',
                    0,
                ]);
                if (!empty($occ['duration'])) {
                    try { require_once __DIR__ . '/includes/performance_functions.php'; ps_note_interaction($pdo, (int) $newCustomerId, (int) $effectiveUserId, 'calizer'); } catch (Throwable $e) {}
                }
                if ($newRelationId || $newPhoneId) {
                    $pdo->prepare('UPDATE followups SET customer_phone_id = ?, relation_id = ? WHERE id = ?')
                        ->execute([$newPhoneId, $newRelationId, (int) $pdo->lastInsertId()]);
                }
                if (!empty($occ['duration'])) {
                    __call_import_recon_add($stats, (int) $occ['duration'], (int) $effectiveUserId, (string) $occ['date']);
                }
            }
            $fuCountStmt->execute([$followupNumber, $newCustomerId]);
            // شماره‌ای که جای دیگری «همکار/خانواده» ثبت شده (یا موبایلِ کارمند است) ← همان نوع + قفل
            try { apply_known_contact_type($pdo, $newCustomerId, $mobile, null); } catch (Throwable $e) {}

            $createdCount++;
        }
        $pdo->commit();

        session_start();
        unset($_SESSION[$SESS_NEWSTEP]);

        $stats['created'] = $createdCount;
        $stats['created_skipped'] = $skippedCount;
        $result = $stats;
        $step = 'result';
    }
    if ($errors && !empty($_SESSION[$SESS_NEWSTEP])) $step = 'new_customers';
}

if ($step === 'upload' && empty($result)) {
    if (!empty($_SESSION[$SESS_PROGRESS])) {
        $step = 'processing';
    } elseif (!empty($_SESSION[$SESS_NEWSTEP])) {
        $step = 'new_customers';
    } elseif (!empty($_SESSION[$SESS_MATCHED])) {
        $step = 'matched_customers';
    } elseif (!empty($_SESSION[$SESS_UPLOAD])) {
        $step = 'map';
    }
}

$pageTitle = 'ورودی از اکسل کالیزر';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.ci-page{--ci-line:#e7e2d3;--ci-ink:#1c1917;--ci-muted:#78716c;--ci-gold:#c9a24b;--ci-gold-2:#f1dfa8;}
.ci-page .ci-back{border-radius:12px;font-weight:700}
.ci-page .alert{border-radius:14px}
.ci-page .ci-steps{display:flex;gap:.4rem;margin-bottom:1.2rem;flex-wrap:wrap}
.ci-page .ci-step{flex:1;min-width:110px;text-align:center;padding:.55rem .5rem;border-radius:12px;font-size:.74rem;font-weight:700;background:#faf9f5;border:1px solid var(--ci-line);color:var(--ci-muted);}
.ci-page .ci-step.active{background:linear-gradient(135deg,var(--ci-gold-2),var(--ci-gold));color:#241d0a;border-color:transparent;box-shadow:0 6px 14px -6px rgba(201,162,75,.6);}
.ci-page .ci-step.done{background:#f0fdf4;border-color:#bbf7d0;color:#15803d}
.ci-page .card{border:1px solid var(--ci-line);border-radius:20px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.ci-page .card h6{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--ci-ink);}
.ci-page .card h6 i{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--ci-gold) 130%);color:#fff;font-size:.8rem;}
.ci-page .form-label{font-size:.78rem;font-weight:700;color:#57534e}
.ci-page .form-control, .ci-page .form-select{border:1px solid var(--ci-line);border-radius:10px;background:#fff}
.ci-page .form-control:focus, .ci-page .form-select:focus{border-color:var(--ci-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.ci-page .form-text{color:var(--ci-muted);font-size:.74rem}
.ci-page .form-check{background:#faf9f5;border:1px solid var(--ci-line);border-radius:10px;padding:.5rem .6rem;padding-inline-start:2rem}
.ci-page .form-check .form-check-input{float:none;margin-inline-start:-1.5em;margin-inline-end:0;vertical-align:middle}
.ci-page .form-check .form-check-label{vertical-align:middle}
.ci-page table{font-size:.83rem}
.ci-page table thead th{background:#faf9f5;color:#78716c;font-weight:700;font-size:.72rem;border-bottom:1px solid var(--ci-line)}
.ci-page table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.ci-page table tbody tr:hover{background:#faf8f2}
.ci-page .btn-primary{border:none;border-radius:12px;font-weight:700;padding:.6rem 1.3rem;color:#241d0a;background:linear-gradient(135deg,var(--ci-gold-2),var(--ci-gold));box-shadow:0 8px 16px -8px rgba(201,162,75,.7);}
.ci-page .btn-primary:hover{transform:translateY(-1px);color:#241d0a}
.ci-page .btn-outline-secondary{border-radius:10px;font-weight:700}
.ci-page .btn-outline-primary{border-radius:10px;font-weight:700;border-color:var(--ci-gold);color:#8a6a1e}
.ci-page .btn-outline-primary:hover{background:linear-gradient(135deg,var(--ci-gold-2),var(--ci-gold));border-color:transparent;color:#241d0a}
.ci-page .ci-stat{border:1px solid var(--ci-line);border-radius:16px;text-align:center;padding:1.1rem .8rem;box-shadow:0 4px 16px -12px rgba(28,25,23,.25);}
.ci-page .ci-stat .text-muted{font-size:.76rem}
.ci-page .ci-stat .fs-3{font-size:1.6rem !important;font-weight:800}
@media (max-width:767.98px){
  .ci-page .card{border-radius:16px}
  .ci-page table{font-size:.76rem}
  .ci-page .ci-step{min-width:80px;font-size:.68rem}
}
</style>

<div class="ci-page">

<a href="imports.php" class="btn btn-sm btn-outline-secondary mb-3 ci-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به ورودی‌ها</a>

<div class="ci-steps">
  <div class="ci-step <?= $step === 'upload' ? 'active' : 'done' ?>">۱. آپلود فایل</div>
  <div class="ci-step <?= $step === 'map' ? 'active' : (in_array($step, ['matched_customers','new_customers','result'], true) ? 'done' : '') ?>">۲. تطبیق ستون‌ها</div>
  <div class="ci-step <?= $step === 'matched_customers' ? 'active' : (in_array($step, ['new_customers','result'], true) ? 'done' : '') ?>">۳. مشتریان منطبق</div>
  <div class="ci-step <?= $step === 'new_customers' ? 'active' : ($step === 'result' ? 'done' : '') ?>">۴. مشتریان جدید</div>
  <div class="ci-step <?= $step === 'result' ? 'active' : '' ?>">۵. نتیجه</div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($step === 'upload'): ?>

<div class="card p-4 mb-3">
  <h6 class="mb-1"><i class="fa-solid fa-file-arrow-up"></i> آپلود فایل روزانه تماس (کالیزر / هر اپلیکیشن مشابه)</h6>
  <p class="text-muted small mb-3">
    فایل خروجی روزانه اکسل یا CSV اپلیکیشن ثبت تماس (مثل کالیزر) را آپلود کنید. سیستم به‌صورت خودکار بر اساس شماره موبایل،
    مشتریان مطابق را پیدا کرده و برای هرکدام یک پیگیری خودکار در لاگ ثبت می‌کند (بدون نیاز به تایپ دستی).
    شماره‌هایی که هنوز به‌عنوان مشتری ثبت نشده‌اند هم شناسایی می‌شوند تا در صورت تمایل با یک کلیک به‌عنوان مشتری جدید اضافه شوند.
    وضعیت و سررسید بعدی مشتریان موجود تغییری نمی‌کند؛ فقط تماس و مدت آن در تاریخچه پیگیری ثبت می‌شود.
  </p>
  <form method="post" enctype="multipart/form-data" data-upload-progress>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="row g-3 align-items-end">
      <div class="col-md-7">
        <label class="form-label">فایل اکسل (xlsx) یا CSV</label>
        <input type="file" name="call_file" class="form-control" accept=".xlsx,.xlsm,.csv,.txt" required>
      </div>
      <div class="col-md-5">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" name="no_header" id="no_header" value="1">
          <label class="form-check-label" for="no_header">فایل من ردیف عنوان (هدر) ندارد</label>
        </div>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3"><i class="fa-solid fa-upload"></i> آپلود و بررسی فایل</button>
  </form>
</div>

<div class="card p-4">
  <h6 class="mb-2"><i class="fa-solid fa-circle-info"></i> راهنما</h6>
  <div class="text-muted small">
    فایل باید حداقل یک ستون شماره موبایل داشته باشد. ستون‌های «نام مخاطب»، «مدت مکالمه»، «تاریخ تماس» و «وضعیت/نوع تماس»
    اختیاری هستند اما در صورت وجود، دقت ثبت خودکار را بالا می‌برند.
  </div>
</div>

<?php elseif ($step === 'map'): ?>

<?php
$data = $_SESSION[$SESS_UPLOAD];
$header = $data['header'];
$previewRows = array_slice($data['rows'], 0, 8);
$autoMap = $data['auto_map'];
$durPreview = [];
foreach ($header as $__ci => $__h) {
    $seen = [];
    foreach (array_slice($data['rows'], 0, 400) as $__r) {
        $raw = trim((string) ($__r[$__ci] ?? ''));
        if ($raw === '' || isset($seen[$raw])) continue;
        $seen[$raw] = format_duration_seconds(parse_call_duration_to_seconds($raw));
        if (count($seen) >= 6) break;
    }
    $durPreview[$__ci] = $seen;
}
$colOptions = function ($selectedIdx) use ($header) {
    $html = '<option value="">— انتخاب نشود —</option>';
    foreach ($header as $idx => $h) {
        $sel = ($selectedIdx === $idx) ? 'selected' : '';
        $html .= '<option value="' . $idx . '" ' . $sel . '>' . e($h) . '</option>';
    }
    return $html;
};
?>

<div class="card p-4 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0"><i class="fa-solid fa-table-columns"></i> تطبیق ستون‌ها - فایل «<?= e($data['file_name']) ?>»</h6>
    <a href="call_log_import.php?cancel=1" class="btn btn-sm btn-outline-secondary">انصراف و آپلود فایل جدید</a>
  </div>
  <p class="text-muted small">
    تعداد <?= to_persian_digits((string) count($data['rows'])) ?> ردیف داده در فایل شناسایی شد. ستون‌های زیر به‌صورت خودکار
    حدس زده شده‌اند؛ در صورت نیاز آن‌ها را اصلاح کنید.
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="process">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">ستون شماره موبایل <span class="text-danger">*</span></label>
        <select name="phone_col" class="form-select" required><?= $colOptions($autoMap['phone']) ?></select>
      </div>
      <div class="col-md-3">
        <label class="form-label">ستون نام مخاطب</label>
        <select name="name_col" class="form-select"><?= $colOptions($autoMap['name']) ?></select>
        <div class="form-text">برای پیشنهاد خودکار نام مشتریان جدید استفاده می‌شود.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">ستون مدت مکالمه</label>
        <select name="duration_col" id="duration_col" class="form-select"><?= $colOptions($autoMap['duration']) ?></select>
        <div class="form-text" id="dur-preview"></div>
        <script>
        (function () {
          var data = <?= json_encode($durPreview, JSON_UNESCAPED_UNICODE) ?>;
          var sel = document.getElementById('duration_col'), box = document.getElementById('dur-preview');
          function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
          function render() {
            var v = sel.value, rows = v === '' ? null : data[v];
            if (!rows || !Object.keys(rows).length) { box.innerHTML = v === '' ? '' : 'این ستون مقداری ندارد.'; return; }
            var h = '<b>چک کنید:</b> مقدارِ فایل ← مدتی که ثبت می‌شود';
            Object.keys(rows).forEach(function (k) { h += '<br><span dir="ltr">' + esc(k) + '</span> ← ' + esc(rows[k]); });
            box.innerHTML = h;
          }
          sel.addEventListener('change', render); render();
        })();
        </script>
      </div>
      <div class="col-md-3">
        <label class="form-label">ستون تاریخ تماس</label>
        <select name="date_col" class="form-select"><?= $colOptions($autoMap['date']) ?></select>
        <div class="form-text">در صورت انتخاب‌نشدن یا نامعتبر بودن، تاریخ امروز ثبت می‌شود.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">ستون ساعت تماس (در صورت جدا بودن از تاریخ)</label>
        <select name="time_col" class="form-select"><?= $colOptions($autoMap['time'] ?? null) ?></select>
        <div class="form-text">فقط وقتی لازم است که ساعت در ستونِ جداگانه‌ای از تاریخ باشد.</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">ستون وضعیت/نوع تماس</label>
        <select name="status_col" class="form-select"><?= $colOptions($autoMap['status']) ?></select>
        <div class="form-text">در صورت نبود، بر اساس مدت مکالمه تشخیص داده می‌شود.</div>
      </div>
      <?php if ($user['role'] === 'admin'): ?>
      <div class="col-md-5">
        <label class="form-label">آپلود به نامِ کارشناس</label>
        <input type="text" class="form-select" list="actAsUserList" id="act_as_user_search" placeholder="برای جستجو تایپ کنید — یا خالی بگذارید" autocomplete="off">
        <input type="hidden" name="act_as_user_id" id="act_as_user_id" value="">
        <datalist id="actAsUserList">
          <?php foreach (__call_import_staff_options($pdo) as $st): ?>
            <option data-id="<?= (int) $st['id'] ?>" value="<?= e(person_pick_label((string) $st['full_name'], $st['mobile'] ?? null)) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-4">
        <div class="form-check mt-4 pt-2">
          <input class="form-check-input" type="checkbox" name="scope_all" id="scope_all" value="1" checked>
          <label class="form-check-label" for="scope_all">جستجوی شماره‌ها در مشتریان <b>همه کارشناس‌ها</b></label>
        </div>
      </div>
      <script>
      (function () {
        var search = document.getElementById('act_as_user_search');
        var hidden = document.getElementById('act_as_user_id');
        var list = document.getElementById('actAsUserList');
        if (!search || !hidden || !list) return;
        function syncFromText() {
          var val = search.value.trim();
          hidden.value = '';
          if (val === '') return;
          var opts = list.querySelectorAll('option');
          for (var i = 0; i < opts.length; i++) {
            if (opts[i].value === val) { hidden.value = opts[i].getAttribute('data-id'); break; }
          }
        }
        search.addEventListener('input', syncFromText);
        search.addEventListener('change', syncFromText);
      })();
      </script>
      <?php endif; ?>
    </div>

    <hr class="my-3">
    <div class="table-responsive mb-3">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-light">
          <tr><?php foreach ($header as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($previewRows as $r): ?>
            <tr><?php foreach ($header as $idx => $h): ?><td><?= e((string) ($r[$idx] ?? '')) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="text-muted small mb-3">پیش‌نمایش <?= to_persian_digits((string) count($previewRows)) ?> ردیف اول فایل.</div>

    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check-double"></i> پردازش و ثبت خودکار پیگیری‌ها</button>
  </form>
</div>

<?php elseif ($step === 'processing'): ?>

<?php
$__progCtx = $_SESSION[$SESS_PROGRESS]['ctx'] ?? ['offset' => 0, 'total' => 1];
$__progTotal = max(1, (int) $__progCtx['total']);
$__progPct = min(100, (int) round(((int) $__progCtx['offset']) / $__progTotal * 100));
?>
<div class="card p-4 mb-3 text-center">
  <h6 class="mb-3"><i class="fa-solid fa-gears fa-spin"></i> در حالِ ثبتِ پیگیری‌ها...</h6>
  <p class="text-muted small mb-3">
    این فایل بزرگه، برایِ همین ثبتش به‌صورتِ خودکار طیِ چند مرحله‌ی کوتاه انجام می‌شه.
  </p>
  <div class="progress mb-2" style="height:22px; border-radius:11px">
    <div class="progress-bar bg-warning text-dark fw-bold" style="width:<?= $__progPct ?>%"><?= to_persian_digits((string) $__progPct) ?>٪</div>
  </div>
  <div class="text-muted small"><?= to_persian_digits((string) $__progCtx['offset']) ?> از <?= to_persian_digits((string) $__progCtx['total']) ?> ردیف پردازش شد.</div>
  <div class="alert alert-danger small mt-3 mb-0 py-2"><i class="fa-solid fa-triangle-exclamation"></i> تا پایانِ کار از این صفحه خارج نشوید و آن را نبندید.</div>
  <a href="call_log_import.php?cancel=1" class="btn btn-sm btn-outline-secondary mt-3">توقف و لغو</a>
</div>
<script>setTimeout(function () { window.location.href = 'call_log_import.php?continue_import=1'; }, 900);</script>
<noscript><meta http-equiv="refresh" content="2;url=call_log_import.php?continue_import=1"></noscript>

<?php elseif ($step === 'matched_customers'): ?>

<?php
$stepData = $_SESSION[$SESS_MATCHED];
$matchedPreview = $stepData['matched_preview'] ?? [];
$statusOptionsForMatched = status_options_for_role($user['role']);
$__actAsName = null;
if (!empty($stepData['act_as_user_id'])) {
    $st = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int) $stepData['act_as_user_id']]);
    $__actAsName = $st->fetchColumn() ?: null;
}
?>

<?php if ($__actAsName): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
  <i class="fa-solid fa-user-check"></i>
  این ایمپورت به‌نامِ کارشناس «<b><?= e($__actAsName) ?></b>» ثبت می‌شود.
</div>
<?php endif; ?>

<div class="card p-4 mb-3">
  <h6 class="mb-1"><i class="fa-solid fa-users"></i> مشتریانِ منطبق‌یافته در فایل</h6>
  <p class="text-muted small mb-3">
    این <?= to_persian_digits((string) count($matchedPreview)) ?> مشتری قبلاً توی سیستم ثبت شدن.
  </p>

  <form method="post" id="matchedForm" onsubmit="return __buildMatchedPayload();" data-busy="در حالِ ثبتِ پیگیری‌ها…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="finalize_matched">
    <input type="hidden" name="status_overrides" id="matchedOverridesInput" value="{}">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded-3" style="background:#fffbeb;border:1px solid #fde68a">
      <label class="mb-0 small fw-bold"><i class="fa-solid fa-layer-group text-warning"></i> تغییرِ یکجای وضعیتِ همه‌ی این مشتری‌ها:</label>
      <select id="matchedBulkStatus" class="form-select form-select-sm" style="width:auto">
        <option value="">— بدون تغییر —</option>
        <?php foreach ($statusOptionsForMatched as $st): if ($st === 'جلسه برگزار شد') continue; ?>
          <option value="<?= e($st) ?>"><?= e($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="var v=document.getElementById('matchedBulkStatus').value;document.querySelectorAll('.matched-status-select').forEach(function(s){s.value=v;});">اعمال به همه</button>
      <span class="small text-muted">بعد می‌توانید تک‌تک را هم عوض کنید؛ با «تایید و ثبت نهایی» ثبت می‌شود.</span>
    </div>

    <div class="table-responsive mb-3" style="max-height:420px; overflow-y:auto;">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr><th>نام</th><th>موبایل</th><th>وضعیت فعلی</th><th>تعداد تماس در فایل</th><th>تغییر وضعیت به</th></tr>
        </thead>
        <tbody>
          <?php foreach ($matchedPreview as $cid => $m): ?>
            <tr>
              <td><?= e($m['full_name']) ?></td>
              <td dir="ltr"><?= e($m['mobile']) ?></td>
              <td><?= e($m['status']) ?></td>
              <td><?= to_persian_digits((string) $m['count']) ?></td>
              <td>
                <select class="form-select form-select-sm matched-status-select" data-cid="<?= (int) $cid ?>">
                  <option value="">— بدون تغییر —</option>
                  <?php foreach ($statusOptionsForMatched as $st): ?>
                    <?php if ($st === 'جلسه برگزار شد') continue; ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check-double"></i> تایید و ثبت نهایی</button>
    <a href="call_log_import.php?cancel=1" class="btn btn-outline-secondary">لغو و شروع دوباره</a>
  </form>
</div>
<script>
function __buildMatchedPayload() {
  var overrides = {};
  document.querySelectorAll('.matched-status-select').forEach(function (sel) {
    if (sel.value !== '') {
      overrides[sel.getAttribute('data-cid')] = sel.value;
    }
  });
  document.getElementById('matchedOverridesInput').value = JSON.stringify(overrides);
  return true;
}
</script>

<?php elseif ($step === 'new_customers'): ?>

<?php
$stepData = $_SESSION[$SESS_NEWSTEP];
$candidates = $stepData['candidates'];
$statusOptionsForNew = status_options_for_role($user['role']);
$existingGlobal = __call_import_existing_globally($pdo, array_keys($candidates));
?>

<div class="card p-4 mb-3">
  <h6 class="mb-1"><i class="fa-solid fa-user-plus"></i> مشتریان جدید یافت‌شده در فایل</h6>
  <p class="text-muted small mb-3">
    این <?= to_persian_digits((string) count($candidates)) ?> شماره در فایل تماس شما وجود دارند اما هنوز به‌عنوان مشتری در سیستم ثبت نشده‌اند.
  </p>

  <form method="post" id="newCustForm" onsubmit="__buildNewCustPayload('newCustForm')" data-busy="در حالِ ثبتِ مشتریانِ جدید…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_new">
    <input type="hidden" name="do" id="newCustDo" value="create">
    <input type="hidden" name="payload" id="newCustPayload" value="[]">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.new-cust-check').forEach(c=>{if(!c.disabled)c.checked=true})">انتخاب همه</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.new-cust-check').forEach(c=>c.checked=false)">لغو همه</button>
      <span class="text-muted small">|</span>
      <label class="mb-0 small">اعمال یک وضعیت به همه:</label>
      <select id="bulkStatusSelect" class="form-select form-select-sm" style="width:auto">
        <?php foreach ($statusOptionsForNew as $st): ?>
          <option value="<?= e($st) ?>"><?= e($st) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.querySelectorAll('.new-cust-status').forEach(s=>s.value=document.getElementById('bulkStatusSelect').value)">اعمال به همه</button>
    </div>

    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr><th style="width:40px"></th><th>شماره موبایل</th><th>نام و نام خانوادگی (قابل ویرایش)</th><th>تعداد تماس در فایل</th><th>وضعیت اولیه</th></tr>
        </thead>
        <tbody>
        <?php foreach ($candidates as $phoneKey => $cand): ?>
          <tr>
            <?php $ex = $existingGlobal[(string) $phoneKey] ?? null; ?>
            <td><input class="form-check-input new-cust-check" type="checkbox" data-phone="<?= e($phoneKey) ?>" checked></td>
            <td dir="ltr"><?= to_persian_digits((string) $phoneKey) ?>
              <?php if ($ex): ?><div class="small text-info-emphasis" dir="rtl"><i class="fa-solid fa-circle-info"></i> از قبل در سامانه: <b><?= e($ex['name']) ?></b></div><?php endif; ?>
            </td>
            <td><input type="text" class="form-control form-control-sm new-cust-name" data-phone="<?= e($phoneKey) ?>" value="<?= e($cand['name']) ?>" placeholder="نام مشتری (اختیاری)"></td>
            <td><?= to_persian_digits((string) count($cand['occurrences'])) ?></td>
            <td>
              <select class="form-select form-select-sm new-cust-status" data-phone="<?= e($phoneKey) ?>">
                <?php foreach ($statusOptionsForNew as $st): ?>
                  <option value="<?= e($st) ?>"><?= e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-primary" onclick="document.getElementById('newCustDo').value='create'"><i class="fa-solid fa-user-plus"></i> افزودن موارد انتخاب‌شده به‌عنوان مشتری جدید</button>
      <button type="submit" class="btn btn-outline-secondary" onclick="document.getElementById('newCustDo').value='skip'">هیچ‌کدام؛ رد شو و نتیجه را نشان بده</button>
    </div>
  </form>
</div>
<script>
function __buildNewCustPayload(formId) {
  var form = document.getElementById(formId);
  var rows = [];
  form.querySelectorAll('.new-cust-check').forEach(function (chk) {
    var phone = chk.getAttribute('data-phone');
    var safePhone = phone.replace(/"/g, '');
    var nameInput = form.querySelector('.new-cust-name[data-phone="' + safePhone + '"]');
    var statusSelect = form.querySelector('.new-cust-status[data-phone="' + safePhone + '"]');
    rows.push({ phone: phone, name: nameInput ? nameInput.value : '', status: statusSelect ? statusSelect.value : '', create: chk.checked });
  });
  document.getElementById('newCustPayload').value = JSON.stringify(rows);
  return true;
}
</script>

<?php elseif ($step === 'result'): ?>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="card ci-stat"><div class="text-muted">کل ردیف‌های فایل</div><div class="fs-3 fw-bold"><?= to_persian_digits((string) $result['total_rows']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card ci-stat"><div class="text-muted">پیگیری‌های ثبت‌شده</div><div class="fs-3 fw-bold text-success"><?= to_persian_digits((string) $result['inserted']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card ci-stat"><div class="text-muted">تماس‌های برقرارشده</div><div class="fs-3 fw-bold text-primary"><?= to_persian_digits((string) $result['connected']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card ci-stat"><div class="text-muted">بی‌پاسخ / نگرفته</div><div class="fs-3 fw-bold text-warning"><?= to_persian_digits((string) $result['not_connected']) ?></div></div>
  </div>
</div>

<?php
  $sbct = $result['seconds_by_contact_type'] ?? ['customer' => 0, 'family' => 0, 'colleague' => 0];
  $ctLabels = ['customer' => 'با مشتریان', 'colleague' => 'با همکاران', 'family' => 'با خانواده'];
?>
<div class="row g-3 mb-4">
  <?php foreach ($ctLabels as $ctKey => $ctLabel): ?>
    <div class="col-md-4 col-6">
      <div class="card ci-stat">
        <div class="text-muted"><i class="fa-solid fa-phone-volume"></i> مدتِ مکالمه <?= e($ctLabel) ?></div>
        <div class="fs-3 fw-bold"><?= to_persian_digits((string) round(($sbct[$ctKey] ?? 0) / 60)) ?> <span class="fs-6 fw-normal text-muted">دقیقه</span></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card p-4 mb-3">
  <h6 class="mb-3"><i class="fa-solid fa-list-check"></i> خلاصه پردازش</h6>
  <ul class="mb-0">
    <li>از مجموع <?= to_persian_digits((string) $result['total_rows']) ?> ردیف فایل، برای
        <?= to_persian_digits((string) $result['matched_customers']) ?> مورد، مشتری مطابق در سیستم پیدا شد.</li>
    <li><?= to_persian_digits((string) $result['inserted']) ?> پیگیری جدید با موفقیت در تاریخچه هر مشتری ثبت شد.</li>
    <?php if ($result['duplicate'] > 0): ?>
      <li><?= to_persian_digits((string) $result['duplicate']) ?> ردیف تکراری نادیده گرفته شد.</li>
    <?php endif; ?>
    <?php if (!empty($result['name_fixed']) && $result['name_fixed'] > 0): ?>
      <li><?= to_persian_digits((string) $result['name_fixed']) ?> مشتری نامش اصلاح شد.</li>
    <?php endif; ?>
    <?php if ($result['invalid_phone'] > 0): ?>
      <li><?= to_persian_digits((string) $result['invalid_phone']) ?> ردیف فاقد شماره موبایل معتبر بود.</li>
    <?php endif; ?>
    <?php if ($result['created'] > 0): ?>
      <li><?= to_persian_digits((string) $result['created']) ?> مشتری جدید اضافه شد.</li>
    <?php endif; ?>
    <?php if ($result['created_skipped'] > 0): ?>
      <li><?= to_persian_digits((string) $result['created_skipped']) ?> شماره ناشناس ثبت نشد.</li>
    <?php endif; ?>
  </ul>
</div>

<a href="call_log_import.php" class="btn btn-primary"><i class="fa-solid fa-rotate"></i> آپلود فایل بعدی</a>
<a href="customer_list.php" class="btn btn-outline-secondary"><i class="fa-solid fa-list-check"></i> مشاهده لیست مشتریان</a>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/upload_progress.php'; ?>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>