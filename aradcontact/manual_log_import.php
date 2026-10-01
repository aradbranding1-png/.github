<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/spreadsheet_reader.php';
$user = require_login();
$pdo  = db();
try { contact_type_backfill_v1($pdo); } catch (Throwable $e) {} // یک‌بار: اصلاحِ همکار/خانواده‌هایی که «مشتری» مانده‌اند

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

$SESS_UPLOAD  = 'manual_import_data';
$SESS_NEWSTEP = 'manual_import_new_step';
$MAX_UPLOAD_BYTES = 50 * 1024 * 1024;
$NEW_LEAD_FOLLOWUP_DAYS = 3;
$DEFAULT_BASE_YEAR = 1405;
$step = 'upload';
$errors = [];
$result = null;

// ادمینِ کل می‌تواند فایل را «برای یک کارشناسِ دیگر» وارد کند: مشتری‌ها به لیستِ همان کارشناس می‌روند
$canActAs = is_super_admin($user);
function __manual_import_act_as_user(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT id, full_name, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['cancel'])) {
    unset($_SESSION[$SESS_UPLOAD], $_SESSION[$SESS_NEWSTEP]);
    redirect('manual_log_import.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    unset($_SESSION[$SESS_NEWSTEP]);
    if (!csrf_verify()) {
        $errors[] = 'نشست شما منقضی شده است، صفحه را رفرش کرده و دوباره تلاش کنید.';
    } elseif (empty($_FILES['manual_file']) || $_FILES['manual_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'لطفا یک فایل اکسل یا CSV انتخاب کنید.';
    } elseif ($_FILES['manual_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'خطا در آپلود فایل. دوباره تلاش کنید.';
    } elseif ($_FILES['manual_file']['size'] > $MAX_UPLOAD_BYTES) {
        $errors[] = 'حجم فایل بیش از حد مجاز (۵۰ مگابایت) است.';
    } else {
        $baseYear = (int) normalize_digits((string) ($_POST['base_year'] ?? $DEFAULT_BASE_YEAR));
        if ($baseYear < 1300 || $baseYear > 1500) {
            $baseYear = $DEFAULT_BASE_YEAR;
        }

        $ext = strtolower(pathinfo($_FILES['manual_file']['name'], PATHINFO_EXTENSION));
        $sheetsRaw = null;
        $parseError = null;
        if ($ext === 'xlsx' || $ext === 'xlsm') {
            if (!class_exists('ZipArchive')) {
                $parseError = 'اکستنشن ZipArchive روی این هاست فعال نیست.';
            } else {
                $sheetsRaw = read_xlsx_all_sheets($_FILES['manual_file']['tmp_name'], true);
            }
        } elseif ($ext === 'csv' || $ext === 'txt') {
            $rows = read_csv_spreadsheet($_FILES['manual_file']['tmp_name']);
            $sheetsRaw = $rows ? ['فایل CSV' => $rows] : null;
        } elseif ($ext === 'xls') {
            $parseError = 'فرمت قدیمی xls (اکسل ۲۰۰۳) پشتیبانی نمی‌شود.';
        } else {
            $parseError = 'فرمت فایل شناسایی نشد.';
        }

        if ($sheetsRaw === null) {
            $errors[] = $parseError ?: 'خواندن فایل ممکن نشد.';
        } else {
            $sheets = [];
            foreach ($sheetsRaw as $sheetName => $sheetData) {
                $comments = [];
                if (is_array($sheetData) && array_key_exists('rows', $sheetData)) {
                    $rows = $sheetData['rows'];
                    $comments = is_array($sheetData['comments'] ?? null) ? $sheetData['comments'] : [];
                    if ($comments) {
                        $alignedComments = [];
                        foreach ($comments as $originalRowIndex => $cellComments) {
                            $newRowIndex = (int) $originalRowIndex - 1;
                            if ($newRowIndex >= 0 && is_array($cellComments)) {
                                $alignedComments[$newRowIndex] = $cellComments;
                            }
                        }
                        $comments = $alignedComments;
                    }
                } else {
                    $rows = $sheetData;
                }
                if (!is_array($rows) || count($rows) < 2) continue;
                $header = array_shift($rows);
                if (!$rows) continue;
                $cols = detect_manual_log_columns($header);
                $sheets[$sheetName] = [
                    'header'    => $header,
                    'rows'      => $rows,
                    'comments'  => $comments,
                    'cols'      => $cols,
                    'row_count' => count($rows),
                ];
            }
            if (!$sheets) {
                $errors[] = 'هیچ شیت یا ردیف داده‌ای در فایل یافت نشد.';
            } else {
                $_SESSION[$SESS_UPLOAD] = [
                    'sheets'      => $sheets,
                    'file_name'   => $_FILES['manual_file']['name'],
                    'base_year'   => $baseYear,
                    'uploaded_at' => time(),
                ];
                redirect('manual_log_import.php');
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
        $data = $_SESSION[$SESS_UPLOAD];
        $baseYear = (int) $data['base_year'];
        $scopeAll = ($user['role'] === 'admin') && isset($_POST['scope_all']);
        $actAs = $canActAs ? __manual_import_act_as_user($pdo, (int) ($_POST['act_as_user_id'] ?? 0)) : null;
        $ownerId = $actAs ? (int) $actAs['id'] : (int) $user['id'];
        $moveExisting = $actAs && !empty($_POST['move_existing']);
        // تغییرِ یکجای وضعیتِ مشتریانِ موجودِ این فایل (قفل‌شده‌ها و همکار/خانواده دست نمی‌خورند)
        $existStatus = in_array((string) ($_POST['existing_status'] ?? ''), unified_status_options(), true) ? (string) $_POST['existing_status'] : '';
        $statusChangedIds = [];
        $movedCustomers = 0;
        $movedIds = [];

        if ($scopeAll || $moveExisting) {
            $custStmt = $pdo->query('SELECT * FROM customers');
        } else {
            $custStmt = $pdo->prepare('SELECT * FROM customers WHERE owner_user_id = ?');
            $custStmt->execute([$ownerId]);
        }
        $phoneMap = [];
        foreach ($custStmt->fetchAll() as $c) {
            $norm = normalize_phone_for_match($c['mobile']);
            if ($norm !== null) {
                $phoneMap[$norm][] = $c;
            }
            if (!empty($c['mobile_2'])) {
                $norm2 = normalize_phone_for_match($c['mobile_2']);
                if ($norm2 !== null && $norm2 !== $norm) {
                    $phoneMap[$norm2][] = $c;
                }
            }
        }

        $totalRows = 0;
        $invalidPhoneCount = 0;
        $skippedSheetCount = 0;
        $newCandidates = [];
        $matchedCustomerRows = 0;
        $insertedCount = 0;
        $duplicateCount = 0;
        $multiPhoneRowCount = 0;

        // ⭐ INSERT با ستون‌های denormalized
        $insStmt = $pdo->prepare('INSERT INTO followups
            (customer_id, followup_number, followup_date, description, status_after, next_followup_date, call_duration_seconds, source, created_by, contact_type, is_phone_call)
            VALUES (?,?,?,?,?,NULL,NULL,\'manual_excel_import\',?,?,?)');
        $dupStmt = $pdo->prepare('SELECT id FROM followups WHERE customer_id = ? AND source = \'manual_excel_import\' AND followup_date = ? AND description = ? LIMIT 1');
        $updStmt = $pdo->prepare('UPDATE customers SET followup_count = followup_count + 1 WHERE id = ?');

        $txBatchSize = 2000;
        $txCounter = 0;
        $pdo->beginTransaction();

        foreach ($data['sheets'] as $sheetName => $sheet) {
            $cols = $sheet['cols'];
            if ($cols['phone'] === null) {
                $skippedSheetCount++;
                continue;
            }
            $artebatCols = $cols['artebat'];

            foreach ($sheet['rows'] as $rowIndex => $row) {
                $totalRows++;
                $txCounter++;
                if ($txCounter % $txBatchSize === 0) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                }
                $rawPhone = trim((string) ($row[$cols['phone']] ?? ''));
                $phoneNumbers = extract_mobile_numbers_from_cell($rawPhone);
                if (!$phoneNumbers) {
                    $invalidPhoneCount++;
                    continue;
                }
                $primaryPhone = $phoneNumbers[0];
                $secondaryPhone = $phoneNumbers[1] ?? null;
                if (count($phoneNumbers) > 1) {
                    $multiPhoneRowCount++;
                }

                $rawName = $cols['name'] !== null ? trim((string) ($row[$cols['name']] ?? '')) : '';
                $rawCity = $cols['city'] !== null ? trim((string) ($row[$cols['city']] ?? '')) : '';
                $rawDesc = $cols['description'] !== null ? trim((string) ($row[$cols['description']] ?? '')) : '';

                $tokens = [];
                $tokenColumns = [];
                if ($cols['first'] !== null) {
                    $tokens[] = (string) ($row[$cols['first']] ?? '');
                    $tokenColumns[] = $cols['first'];
                }
                foreach ($artebatCols as $idx) {
                    $tokens[] = (string) ($row[$idx] ?? '');
                    $tokenColumns[] = $idx;
                }
                $hasNext = $cols['next'] !== null;
                if ($hasNext) {
                    $tokens[] = (string) ($row[$cols['next']] ?? '');
                    $tokenColumns[] = $cols['next'];
                }
                $parsed = parse_manual_log_sequence($tokens, $baseYear);
                $nextParsed = $hasNext ? array_pop($parsed) : null;
                $nextColumn = $hasNext ? array_pop($tokenColumns) : null;
                $nextDateG = $nextParsed['date'] ?? null;

                $rowComments = is_array($sheet['comments'][$rowIndex] ?? null) ? $sheet['comments'][$rowIndex] : [];

                $occurrences = [];
                foreach ($parsed as $pIndex => $p) {
                    if ($p === null) continue;
                    $sourceColumn = $tokenColumns[$pIndex] ?? null;
                    $commentText = ($sourceColumn !== null) ? trim((string) ($rowComments[$sourceColumn] ?? '')) : '';
                    $description = build_manual_import_description($p['mode']);
                    if ($commentText !== '') {
                        $description .= "\n" . $commentText;
                    }
                    $occurrences[] = [
                        'date'        => $p['date'],
                        'duration'    => null,
                        'connected'   => $p['mode'] !== 'missed',
                        'description' => $description,
                    ];
                }

                if ($rawDesc !== '' && $occurrences) {
                    $lastIdx = count($occurrences) - 1;
                    $occurrences[$lastIdx]['description'] = $occurrences[$lastIdx]['description'] . ' - ' . $rawDesc;
                }

                $matchedCustomers = [];
                foreach ($phoneNumbers as $phoneNorm) {
                    foreach (($phoneMap[$phoneNorm] ?? []) as $customer) {
                        $matchedCustomers[(int) $customer['id']] = $customer;
                    }
                }

                if (!$matchedCustomers) {
                    if (!isset($newCandidates[$primaryPhone])) {
                        $newCandidates[$primaryPhone] = [
                            'raw_phone'   => $rawPhone,
                            'phones'      => $phoneNumbers,
                            'name'        => $rawName,
                            'city'        => $rawCity,
                            'description' => $rawDesc,
                            'next_date'   => $nextDateG,
                            'occurrences' => [],
                        ];
                    } else {
                        foreach ($phoneNumbers as $pn) {
                            if (!in_array($pn, $newCandidates[$primaryPhone]['phones'], true) && count($newCandidates[$primaryPhone]['phones']) < 2) {
                                $newCandidates[$primaryPhone]['phones'][] = $pn;
                            }
                        }
                        if ($newCandidates[$primaryPhone]['name'] === '' && $rawName !== '') {
                            $newCandidates[$primaryPhone]['name'] = $rawName;
                        }
                        if ($nextDateG !== null) {
                            $newCandidates[$primaryPhone]['next_date'] = $nextDateG;
                        }
                    }
                    foreach ($occurrences as $occ) {
                        $newCandidates[$primaryPhone]['occurrences'][] = $occ;
                    }
                    continue;
                }

                foreach ($matchedCustomers as $customer) {
                    $matchedCustomerRows++;
                    // انتقالِ مشتریِ موجود به لیستِ کارشناسِ انتخاب‌شده (همکار/خانواده منتقل نمی‌شوند)
                    if ($moveExisting && (int) $customer['owner_user_id'] !== $ownerId && ($customer['contact_type'] ?? 'customer') === 'customer'
                        && !isset($movedIds[(int) $customer['id']])) {
                        $movedIds[(int) $customer['id']] = true;
                        $pdo->prepare('UPDATE customers SET owner_user_id = ?, new_customer_notified = 0 WHERE id = ?')->execute([$ownerId, (int) $customer['id']]);
                        try { get_or_create_relation($pdo, (int) $customer['id'], $ownerId, 'manual_import', true); } catch (Throwable $e) {}
                        try {
                            $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)')
                                ->execute([(int) $customer['id'], (int) $user['id'], 'transfer', 'انتقال به ' . $actAs['full_name'] . ' از طریقِ آپلودِ اکسل (توسطِ ادمین کل)']);
                        } catch (Throwable $e) {}
                        $movedCustomers++;
                    }
                    if ($moveExisting && isset($movedIds[(int) $customer['id']])) $customer['owner_user_id'] = $ownerId;
                    if ($existStatus !== '' && !isset($statusChangedIds[(int) $customer['id']])) {
                        $statusChangedIds[(int) $customer['id']] = false;
                        if ((int) ($customer['status_locked'] ?? 0) === 0 && ($customer['contact_type'] ?? 'customer') === 'customer' && $customer['status'] !== $existStatus) {
                            $pdo->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$existStatus, (int) $customer['id']]);
                            try { record_meeting_flag_if_needed($pdo, (int) $customer['id'], $existStatus); } catch (Throwable $e) {}
                            try {
                                $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)')
                                    ->execute([(int) $customer['id'], (int) $user['id'], 'status', 'تغییرِ وضعیت از «' . $customer['status'] . '» به «' . $existStatus . '» هنگامِ ورودِ اکسل دستی']);
                            } catch (Throwable $e) {}
                            $statusChangedIds[(int) $customer['id']] = true;
                        }
                    }
                    if ($existStatus !== '' && !empty($statusChangedIds[(int) $customer['id']])) $customer['status'] = $existStatus;
                    foreach ($occurrences as $occ) {
                        $dupStmt->execute([$customer['id'], $occ['date'], $occ['description']]);
                        if ($dupStmt->fetch()) {
                            $duplicateCount++;
                            continue;
                        }
                        $nextFollowupNumber = (int) $customer['followup_count'] + 1;

                        // ⭐ محاسبه‌ی contact_type از رکورد مشتری
                        $__ct = (string) ($customer['contact_type'] ?? 'customer');
                        if (!in_array($__ct, ['customer', 'family', 'colleague'], true)) $__ct = 'customer';

                        // ⭐ execute با دو مقدار جدید (این تماس‌ها manual هستند، پس is_phone_call=0)
                        $insStmt->execute([
                            $customer['id'],
                            $nextFollowupNumber,
                            $occ['date'],
                            $occ['description'],
                            $customer['status'],
                            $customer['owner_user_id'],
                            $__ct,
                            0,
                        ]);
                        $updStmt->execute([$customer['id']]);
                        $customer['followup_count'] = $nextFollowupNumber;
                        $insertedCount++;
                    }
                }
            }
        }
        $pdo->commit();

        unset($_SESSION[$SESS_UPLOAD]);

        $stats = [
            'total_rows'        => $totalRows,
            'invalid_phone'     => $invalidPhoneCount,
            'skipped_sheets'    => $skippedSheetCount,
            'matched_customers' => $matchedCustomerRows,
            'inserted'          => $insertedCount,
            'duplicate'         => $duplicateCount,
            'multi_phone_rows'  => $multiPhoneRowCount,
            'scope_all'         => $scopeAll,
            'act_as_user_id'    => $actAs ? (int) $actAs['id'] : null,
            'act_as_role'       => $actAs ? (string) $actAs['role'] : null,
            'act_as_name'       => $actAs ? (string) $actAs['full_name'] : null,
            'moved_customers'   => $movedCustomers,
            'existing_status'   => $existStatus,
            'status_changed'    => count(array_filter($statusChangedIds)),
            'created'           => 0,
            'created_skipped'   => 0,
        ];

        if ($newCandidates) {
            $_SESSION[$SESS_NEWSTEP] = ['candidates' => $newCandidates, 'stats' => $stats, 'base_year' => $baseYear];
            $step = 'new_customers';
        } else {
            $result = $stats;
            $step = 'result';
        }
    }
    if ($errors && !empty($_SESSION[$SESS_UPLOAD])) {
        $step = 'confirm';
    }
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

        $payload = json_decode((string) ($_POST['payload'] ?? '[]'), true);
        if (!is_array($payload)) $payload = [];
        $payloadByPhone = [];
        foreach ($payload as $entry) {
            if (is_array($entry) && isset($entry['phone'])) {
                $payloadByPhone[(string) $entry['phone']] = $entry;
            }
        }

        $ownerId = (int) ($stats['act_as_user_id'] ?? 0) ?: (int) $user['id'];
        $statusOptions = status_options_for_role((string) ($stats['act_as_role'] ?? '') ?: $user['role']);
        $defaultStatus = $statusOptions[0];

        $createdCount = 0;
        $skippedCount = 0;

        $custInsStmt = $pdo->prepare('INSERT INTO customers
            (owner_user_id, full_name, mobile, mobile_2, initial_contact_date, next_followup_date, status, city, description)
            VALUES (?,?,?,?,?,?,?,?,?)');

        // ⭐ INSERT با ستون‌های denormalized — همیشه customer و 0
        $fuInsStmt = $pdo->prepare('INSERT INTO followups
            (customer_id, followup_number, followup_date, description, status_after, next_followup_date, call_duration_seconds, source, created_by, contact_type, is_phone_call)
            VALUES (?,?,?,?,?,NULL,NULL,\'manual_excel_import\',?,?,?)');

        $fuCountStmt = $pdo->prepare('UPDATE customers SET followup_count = ? WHERE id = ?');

        $txBatchSize = 2000;
        $txCounter = 0;
        $pdo->beginTransaction();

        foreach ($candidates as $phoneKey => $cand) {
            $txCounter++;
            if ($txCounter % $txBatchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }

            $entry = $payloadByPhone[$phoneKey] ?? null;
            $checked = ($doAction === 'create') && $entry !== null && !empty($entry['create']);
            if (!$checked) {
                $skippedCount++;
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if (mb_strlen($name) < 2) {
                $name = 'مخاطب ' . $cand['raw_phone'];
            }
            $rowStatus = trim((string) ($entry['status'] ?? ''));
            if (!in_array($rowStatus, $statusOptions, true)) {
                $rowStatus = $defaultStatus;
            }
            $mobile = '0' . $phoneKey;
            $candidatePhones = is_array($cand['phones'] ?? null) ? $cand['phones'] : [$phoneKey];
            $mobile2 = isset($candidatePhones[1]) ? ('0' . $candidatePhones[1]) : null;

            $occurrences = $cand['occurrences'];
            usort($occurrences, function ($a, $b) {
                return strcmp($a['date'], $b['date']);
            });

            if ($occurrences) {
                $initialDate = $occurrences[0]['date'];
            } else {
                $initialDate = date('Y-m-d');
            }
            $nextFollowupDate = $cand['next_date'] ?? date('Y-m-d', strtotime($initialDate . ' +' . $NEW_LEAD_FOLLOWUP_DAYS . ' days'));

            $custInsStmt->execute([
                $ownerId,
                $name,
                $mobile,
                $mobile2,
                $initialDate,
                $nextFollowupDate,
                $rowStatus,
                $cand['city'] !== '' ? $cand['city'] : null,
                $cand['description'] !== '' ? $cand['description'] : 'ایجاد خودکار از اکسل دستی پیگیری - اطلاعات تکمیلی را در صورت نیاز ویرایش کنید.',
            ]);
            $newCustomerId = (int) $pdo->lastInsertId();
            record_meeting_flag_if_needed($pdo, $newCustomerId, $rowStatus);

            $followupNumber = 0;
            foreach ($occurrences as $occ) {
                $followupNumber++;
                // ⭐ execute با دو مقدار جدید: 'customer' و 0
                $fuInsStmt->execute([
                    $newCustomerId,
                    $followupNumber,
                    $occ['date'],
                    $occ['description'],
                    $rowStatus,
                    $ownerId,
                    'customer',
                    0,
                ]);
            }
            $fuCountStmt->execute([$followupNumber, $newCustomerId]);
            // شماره‌ای که جای دیگری «همکار/خانواده» ثبت شده ← همان نوع + قفل
            try {
                sync_customer_phone_normalized($pdo, $newCustomerId, $mobile, $mobile2);
                if ($ownerId !== (int) $user['id']) get_or_create_relation($pdo, $newCustomerId, $ownerId, 'manual_import', true);
                apply_known_contact_type($pdo, $newCustomerId, $mobile, $mobile2);
            } catch (Throwable $e) {}

            $createdCount++;
        }
        $pdo->commit();

        unset($_SESSION[$SESS_NEWSTEP]);

        $stats['created'] = $createdCount;
        $stats['created_skipped'] = $skippedCount;
        $result = $stats;
        $step = 'result';
    }
    if ($errors && !empty($_SESSION[$SESS_NEWSTEP])) {
        $step = 'new_customers';
    }
}

if (!empty($_SESSION['manual_import_final_result'])) {
    $result = $_SESSION['manual_import_final_result'];
    $step = 'result';
    unset($_SESSION['manual_import_final_result']);
} elseif ($step === 'upload' && empty($result)) {
    if (!empty($_SESSION[$SESS_NEWSTEP])) {
        $step = 'new_customers';
    } elseif (!empty($_SESSION[$SESS_UPLOAD])) {
        $step = 'confirm';
    }
}

$pageTitle = 'ورودی از اکسل پیگیری';
require_once __DIR__ . '/includes/layout_top.php';
?>

<style>
.mi-page{--mi-line:#e7e2d3;--mi-ink:#1c1917;--mi-muted:#78716c;--mi-gold:#c9a24b;--mi-gold-2:#f1dfa8;}
.mi-page .mi-steps{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.mi-page .mi-step{flex:1 1 0;min-width:110px;text-align:center;padding:.55rem .5rem;border-radius:12px;font-size:.78rem;font-weight:700;background:#faf9f5;border:1px solid var(--mi-line);color:var(--mi-muted);}
.mi-page .mi-step.active{background:linear-gradient(135deg,var(--mi-gold-2),var(--mi-gold));color:#241d0a;border-color:transparent;box-shadow:0 8px 16px -10px rgba(201,162,75,.7);}
.mi-page .mi-step.done{background:#eafaf0;border-color:#bbf0cf;color:#15803d}
.mi-page .mi-back{border-radius:10px;font-weight:700}
.mi-page .card{border:1px solid var(--mi-line);border-radius:20px;box-shadow:0 4px 20px -16px rgba(28,25,23,.3);}
.mi-page .card h6{display:flex;align-items:center;gap:.5rem;font-weight:800;color:var(--mi-ink);}
.mi-page .card h6 i{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--mi-gold) 130%);color:#fff;font-size:.8rem;}
.mi-page .card h6 i.text-primary{color:#fff !important}
.mi-page .form-label{font-size:.78rem;font-weight:700;color:#57534e}
.mi-page .form-control, .mi-page .form-select{border:1px solid var(--mi-line);border-radius:10px;background:#fff}
.mi-page .form-control:focus, .mi-page .form-select:focus{border-color:var(--mi-gold);box-shadow:0 0 0 4px rgba(201,162,75,.15)}
.mi-page .form-text{color:var(--mi-muted);font-size:.74rem}
.mi-page .form-check{background:#faf9f5;border:1px solid var(--mi-line);border-radius:10px;padding:.5rem .6rem;padding-inline-start:2rem}
.mi-page .form-check .form-check-input{float:none;margin-inline-start:-1.5em;margin-inline-end:0;vertical-align:middle}
.mi-page .form-check .form-check-label{vertical-align:middle}
.mi-page table{font-size:.83rem}
.mi-page table thead th{background:#faf9f5;color:#78716c;font-weight:700;font-size:.72rem;border-bottom:1px solid var(--mi-line)}
.mi-page table tbody td{border-bottom:1px solid #f3f1ea;vertical-align:middle}
.mi-page table tbody tr:hover{background:#faf8f2}
.mi-page table tbody tr.table-danger{background:#fdf1f1 !important}
.mi-page .btn-primary{border:none;border-radius:12px;font-weight:700;padding:.6rem 1.3rem;color:#241d0a;background:linear-gradient(135deg,var(--mi-gold-2),var(--mi-gold));box-shadow:0 8px 16px -8px rgba(201,162,75,.7);}
.mi-page .btn-primary:hover{transform:translateY(-1px);color:#241d0a}
.mi-page .btn-outline-secondary{border-radius:10px;font-weight:700}
.mi-page .btn-outline-primary{border-radius:10px;font-weight:700;border-color:var(--mi-gold);color:#8a6a1e}
.mi-page .btn-outline-primary:hover{background:linear-gradient(135deg,var(--mi-gold-2),var(--mi-gold));border-color:transparent;color:#241d0a}
.mi-page .mi-stat{border:1px solid var(--mi-line);border-radius:16px;text-align:center;padding:1.1rem .8rem;box-shadow:0 4px 16px -12px rgba(28,25,23,.25);}
.mi-page .mi-stat .text-muted{font-size:.76rem}
.mi-page .mi-stat .fs-3{font-size:1.6rem !important;font-weight:800}
.mi-page #newCustProgressBox{border-radius:14px;border:1px solid #bcd8f5}
@media (max-width:767.98px){
  .mi-page .card{border-radius:16px}
  .mi-page table{font-size:.76rem}
  .mi-page .mi-step{min-width:80px;font-size:.68rem}
}
</style>

<div class="mi-page">

<a href="imports.php" class="btn btn-sm btn-outline-secondary mb-3 mi-back"><i class="fa-solid fa-arrow-right"></i> بازگشت به ورودی‌ها</a>

<div class="mi-steps">
  <div class="mi-step <?= $step === 'upload' ? 'active' : 'done' ?>">۱. آپلود فایل</div>
  <div class="mi-step <?= $step === 'confirm' ? 'active' : (in_array($step, ['new_customers','result'], true) ? 'done' : '') ?>">۲. بررسی فایل</div>
  <div class="mi-step <?= $step === 'new_customers' ? 'active' : ($step === 'result' ? 'done' : '') ?>">۳. مشتریان جدید</div>
  <div class="mi-step <?= $step === 'result' ? 'active' : '' ?>">۴. نتیجه</div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger py-2"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($step === 'upload'): ?>

<div class="card p-4 mb-3">
  <h6 class="mb-1"><i class="fa-solid fa-file-arrow-up"></i> آپلود اکسل پیگیری</h6>
  <p class="text-muted small mb-3">
    این بخش برای اکسل‌هایی است که خودتان به‌ازای هر مشتری، تاریخ تماس اول و پیگیری‌های بعدی را در ستون‌های جداگانه
    (مثل «تماس اول»، «ارتباط ۱» تا «ارتباط ۱۲» و «ارتباط آتی») ثبت کرده‌اید.
  </p>
  <form method="post" enctype="multipart/form-data" data-upload-progress>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <div class="row g-3 align-items-end">
      <div class="col-md-7">
        <label class="form-label">فایل اکسل (xlsx) یا CSV</label>
        <input type="file" name="manual_file" class="form-control" accept=".xlsx,.xlsm,.csv,.txt" required>
      </div>
      <div class="col-md-5">
        <label class="form-label">سال شمسی مبنا</label>
        <input type="text" name="base_year" class="form-control" dir="ltr" value="<?= to_persian_digits((string) $DEFAULT_BASE_YEAR) ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3"><i class="fa-solid fa-upload"></i> آپلود و بررسی فایل</button>
  </form>
</div>

<?php elseif ($step === 'confirm'): ?>

<?php
$data = $_SESSION[$SESS_UPLOAD];
$sheets = $data['sheets'];
?>

<div class="card p-4 mb-3">
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0"><i class="fa-solid fa-table-list text-primary"></i> بررسی فایل «<?= e($data['file_name']) ?>»</h6>
    <a href="manual_log_import.php?cancel=1" class="btn btn-sm btn-outline-secondary">انصراف و آپلود فایل جدید</a>
  </div>
  <p class="text-muted small mb-3">
    <?= to_persian_digits((string) count($sheets)) ?> شیت شناسایی شد؛ سال مبنا: <?= to_persian_digits((string) $data['base_year']) ?>.
  </p>

  <div class="table-responsive mb-3">
    <table class="table table-sm table-bordered mb-0">
      <thead class="table-light">
        <tr><th>شیت</th><th>تعداد ردیف</th><th>ستون شماره</th><th>ستون نام</th><th>تماس اول</th><th>ارتباط آتی</th><th>تعداد ارتباط N</th><th>نوع</th></tr>
      </thead>
      <tbody>
        <?php foreach ($sheets as $sheetName => $sheet): $c = $sheet['cols']; ?>
          <tr class="<?= $c['phone'] === null ? 'table-danger' : '' ?>">
            <td><?= e($sheetName) ?></td>
            <td><?= to_persian_digits((string) $sheet['row_count']) ?></td>
            <td><?= $c['phone'] !== null ? e($sheet['header'][$c['phone']]) : '— یافت نشد —' ?></td>
            <td><?= $c['name'] !== null ? e($sheet['header'][$c['name']]) : '-' ?></td>
            <td><?= $c['first'] !== null ? e($sheet['header'][$c['first']]) : '-' ?></td>
            <td><?= $c['next'] !== null ? e($sheet['header'][$c['next']]) : '-' ?></td>
            <td><?= to_persian_digits((string) count($c['artebat'])) ?></td>
            <td><?= ($c['first'] === null && $c['next'] === null && count($c['artebat']) === 0) ? 'لیست مشتریان' : 'اکسل پیگیری' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <form method="post" data-busy="در حالِ پردازش و ثبتِ اطلاعات…">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="process">
    <?php if ($canActAs):
        $__experts = $pdo->query("SELECT id, full_name, role, mobile FROM users WHERE is_active = 1 AND role IN ('A','B','C','leader','nonsales') ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) ?: []; ?>
      <div class="border rounded-3 p-3 mb-3" style="background:#fffbeb">
        <label class="form-label fw-bold small mb-1"><i class="fa-solid fa-user-tag text-warning"></i> ثبت برای کارشناس (اختیاری — فقط ادمین کل)</label>
        <select name="act_as_user_id" class="form-select form-select-sm" data-search>
          <option value="">— برای خودم —</option>
          <?php foreach ($__experts as $__x): ?>
            <option value="<?= (int) $__x['id'] ?>"><?= e($__x['full_name'] . ' — ' . $__x['role'] . ($__x['mobile'] ? ' — ' . $__x['mobile'] : '')) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="move_existing" id="move_existing" value="1" checked>
          <label class="form-check-label small" for="move_existing">مشتریانِ موجودِ این فایل (که الان در لیستِ کارشناسِ دیگری هستند) هم به لیستِ این کارشناس <b>منتقل شوند</b></label>
        </div>
        <div class="small text-muted mt-1">مشتری‌های جدید مستقیم به لیستِ کارشناسِ انتخاب‌شده می‌روند. مخاطبانِ «همکار/خانواده» منتقل نمی‌شوند.</div>
      </div>
    <?php endif; ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3 p-2 rounded-3" style="background:#fffbeb;border:1px solid #fde68a">
      <label class="mb-0 small fw-bold"><i class="fa-solid fa-layer-group text-warning"></i> تغییرِ یکجای وضعیتِ مشتریانِ <u>موجود</u> در این فایل:</label>
      <select name="existing_status" class="form-select form-select-sm" style="width:auto">
        <option value="">— بدونِ تغییر —</option>
        <?php foreach (unified_status_options() as $__s): ?><option value="<?= e($__s) ?>"><?= e($__s) ?></option><?php endforeach; ?>
      </select>
      <span class="small text-muted w-100">با «پردازش و ثبت اطلاعات» ثبت می‌شود. وضعیتِ مشتریانِ <u>جدید</u> را در مرحله‌ی بعد (یکجا یا تک‌تک) انتخاب می‌کنید. مشتریانِ وضعیت‌قفل و همکار/خانواده تغییر نمی‌کنند.</span>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="scope_all" id="scope_all" value="1" checked>
        <label class="form-check-label" for="scope_all">جستجوی شماره‌ها در مشتریان <b>همه کارشناس‌ها</b></label>
      </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check-double"></i> پردازش و ثبت اطلاعات</button>
  </form>
</div>

<?php elseif ($step === 'new_customers'): ?>

<?php
$stepData = $_SESSION[$SESS_NEWSTEP];
$candidates = $stepData['candidates'];
$statusOptionsForNew = status_options_for_role((string) ($stepData['stats']['act_as_role'] ?? '') ?: $user['role']);
?>

<div class="card p-4 mb-3">
  <h6 class="mb-1"><i class="fa-solid fa-user-plus text-primary"></i> مشتریان جدید یافت‌شده در فایل</h6>
  <p class="text-muted small mb-3">
    این <?= to_persian_digits((string) count($candidates)) ?> شماره در فایل شما وجود دارند اما هنوز به‌عنوان مشتری در سیستم ثبت نشده‌اند.
  </p>

  <div class="d-flex gap-2 mb-3 flex-wrap" id="newCustTopActions">
    <button type="button" class="btn btn-primary" id="newCustSubmitTopBtn"><i class="fa-solid fa-user-plus"></i> افزودن موارد انتخاب‌شده</button>
    <button type="button" class="btn btn-outline-secondary" id="newCustSkipTopBtn">هیچ‌کدام؛ رد شو</button>
  </div>
  <div id="newCustProgressBox" class="alert alert-info d-none">
    <i class="fa-solid fa-spinner fa-spin"></i> در حال افزودن مشتریان... (<span id="newCustProgressDone">۰</span> از <span id="newCustProgressTotal">۰</span>)
  </div>

  <form method="post" id="newCustForm" onsubmit="return false;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_new">
    <input type="hidden" name="do" id="newCustDo" value="create">
    <input type="hidden" name="payload" id="newCustPayload" value="[]">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.new-cust-check').forEach(c=>c.checked=true)">انتخاب همه</button>
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
          <tr><th style="width:40px"></th><th>شماره‌های موبایل</th><th>نام (قابل ویرایش)</th><th>شهر</th><th>تعداد پیگیری</th><th>وضعیت</th></tr>
        </thead>
        <tbody>
        <?php foreach ($candidates as $phoneKey => $cand): ?>
          <tr>
            <td><input class="form-check-input new-cust-check" type="checkbox" data-phone="<?= e($phoneKey) ?>" checked></td>
            <td dir="ltr">
              <?php foreach (($cand['phones'] ?? [$phoneKey]) as $pidx => $pnum): ?>
                <div><?= to_persian_digits('0' . ltrim((string) $pnum, '0')) ?></div>
              <?php endforeach; ?>
            </td>
            <td><input type="text" class="form-control form-control-sm new-cust-name" data-phone="<?= e($phoneKey) ?>" value="<?= e($cand['name']) ?>" placeholder="نام مشتری"></td>
            <td><?= e($cand['city'] ?: '-') ?></td>
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
      <button type="button" class="btn btn-primary" id="newCustSubmitBtn"><i class="fa-solid fa-user-plus"></i> افزودن موارد انتخاب‌شده</button>
      <button type="button" class="btn btn-outline-secondary" id="newCustSkipBtn">هیچ‌کدام؛ رد شو</button>
    </div>
  </form>
</div>

<script>
(function () {
  var form = document.getElementById('newCustForm');
  var csrfToken = form.querySelector('input[name="csrf_token"]').value;
  var CHUNK_SIZE = 75;

  function buildRows() {
    var rows = [];
    form.querySelectorAll('.new-cust-check').forEach(function (chk) {
      var phone = chk.getAttribute('data-phone');
      var safePhone = phone.replace(/"/g, '');
      var nameInput = form.querySelector('.new-cust-name[data-phone="' + safePhone + '"]');
      var statusSelect = form.querySelector('.new-cust-status[data-phone="' + safePhone + '"]');
      rows.push({ phone: phone, name: nameInput ? nameInput.value : '', status: statusSelect ? statusSelect.value : '', create: chk.checked });
    });
    return rows;
  }

  function setBusy(busy) {
    document.getElementById('newCustSubmitBtn').disabled = busy;
    document.getElementById('newCustSkipBtn').disabled = busy;
    document.getElementById('newCustSubmitTopBtn').disabled = busy;
    document.getElementById('newCustSkipTopBtn').disabled = busy;
    document.getElementById('newCustProgressBox').classList.toggle('d-none', !busy);
  }

  async function runCreate() {
    var rows = buildRows().filter(function (r) { return r.create; });
    if (!rows.length) { alert('هیچ موردی انتخاب نشده است.'); return; }
    setBusy(true);
    var total = rows.length;
    var done = 0;
    var totalCreated = 0;
    var totalSkipped = 0;
    document.getElementById('newCustProgressTotal').textContent = total;

    for (var i = 0; i < rows.length; i += CHUNK_SIZE) {
      var chunk = rows.slice(i, i + CHUNK_SIZE);
      var fd = new FormData();
      fd.append('csrf_token', csrfToken);
      fd.append('phones', JSON.stringify(chunk.map(function (r) { return r.phone; })));
      fd.append('payload', JSON.stringify(chunk));
      try {
        var res = await fetch('manual_import_create_chunk.php', { method: 'POST', body: fd });
        var data = await res.json();
        if (!data.ok) {
          alert('خطا در ثبت بخشی از مشتریان: ' + (data.error || 'نامشخص'));
          setBusy(false);
          return;
        }
        totalCreated += data.created;
        totalSkipped += data.skipped;
      } catch (err) {
        alert('ارتباط با سرور قطع شد.');
        setBusy(false);
        return;
      }
      done += chunk.length;
      document.getElementById('newCustProgressDone').textContent = done;
    }

    totalSkipped += buildRows().filter(function (r) { return !r.create; }).length;

    var fd2 = new FormData();
    fd2.append('csrf_token', csrfToken);
    fd2.append('created', totalCreated);
    fd2.append('skipped', totalSkipped);
    try { await fetch('manual_import_finalize.php', { method: 'POST', body: fd2 }); } catch (err) {}
    window.location.href = 'manual_log_import.php';
  }

  function runSkip() {
    setBusy(true);
    var totalRows = buildRows().length;
    var fd = new FormData();
    fd.append('csrf_token', csrfToken);
    fd.append('created', 0);
    fd.append('skipped', totalRows);
    fetch('manual_import_finalize.php', { method: 'POST', body: fd }).finally(function () {
      window.location.href = 'manual_log_import.php';
    });
  }

  document.getElementById('newCustSubmitBtn').addEventListener('click', runCreate);
  document.getElementById('newCustSubmitTopBtn').addEventListener('click', runCreate);
  document.getElementById('newCustSkipBtn').addEventListener('click', runSkip);
  document.getElementById('newCustSkipTopBtn').addEventListener('click', runSkip);
})();
</script>

<?php elseif ($step === 'result'): ?>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="card mi-stat"><div class="text-muted small">کل ردیف‌ها</div><div class="fs-3 fw-bold"><?= to_persian_digits((string) $result['total_rows']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card mi-stat"><div class="text-muted small">پیگیری‌های ثبت‌شده</div><div class="fs-3 fw-bold text-success"><?= to_persian_digits((string) $result['inserted']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card mi-stat"><div class="text-muted small">تکراری</div><div class="fs-3 fw-bold text-warning"><?= to_persian_digits((string) $result['duplicate']) ?></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card mi-stat"><div class="text-muted small">مشتریان جدید</div><div class="fs-3 fw-bold" style="color:#8a6a1e"><?= to_persian_digits((string) $result['created']) ?></div></div>
  </div>
</div>

<div class="card p-4 mb-3">
  <h6 class="mb-3"><i class="fa-solid fa-list-check text-primary"></i> خلاصه پردازش</h6>
  <ul class="mb-0">
    <li>از مجموع <?= to_persian_digits((string) $result['total_rows']) ?> ردیف، برای <?= to_persian_digits((string) $result['matched_customers']) ?> مورد مشتری پیدا شد.</li>
    <?php if (!empty($result['existing_status'])): ?>
      <li>وضعیتِ <?= to_persian_digits((string) (int) ($result['status_changed'] ?? 0)) ?> مشتریِ موجود به «<?= e((string) $result['existing_status']) ?>» تغییر کرد.</li>
    <?php endif; ?>
    <?php if (!empty($result['act_as_name'])): ?>
      <li>ثبت برای کارشناس: <b><?= e((string) $result['act_as_name']) ?></b><?= !empty($result['moved_customers']) ? ' — ' . to_persian_digits((string) $result['moved_customers']) . ' مشتریِ موجود به لیستِ او منتقل شد.' : '' ?></li>
    <?php endif; ?>
    <li><?= to_persian_digits((string) $result['inserted']) ?> پیگیری جدید ثبت شد.</li>
    <?php if ($result['duplicate'] > 0): ?>
      <li><?= to_persian_digits((string) $result['duplicate']) ?> ردیف تکراری نادیده گرفته شد.</li>
    <?php endif; ?>
    <?php if ($result['invalid_phone'] > 0): ?>
      <li><?= to_persian_digits((string) $result['invalid_phone']) ?> ردیف فاقد شماره معتبر بود.</li>
    <?php endif; ?>
    <?php if ($result['created'] > 0): ?>
      <li><?= to_persian_digits((string) $result['created']) ?> مشتری جدید اضافه شد.</li>
    <?php endif; ?>
    <?php if ($result['created_skipped'] > 0): ?>
      <li><?= to_persian_digits((string) $result['created_skipped']) ?> شماره ثبت نشد.</li>
    <?php endif; ?>
  </ul>
</div>

<a href="manual_log_import.php" class="btn btn-primary"><i class="fa-solid fa-rotate"></i> آپلود فایل بعدی</a>
<a href="customer_list.php" class="btn btn-outline-secondary"><i class="fa-solid fa-list-check"></i> مشاهده لیست مشتریان</a>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/upload_progress.php'; ?>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>