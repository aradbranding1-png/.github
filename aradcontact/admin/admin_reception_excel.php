<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/spreadsheet_reader.php';
require_once __DIR__ . '/../includes/reception_functions.php';
$admin = require_login();
if (!perm_page_allowed($admin)) {
    http_response_code(403);
    die('دسترسی به این بخش ندارید.');
}
$pdo = db();

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');
@ignore_user_abort(true);

$moduleReady   = reception_module_ready($pdo);
$workLocationReady = reception_column_exists($pdo, 'users', 'work_location');
$jobGroupReady = function_exists('users_job_group_ready') ? users_job_group_ready($pdo) : reception_column_exists($pdo, 'users', 'job_group');

$MAX_UPLOAD_BYTES = 20 * 1024 * 1024;
$errors = [];

$isAjax = isset($_POST['ajax_action']);
if ($moduleReady && $_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrf_verify()) {
        echo json_encode(['ok' => false, 'error' => 'نشست منقضی شده است.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $action = (string) $_POST['ajax_action'];

    // ============================================================
    //  prepare
    // ============================================================
    if ($action === 'prepare') {
        $assignmentMode = ($_POST['assignment_mode'] ?? 'agent') === 'shared' ? 'shared' : 'agent';
        $assignedAgentId = (int) ($_POST['assigned_agent_id'] ?? 0);

        if ($assignmentMode === 'agent' && $assignedAgentId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'در حالت «اختصاص به کارشناس»، باید یک کارشناس پذیرش فعال انتخاب کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($assignmentMode === 'agent' && $assignedAgentId > 0) {
            $st = $pdo->prepare("SELECT id FROM users WHERE id = ? AND service_access_role = 'reception_agent' AND is_active = 1 LIMIT 1");
            $st->execute([$assignedAgentId]);
            if (!$st->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'کارشناس انتخاب‌شده فعال نیست یا دسترسی پذیرش ندارد.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        if (empty($_FILES['reception_file']) || $_FILES['reception_file']['error'] === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['ok' => false, 'error' => 'لطفاً یک فایل اکسل یا CSV انتخاب کنید.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_FILES['reception_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'در بارگذاری فایل خطایی رخ داد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($_FILES['reception_file']['size'] > $MAX_UPLOAD_BYTES) {
            echo json_encode(['ok' => false, 'error' => 'حجم فایل بیشتر از حد مجاز (۲۰ مگابایت) است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $parseError = null;
        try {
            $rows = read_uploaded_spreadsheet($_FILES['reception_file']['tmp_name'], $_FILES['reception_file']['name'], $parseError);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'خواندن فایل با خطا مواجه شد: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($rows === null) {
            echo json_encode(['ok' => false, 'error' => $parseError ?: 'خواندن فایل ممکن نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (count($rows) < 2) {
            echo json_encode(['ok' => false, 'error' => 'فایل خالی است یا فقط سطر عنوان دارد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $header = array_shift($rows);
        $colFullName = null;
        $colMobile = null;
        foreach ($header as $idx => $h) {
            $h = trim(preg_replace('/[\x{200C}\s]+/u', ' ', (string) $h) ?? (string) $h);
            if ($h === 'نام و نام خانوادگی') { $colFullName = $idx; }
            elseif ($h === 'شماره موبایل' || $h === 'موبایل') { $colMobile = $idx; }
        }
        if ($colFullName === null || $colMobile === null) {
            echo json_encode(['ok' => false, 'error' => 'ستون‌های «نام و نام خانوادگی» و «شماره موبایل» در فایل پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $insImport = $pdo->prepare("INSERT INTO reception_excel_imports (admin_user_id, file_name, total_rows, success_count, duplicate_count, error_count, created_at) VALUES (?, ?, 0, 0, 0, 0, NOW())");
            $insImport->execute([(int) $admin['id'], $_FILES['reception_file']['name']]);
            $importId = (int) $pdo->lastInsertId();

            $insRow = $pdo->prepare("INSERT INTO reception_excel_import_rows (import_id, `row_number`, raw_full_name, raw_mobile, first_name, last_name, mobile, status, error_message, applicant_id, user_id) VALUES (?, ?, ?, ?, '', '', ?, 'pending', NULL, NULL, NULL)");

            $rowNumber = 1;
            $pendingCount = 0;
            foreach ($rows as $row) {
                $rowNumber++;
                if (!array_filter($row, static fn($v) => trim((string) $v) !== '')) {
                    continue;
                }
                $fullName  = trim((string) ($row[$colFullName] ?? ''));
                $fullName  = preg_replace('/\s+/u', ' ', $fullName) ?? $fullName;
                $rawMobile = trim((string) ($row[$colMobile] ?? ''));
                $insRow->execute([$importId, $rowNumber, $fullName, $rawMobile, $rawMobile]);
                $pendingCount++;
            }

            $pdo->prepare("UPDATE reception_excel_imports SET total_rows = ? WHERE id = ?")->execute([$pendingCount, $importId]);

            $pdo->commit();

            echo json_encode([
                'ok' => true,
                'import_id' => $importId,
                'total' => $pendingCount,
                'assignment_mode' => $assignmentMode,
                'assigned_agent_id' => $assignedAgentId,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['ok' => false, 'error' => 'خطا در آماده‌سازی صف: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // ============================================================
    //  process_batch
    // ============================================================
    if ($action === 'process_batch') {
        $importId = (int) ($_POST['import_id'] ?? 0);
        $assignedAgentId = (int) ($_POST['assigned_agent_id'] ?? 0);
        $assignmentMode = ($_POST['assignment_mode'] ?? 'agent') === 'shared' ? 'shared' : 'agent';
        $batchSize = max(1, min(50, (int) ($_POST['batch_size'] ?? 5)));

        if ($importId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'شناسه‌ی بارگذاری نامعتبر است.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $assignedAgentIdForInsert = ($assignmentMode === 'agent' && $assignedAgentId > 0) ? $assignedAgentId : null;

        $stmt = $pdo->prepare("SELECT id, `row_number`, raw_full_name, raw_mobile FROM reception_excel_import_rows WHERE import_id = ? AND status = 'pending' ORDER BY id ASC LIMIT {$batchSize}");
        $stmt->execute([$importId]);
        $pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$pendingRows) {
            $sum = $pdo->prepare("SELECT total_rows, success_count, duplicate_count, error_count FROM reception_excel_imports WHERE id = ?");
            $sum->execute([$importId]);
            $s = $sum->fetch(PDO::FETCH_ASSOC) ?: ['total_rows'=>0,'success_count'=>0,'duplicate_count'=>0,'error_count'=>0];
            echo json_encode([
                'ok' => true,
                'done' => true,
                'stats' => [
                    'total' => (int)$s['total_rows'],
                    'success' => (int)$s['success_count'],
                    'duplicate' => (int)$s['duplicate_count'],
                    'error' => (int)$s['error_count'],
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $insUser = $pdo->prepare("INSERT INTO users (full_name, mobile, password_hash, role, team_id, is_approved, is_active) VALUES (?, ?, ?, 'A', NULL, 1, 1)");
        $insApplicant = $pdo->prepare("INSERT INTO reception_applicants (first_name, last_name, mobile, mobile_normalized, source, status, user_id, assigned_agent_id, assigned_at, created_at, updated_at) VALUES (?, ?, ?, ?, 'excel', 'new', ?, ?, ?, NOW(), NOW())");
        $insPhone = $pdo->prepare("INSERT INTO reception_applicant_phones (applicant_id, phone, phone_normalized, is_primary, created_at) VALUES (?, ?, ?, 1, NOW())");
        $updRow = $pdo->prepare("UPDATE reception_excel_import_rows SET first_name=?, last_name=?, status=?, error_message=?, applicant_id=?, user_id=?, processed_at=NOW() WHERE id=?");
        $checkUser = $pdo->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
        $checkApplicant = $pdo->prepare('SELECT id FROM reception_applicants WHERE mobile_normalized = ? LIMIT 1');
        $updJobGroup = $jobGroupReady ? $pdo->prepare('UPDATE users SET job_group = ? WHERE id = ?') : null;
        $updWorkLoc = $workLocationReady ? $pdo->prepare("UPDATE users SET work_location = 'remote' WHERE id = ?") : null;

        $batchSuccess = 0; $batchDup = 0; $batchErr = 0;
        $processed = [];

        foreach ($pendingRows as $prow) {
            $rowId = (int)$prow['id'];
            $rowNumber = (int)$prow['row_number'];
            $fullName = (string)$prow['raw_full_name'];
            $rawMobile = (string)$prow['raw_mobile'];
            $normalized = reception_normalize_mobile($rawMobile);

            if (strpos($fullName, ' ') !== false) {
                [$firstName, $lastName] = explode(' ', $fullName, 2);
            } else {
                $firstName = $fullName;
                $lastName = '';
            }

            $status = 'success'; $errorMessage = null;
            $newApplicantId = null; $newUserId = null;

            // اعتبارسنجی
            $validationError = null;
            if ($fullName === '' || $fullName === '0' || preg_match('/^0+$/', $fullName)) {
                $validationError = 'نام خالی یا نامعتبر';
            } elseif (mb_strlen($fullName, 'UTF-8') > 150) {
                $validationError = 'نام طولانی‌تر از حد مجاز';
            } elseif (!reception_is_valid_mobile($normalized)) {
                $validationError = 'شماره موبایل معتبر نیست';
            } elseif (mb_strlen($rawMobile, 'UTF-8') > 15) {
                $validationError = 'شماره موبایل چندمقداری';
            } elseif (strpos($rawMobile, '#') !== false) {
                $validationError = 'شماره موبایل حاوی #';
            }

            if ($validationError !== null) {
                $status = 'error';
                $errorMessage = $validationError;
                $batchErr++;
            } else {
                // ============================================================
                //  ✅ منطق جدید: بررسی کامل وضعیت
                // ============================================================
                $existingUserId = false;
                $existingApplicantId = false;
                $existingApplicantUserId = null;

                try {
                    $checkUser->execute([$normalized]);
                    $existingUserId = $checkUser->fetchColumn();
                } catch (Throwable $e) {}

                try {
                    $checkApplicant->execute([$normalized]);
                    $existingApplicantId = $checkApplicant->fetchColumn();
                } catch (Throwable $e) {}

                // حالت ۱: هم user هست هم applicant → واقعاً تکراری
                if ($existingUserId !== false && $existingApplicantId !== false) {
                    $status = 'duplicate';
                    $errorMessage = 'شماره موبایل و متقاضی قبلاً ثبت شده';
                    $batchDup++;
                }
                // حالت ۲: user هست ولی applicant نیست → user رو دوباره استفاده کن، applicant بساز
                elseif ($existingUserId !== false && $existingApplicantId === false) {
                    try {
                        $pdo->beginTransaction();

                        $newUserId = (int) $existingUserId;

                        // applicant بساز
                        if ($assignedAgentIdForInsert !== null) {
                            $insApplicant->execute([$firstName, $lastName, $rawMobile, $normalized, $newUserId, $assignedAgentIdForInsert, date('Y-m-d H:i:s')]);
                        } else {
                            $insApplicant->execute([$firstName, $lastName, $rawMobile, $normalized, $newUserId, null, null]);
                        }
                        $newApplicantId = (int) $pdo->lastInsertId();

                        // phone بساز (اگه تکراری بود، نادیده بگیر)
                        try {
                            $insPhone->execute([$newApplicantId, $rawMobile, $normalized]);
                        } catch (PDOException $e) {
                            // بی‌خیال
                        }

                        $logNote = 'ایجاد متقاضی برای کاربر موجود (#' . $importId . ')';
                        try { reception_log_action($pdo, $newApplicantId, (int) $admin['id'], 'excel_import', $logNote); } catch (Throwable $e) {}

                        $pdo->commit();
                        $batchSuccess++;
                        $status = 'success';
                        $errorMessage = null;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
                        $status = 'error';
                        $errorMessage = 'خطا در ساخت applicant: ' . mb_substr($e->getMessage(), 0, 60);
                        $batchErr++;
                        $newApplicantId = null; $newUserId = null;
                    }
                }
                // حالت ۳: applicant هست ولی user نیست (عجیب)
                elseif ($existingUserId === false && $existingApplicantId !== false) {
                    $status = 'duplicate';
                    $errorMessage = 'متقاضی قبلاً ثبت شده';
                    $batchDup++;
                }
                // حالت ۴: هیچ‌کدوم نیستن → همه چیز رو از صفر بساز
                else {
                    try {
                        $pdo->beginTransaction();

                        $password = reception_password_from_mobile($normalized);
                        $insUser->execute([$fullName, $normalized, password_hash($password, PASSWORD_BCRYPT)]);
                        $newUserId = (int) $pdo->lastInsertId();

                        if ($updJobGroup) $updJobGroup->execute(['توسعه', $newUserId]);
                        if ($updWorkLoc) $updWorkLoc->execute([$newUserId]);

                        if ($assignedAgentIdForInsert !== null) {
                            $insApplicant->execute([$firstName, $lastName, $rawMobile, $normalized, $newUserId, $assignedAgentIdForInsert, date('Y-m-d H:i:s')]);
                        } else {
                            $insApplicant->execute([$firstName, $lastName, $rawMobile, $normalized, $newUserId, null, null]);
                        }
                        $newApplicantId = (int) $pdo->lastInsertId();

                        try {
                            $insPhone->execute([$newApplicantId, $rawMobile, $normalized]);
                        } catch (PDOException $e) {
                            // بی‌خیال
                        }

                        $logNote = 'ایجاد کامل از اکسل (#' . $importId . ')';
                        try { reception_log_action($pdo, $newApplicantId, (int) $admin['id'], 'excel_import', $logNote); } catch (Throwable $e) {}

                        $pdo->commit();
                        $batchSuccess++;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
                        $status = 'error';
                        $errorMessage = 'خطای سیستمی: ' . mb_substr($e->getMessage(), 0, 60);
                        $batchErr++;
                        $newApplicantId = null; $newUserId = null;
                    }
                }
            }

            try { $updRow->execute([$firstName, $lastName, $status, $errorMessage, $newApplicantId, $newUserId, $rowId]); } catch (Throwable $e) {}

            $processed[] = [
                'row' => $rowNumber,
                'full_name' => $fullName,
                'mobile' => $rawMobile,
                'status' => $status,
                'error' => $errorMessage,
            ];
        }

        try {
            $pdo->prepare("UPDATE reception_excel_imports SET success_count = success_count + ?, duplicate_count = duplicate_count + ?, error_count = error_count + ? WHERE id = ?")
                ->execute([$batchSuccess, $batchDup, $batchErr, $importId]);
        } catch (Throwable $e) {}

        $remStmt = $pdo->prepare("SELECT COUNT(*) FROM reception_excel_import_rows WHERE import_id = ? AND status = 'pending'");
        $remStmt->execute([$importId]);
        $remaining = (int) $remStmt->fetchColumn();

        echo json_encode([
            'ok' => true,
            'done' => $remaining === 0,
            'processed' => $processed,
            'remaining' => $remaining,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    //  resume_import
    // ============================================================
    if ($action === 'resume_import') {
        $importId = (int) ($_POST['import_id'] ?? 0);
        if ($importId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'شناسه نامعتبر.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $st = $pdo->prepare("SELECT id FROM reception_excel_imports WHERE id = ? LIMIT 1");
        $st->execute([$importId]);
        if (!$st->fetchColumn()) {
            echo json_encode(['ok' => false, 'error' => 'import پیدا نشد.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rem = $pdo->prepare("SELECT COUNT(*) FROM reception_excel_import_rows WHERE import_id = ? AND status = 'pending'");
        $rem->execute([$importId]);
        $remaining = (int) $rem->fetchColumn();
        echo json_encode(['ok' => true, 'import_id' => $importId, 'remaining' => $remaining], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'عملیات نامعتبر.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
//  بررسی import ناتمام
// ============================================================
$unfinishedImport = null;
if ($moduleReady) {
    try {
        $st2 = $pdo->prepare("SELECT id, file_name, total_rows, success_count, duplicate_count, error_count FROM reception_excel_imports WHERE (success_count + duplicate_count + error_count) < total_rows ORDER BY id DESC LIMIT 1");
        $st2->execute();
        $unfinishedImport = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $unfinishedImport = null;
    }
}

$importHistory = [];
if ($moduleReady) {
    try {
        $stmt = $pdo->prepare("SELECT ei.*, u.full_name AS admin_name FROM reception_excel_imports ei LEFT JOIN users u ON u.id = ei.admin_user_id ORDER BY ei.created_at DESC LIMIT 20");
        $stmt->execute();
        $importHistory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $importHistory = [];
    }
}

$receptionAgents = [];
if ($moduleReady) {
    try {
        $stmtAgents = $pdo->query("SELECT id, full_name, mobile FROM users WHERE service_access_role = 'reception_agent' AND is_active = 1 ORDER BY full_name ASC");
        $receptionAgents = $stmtAgents->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $receptionAgents = [];
    }
}

$pageTitle = 'اکسل ورودی';
require_once __DIR__ . '/../includes/layout_top.php';
?>

<style>
.rei-page{--rei-line:#e7e2d3;--rei-ink:#1c1917;--rei-muted:#78716c;--rei-gold:#c9a24b;--rei-gold-2:#f1dfa8;}
.rei-page .admin-page-header h5{display:flex;align-items:center;gap:.55rem;font-weight:800;color:var(--rei-ink)}
.rei-page .admin-page-header h5 i{width:34px;height:34px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rei-gold) 130%);color:#fff;font-size:.85rem;}
.rei-page .card{border:1px solid var(--rei-line);border-radius:16px;box-shadow:0 4px 16px -14px rgba(28,25,23,.3)}
.rei-page .card h6{font-weight:800;color:var(--rei-ink)}
.rei-page .card h6 i{color:var(--rei-gold)}
.rei-page .btn-primary{background:linear-gradient(135deg,var(--rei-gold-2),var(--rei-gold));border:none;color:#241708;font-weight:700;box-shadow:0 8px 18px -10px rgba(201,162,75,.6);}
.rei-page .btn-primary:hover{filter:brightness(.97);color:#241708}
.rei-page .btn-outline-secondary{border-color:var(--rei-line);color:var(--rei-ink)}
.rei-page .stat-mini{border:1px solid var(--rei-line);border-radius:14px;box-shadow:0 4px 14px -12px rgba(28,25,23,.3);transition:.15s ease}
.rei-page .stat-mini:hover{box-shadow:0 10px 20px -14px rgba(28,25,23,.35);transform:translateY(-2px)}
.rei-page .stat-mini .fs-4{font-weight:800}
.rei-page table thead th{background:linear-gradient(135deg,#0b0f1a 0%,#3d3220 55%,var(--rei-gold) 130%);color:#f6efdd;border-color:transparent;}
.rei-page .badge-success-soft{background:#e7f6ec;color:#1d7a3e}
.rei-page .badge-danger-soft{background:#fbe9e9;color:#a3282f}
.rei-page .badge-warning-soft{background:#fdf3d9;color:#8a6a1e}
.rei-page .badge-info-soft{background:#e7f0fb;color:#1f4e8c}
.rei-page .rei-agent-picker{position:relative;max-width:760px}
.rei-page .rei-agent-search-wrap{position:relative}
.rei-page .rei-agent-search{height:46px;padding-right:42px;border:1px solid var(--rei-line);border-radius:12px;background:#fff;box-shadow:0 3px 10px -9px rgba(28,25,23,.35)}
.rei-page .rei-agent-search:focus{border-color:var(--rei-gold);box-shadow:0 0 0 3px rgba(201,162,75,.14)}
.rei-page .rei-agent-search-icon{position:absolute;right:15px;top:50%;transform:translateY(-50%);z-index:2;color:#8a6d2c;pointer-events:none}
.rei-page .rei-agent-results{position:absolute;z-index:30;right:0;left:0;top:50px;background:#fff;border:1px solid var(--rei-line);border-radius:12px;box-shadow:0 14px 30px -18px rgba(28,25,23,.45);overflow:hidden}
.rei-page .rei-agent-result{display:flex;align-items:center;justify-content:space-between;gap:12px;width:100%;padding:11px 14px;border:0;border-bottom:1px solid #f1eee6;background:#fff;text-align:right;cursor:pointer;transition:.12s ease}
.rei-page .rei-agent-result:last-child{border-bottom:0}
.rei-page .rei-agent-result:hover{background:#fbf6e8}
.rei-page .rei-agent-result-main{display:flex;align-items:center;gap:9px;min-width:0}
.rei-page .rei-agent-avatar{width:30px;height:30px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--rei-gold-2),var(--rei-gold));color:#241708;flex:0 0 30px}
.rei-page .rei-agent-name{font-weight:700;color:var(--rei-ink)}
.rei-page .rei-agent-mobile{font-size:.78rem;color:var(--rei-muted);direction:ltr;white-space:nowrap}
.rei-page .rei-agent-empty{padding:13px 14px;color:var(--rei-muted);font-size:.85rem;text-align:center}
.rei-page .rei-selected-agent{margin-top:9px}
.rei-page .rei-selected-agent-card{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #e5d5aa;border-radius:12px;background:linear-gradient(135deg,#fffdf7,#f8edcf)}
.rei-page .rei-selected-agent-info{display:flex;align-items:center;gap:9px}
.rei-page .rei-clear-agent{border:0;background:transparent;color:#8a6d2c;width:32px;height:32px;border-radius:8px;cursor:pointer}
.rei-page .rei-clear-agent:hover{background:#f0dfb5}
.rei-page .rei-mode-box{border:1px solid var(--rei-line);border-radius:14px;padding:14px;background:#fff;transition:.15s ease}
.rei-page .rei-mode-box.active{border-color:var(--rei-gold);background:linear-gradient(135deg,#fffdf7,#fbf3dd);box-shadow:0 6px 18px -14px rgba(201,162,75,.7)}
.rei-page .rei-mode-box .form-check-label{font-weight:700;color:var(--rei-ink);cursor:pointer}
.rei-page .rei-mode-box .rei-mode-desc{font-size:.8rem;color:var(--rei-muted);margin-top:4px}
.rei-page .rei-mode-section[hidden]{display:none!important}
.rei-page .rei-progress-card{border:1px solid var(--rei-line);border-radius:16px;padding:20px;background:linear-gradient(135deg,#fffdf7,#fbf3dd)}
.rei-page .rei-progress-title{font-weight:800;color:var(--rei-ink);display:flex;align-items:center;gap:8px;margin-bottom:12px}
.rei-page .rei-progress-bar-wrap{height:22px;background:#f1eee6;border-radius:999px;overflow:hidden;box-shadow:inset 0 1px 3px rgba(28,25,23,.08);position:relative}
.rei-page .rei-progress-bar{height:100%;width:0;background:linear-gradient(90deg,var(--rei-gold-2),var(--rei-gold));transition:width .35s ease;display:flex;align-items:center;justify-content:center;color:#241708;font-size:.75rem;font-weight:800;}
.rei-page .rei-progress-meta{display:flex;justify-content:space-between;margin-top:10px;font-size:.85rem;color:var(--rei-muted)}
.rei-page .rei-progress-num{font-weight:800;color:var(--rei-ink)}
.rei-page .rei-progress-spinner{display:inline-block;width:16px;height:16px;border:2px solid #e5d5aa;border-top-color:var(--rei-gold);border-radius:50%;animation:rei-spin .8s linear infinite;vertical-align:middle;}
@keyframes rei-spin{to{transform:rotate(360deg)}}
.rei-page .rei-live-stats{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;font-size:.85rem}
.rei-page .rei-live-stats span{display:inline-flex;align-items:center;gap:5px}
.rei-page .rei-live-stats .dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.rei-page .rei-live-stats .dot-ok{background:#1d7a3e}
.rei-page .rei-live-stats .dot-dup{background:#8a6a1e}
.rei-page .rei-live-stats .dot-err{background:#a3282f}
</style>

<div class="rei-page">
<div class="admin-page-header d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="fa-solid fa-file-excel"></i> اکسل ورودی</h5>
  <a href="admin_reception_hub.php" class="btn btn-sm btn-outline-secondary">بازگشت به پذیرش کارشناس</a>
</div>

<?php if (!$moduleReady): ?>
  <div class="alert alert-warning py-2">جدول‌های ماژولِ پذیرش کارشناس هنوز روی سرور ایجاد نشده‌اند؛ ابتدا بروزرسانیِ سیستم را اجرا کنید.</div>
<?php endif; ?>

<?php if ($unfinishedImport): ?>
  <div class="alert alert-warning py-3" id="reiResumeBanner">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <i class="fa-solid fa-triangle-exclamation"></i>
        <strong>یک بارگذاری ناتمام وجود دارد:</strong>
        فایل «<?= e($unfinishedImport['file_name']) ?>» —
        <?= to_persian_digits((string)$unfinishedImport['success_count']) ?> موفق،
        <?= to_persian_digits((string)$unfinishedImport['duplicate_count']) ?> تکراری،
        <?= to_persian_digits((string)$unfinishedImport['error_count']) ?> خطا
        از <?= to_persian_digits((string)$unfinishedImport['total_rows']) ?>
      </div>
      <button type="button" class="btn btn-sm btn-primary" id="reiResumeBtn" data-import-id="<?= (int)$unfinishedImport['id'] ?>">
        <i class="fa-solid fa-play"></i> ادامه‌ی پردازش
      </button>
    </div>
  </div>
<?php endif; ?>

<div id="reiGlobalErrors"></div>

<div class="card p-4 mb-4" id="reiUploadCard">
  <p class="text-muted small mb-3">
    این بخش برای ثبت ورودی افراد متقاضی فعالیت در بخش کارشناس توسعه تجارت می‌باشد.
    فایل باید سطرِ اول عنوانِ ستون‌ها باشد و دقیقاً شاملِ این دو ستون باشد: <b>نام و نام خانوادگی</b>، <b>شماره موبایل</b>.
  </p>

  <form id="reiForm" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="mb-3">
      <label class="form-label fw-bold">حالتِ ثبت شماره‌ها</label>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="rei-mode-box active" id="reiModeBoxAgent">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="assignment_mode" id="reiModeAgent" value="agent" checked>
              <label class="form-check-label" for="reiModeAgent">
                <i class="fa-solid fa-user-check text-warning"></i> اختصاص به کارشناس خاص
              </label>
              <div class="rei-mode-desc">شماره‌ها فقط به کارشناس انتخاب‌شده اختصاص داده می‌شوند.</div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="rei-mode-box" id="reiModeBoxShared">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="assignment_mode" id="reiModeShared" value="shared">
              <label class="form-check-label" for="reiModeShared">
                <i class="fa-solid fa-users text-warning"></i> بانک مشترک (همه کارشناسان)
              </label>
              <div class="rei-mode-desc">همه‌ی کارشناسان فعال می‌توانند شماره‌ها را بردارند.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="mb-3 rei-mode-section" id="reiAgentSection">
      <label class="form-label fw-bold">انتساب شماره‌ها به کارشناس</label>
      <div class="rei-agent-picker" id="reiAgentPicker">
        <input type="hidden" name="assigned_agent_id" id="reiAssignedAgentId" value="0">
        <div class="rei-agent-search-wrap">
          <i class="fa-solid fa-magnifying-glass rei-agent-search-icon"></i>
          <input type="text" id="reiAgentSearch" class="form-control rei-agent-search" placeholder="نام یا نام خانوادگی کارشناس را جستجو کنید..." autocomplete="off" <?= (!$moduleReady || !$receptionAgents) ? 'disabled' : '' ?>>
        </div>
        <div class="rei-agent-results" id="reiAgentResults" hidden></div>
        <div class="rei-selected-agent" id="reiSelectedAgent" hidden></div>
      </div>
      <?php if (!$receptionAgents): ?>
        <div class="text-danger small mt-1">هیچ کارشناس فعال پذیرش وجود ندارد. می‌توانید «بانک مشترک» را انتخاب کنید.</div>
      <?php endif; ?>
    </div>

    <div class="mb-3 rei-mode-section" id="reiSharedSection" hidden>
      <div class="alert alert-info py-2 mb-0 small">
        <i class="fa-solid fa-circle-info"></i> در این حالت شماره‌ها به هیچ کارشناسی اختصاص داده نمی‌شوند.
      </div>
    </div>

    <div class="mb-3">
      <input type="file" name="reception_file" id="reiFileInput" class="form-control" accept=".xlsx,.xls,.csv" required <?= !$moduleReady ? 'disabled' : '' ?>>
    </div>

    <button type="submit" class="btn btn-primary" id="reiSubmitBtn" <?= !$moduleReady ? 'disabled' : '' ?>>
      <i class="fa-solid fa-upload"></i> بارگذاری و پردازش
    </button>
  </form>
</div>

<div class="card p-4 mb-4 rei-progress-card" id="reiProgressCard" hidden>
  <div class="rei-progress-title">
    <span class="rei-progress-spinner" id="reiSpinner"></span>
    <span id="reiProgressTitle">در حال پردازش فایل...</span>
  </div>
  <div class="rei-progress-bar-wrap">
    <div class="rei-progress-bar" id="reiProgressBar">0%</div>
  </div>
  <div class="rei-progress-meta">
    <div>پردازش‌شده: <span class="rei-progress-num" id="reiDoneCount">0</span> از <span class="rei-progress-num" id="reiTotalCount">0</span></div>
    <div id="reiPercentText">0%</div>
  </div>
  <div class="rei-live-stats">
    <span><span class="dot dot-ok"></span> موفق: <b id="reiOkCount">0</b></span>
    <span><span class="dot dot-dup"></span> تکراری: <b id="reiDupCount">0</b></span>
    <span><span class="dot dot-err"></span> خطا: <b id="reiErrCount">0</b></span>
  </div>
</div>

<div id="reiResultCard" hidden></div>

<div class="card p-4">
  <h6 class="mb-3"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچه‌ی بارگذاری‌ها</h6>
  <?php if (!$importHistory): ?>
    <p class="text-muted small mb-0">هنوز هیچ فایلی بارگذاری نشده است.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>تاریخ</th><th>نامِ فایل</th><th>بارگذارنده</th><th>کل</th><th>موفق</th><th>تکراری</th><th>خطا</th></tr></thead>
        <tbody>
          <?php foreach ($importHistory as $h): ?>
          <tr>
            <td class="small text-muted"><?= to_jalali($h['created_at']) ?></td>
            <td><?= e($h['file_name']) ?></td>
            <td><?= e($h['admin_name'] ?? '—') ?></td>
            <td><?= to_persian_digits((string) $h['total_rows']) ?></td>
            <td class="text-success"><?= to_persian_digits((string) $h['success_count']) ?></td>
            <td class="text-warning"><?= to_persian_digits((string) $h['duplicate_count']) ?></td>
            <td class="text-danger"><?= to_persian_digits((string) $h['error_count']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

</div>

<script>
(function(){
  const agentSection = document.getElementById('reiAgentSection');
  const sharedSection = document.getElementById('reiSharedSection');
  const modeAgent = document.getElementById('reiModeAgent');
  const modeShared = document.getElementById('reiModeShared');
  const boxAgent = document.getElementById('reiModeBoxAgent');
  const boxShared = document.getElementById('reiModeBoxShared');

  function applyMode(){
    const isShared = modeShared && modeShared.checked;
    if (agentSection) agentSection.hidden = isShared;
    if (sharedSection) sharedSection.hidden = !isShared;
    if (boxAgent) boxAgent.classList.toggle('active', !isShared);
    if (boxShared) boxShared.classList.toggle('active', isShared);
  }
  if (modeAgent && modeShared){
    modeAgent.addEventListener('change', applyMode);
    modeShared.addEventListener('change', applyMode);
    applyMode();
  }

  const picker = document.getElementById('reiAgentPicker');
  const search = document.getElementById('reiAgentSearch');
  const results = document.getElementById('reiAgentResults');
  const hidden = document.getElementById('reiAssignedAgentId');
  const selected = document.getElementById('reiSelectedAgent');
  const agents = <?= json_encode(array_map(static function($a){ return ['id'=>(int)$a['id'], 'name'=>(string)$a['full_name'], 'mobile'=>(string)($a['mobile'] ?? '')]; }, $receptionAgents), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  function esc(v){ return String(v ?? '').replace(/[&<>'"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]; }); }
  function closeResults(){ results.hidden = true; results.innerHTML = ''; }
  function selectAgent(agent){
    hidden.value = agent.id;
    search.value = '';
    selected.hidden = false;
    selected.innerHTML = '<div class="rei-selected-agent-card">' +
      '<div class="rei-selected-agent-info"><span class="rei-agent-avatar"><i class="fa-solid fa-user"></i></span>' +
      '<div><div class="rei-agent-name">'+esc(agent.name)+'</div>' +
      (agent.mobile ? '<div class="rei-agent-mobile">'+esc(agent.mobile)+'</div>' : '') + '</div></div>' +
      '<button type="button" class="rei-clear-agent" aria-label="حذف انتخاب"><i class="fa-solid fa-xmark"></i></button></div>';
    selected.querySelector('.rei-clear-agent').addEventListener('click', clearAgent);
    closeResults();
  }
  function clearAgent(){ hidden.value = ''; selected.hidden = true; selected.innerHTML = ''; search.focus(); }
  function renderResults(){
    const q = search.value.trim().toLocaleLowerCase('fa-IR');
    if (!q){ closeResults(); return; }
    const matches = agents.filter(function(a){ const hay = (a.name + ' ' + a.mobile).toLocaleLowerCase('fa-IR'); return hay.indexOf(q) !== -1; }).slice(0,5);
    if (!matches.length){ results.innerHTML = '<div class="rei-agent-empty">کارشناس موردنظر پیدا نشد</div>'; results.hidden = false; return; }
    results.innerHTML = matches.map(function(a){
      return '<button type="button" class="rei-agent-result" data-agent-id="'+a.id+'">' +
        '<span class="rei-agent-result-main"><span class="rei-agent-avatar"><i class="fa-solid fa-user"></i></span><span class="rei-agent-name">'+esc(a.name)+'</span></span>' +
        (a.mobile ? '<span class="rei-agent-mobile">'+esc(a.mobile)+'</span>' : '') + '</button>';
    }).join('');
    results.querySelectorAll('.rei-agent-result').forEach(function(btn){
      btn.addEventListener('click', function(){
        const agent = agents.find(function(a){ return String(a.id) === btn.dataset.agentId; });
        if (agent) selectAgent(agent);
      });
    });
    results.hidden = false;
  }
  if (picker && search) {
    search.addEventListener('input', renderResults);
    search.addEventListener('focus', function(){ if (search.value.trim()) renderResults(); });
    document.addEventListener('click', function(e){ if (!picker.contains(e.target)) closeResults(); });
  }

  const form = document.getElementById('reiForm');
  const uploadCard = document.getElementById('reiUploadCard');
  const progressCard = document.getElementById('reiProgressCard');
  const progressBar = document.getElementById('reiProgressBar');
  const progressTitle = document.getElementById('reiProgressTitle');
  const doneCount = document.getElementById('reiDoneCount');
  const totalCount = document.getElementById('reiTotalCount');
  const percentText = document.getElementById('reiPercentText');
  const okCount = document.getElementById('reiOkCount');
  const dupCount = document.getElementById('reiDupCount');
  const errCount = document.getElementById('reiErrCount');
  const spinner = document.getElementById('reiSpinner');
  const submitBtn = document.getElementById('reiSubmitBtn');
  const resultCard = document.getElementById('reiResultCard');
  const globalErrors = document.getElementById('reiGlobalErrors');

  function setProgress(done, total, stats){
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    progressBar.style.width = pct + '%';
    progressBar.textContent = pct + '%';
    percentText.textContent = pct + '%';
    doneCount.textContent = done;
    totalCount.textContent = total;
    if (stats){
      okCount.textContent = stats.success || 0;
      dupCount.textContent = stats.duplicate || 0;
      errCount.textContent = stats.error || 0;
    }
  }
  function showError(msg){ globalErrors.innerHTML = '<div class="alert alert-danger py-2">'+esc(msg)+'</div>'; window.scrollTo({top: 0, behavior: 'smooth'}); }
  function clearErrors(){ globalErrors.innerHTML = ''; }

  async function postWithRetry(url, fd, maxRetries){
    maxRetries = maxRetries || 5;
    let lastErr = null;
    for (let attempt = 1; attempt <= maxRetries; attempt++){
      try {
        const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        return data;
      } catch (e) {
        lastErr = e;
        if (attempt < maxRetries) await new Promise(r => setTimeout(r, 800 * attempt));
      }
    }
    throw lastErr;
  }

  async function runBatches(importId, total, opts){
    opts = opts || {};
    const BATCH_SIZE = 5;
    const mode = opts.mode || 'shared';
    const agentId = opts.agentId || 0;
    let done = 0;
    let stats = { success: 0, duplicate: 0, error: 0 };
    const allRows = [];
    let finished = false;
    let consecutiveFailures = 0;
    const MAX_CONSECUTIVE_FAILURES = 15;

    setProgress(0, total, stats);
    while (!finished){
      let batch;
      try {
        const bFd = new FormData();
        bFd.append('ajax_action', 'process_batch');
        bFd.append('import_id', String(importId));
        bFd.append('assignment_mode', mode);
        bFd.append('assigned_agent_id', String(agentId));
        bFd.append('batch_size', String(BATCH_SIZE));
        const csrfInput = form.querySelector('input[name="csrf_token"]');
        if (csrfInput) bFd.append('csrf_token', csrfInput.value);
        batch = await postWithRetry(window.location.href, bFd, 5);
        consecutiveFailures = 0;
      } catch (e) {
        consecutiveFailures++;
        if (consecutiveFailures >= MAX_CONSECUTIVE_FAILURES){
          return { finished: false, done, stats, rows: allRows, error: 'ارتباط قطع شد.' };
        }
        await new Promise(r => setTimeout(r, 1500));
        continue;
      }
      if (!batch.ok) return { finished: false, done, stats, rows: allRows, error: batch.error || 'خطا.' };

      if (batch.processed && batch.processed.length){
        for (const row of batch.processed){
          done++;
          if (row.status === 'success') stats.success++;
          else if (row.status === 'duplicate') stats.duplicate++;
          else stats.error++;
          allRows.push(row);
        }
      }
      if (batch.done){
        finished = true;
        if (batch.stats){ stats = { success: batch.stats.success, duplicate: batch.stats.duplicate, error: batch.stats.error }; done = batch.stats.total; }
      }
      setProgress(done, total, stats);
      await new Promise(r => setTimeout(r, 100));
    }
    return { finished: true, done, stats, rows: allRows, error: null };
  }

  form.addEventListener('submit', async function(ev){
    ev.preventDefault();
    clearErrors();
    resultCard.hidden = true;
    resultCard.innerHTML = '';

    const mode = (modeShared && modeShared.checked) ? 'shared' : 'agent';
    const agentId = parseInt(hidden.value || '0', 10);

    if (mode === 'agent' && agentId <= 0){ showError('لطفاً یک کارشناس انتخاب کنید یا حالت «بانک مشترک» را برگزینید.'); return; }
    if (!form.reception_file.files || !form.reception_file.files[0]){ showError('لطفاً یک فایل انتخاب کنید.'); return; }

    submitBtn.disabled = true;
    progressCard.hidden = false;
    progressTitle.textContent = 'در حال آماده‌سازی صف...';
    spinner.style.display = 'inline-block';
    setProgress(0, 0, {success:0, duplicate:0, error:0});

    let prepared;
    try {
      const prepFd = new FormData();
      prepFd.append('ajax_action', 'prepare');
      prepFd.append('assignment_mode', mode);
      prepFd.append('assigned_agent_id', String(agentId));
      prepFd.append('reception_file', form.reception_file.files[0]);
      const csrfInput = form.querySelector('input[name="csrf_token"]');
      if (csrfInput) prepFd.append('csrf_token', csrfInput.value);
      prepared = await postWithRetry(window.location.href, prepFd, 3);
    } catch (e) {
      spinner.style.display = 'none';
      progressTitle.textContent = 'خطا در ارتباط با سرور';
      showError('ارتباط با سرور برقرار نشد.');
      submitBtn.disabled = false;
      return;
    }

    if (!prepared.ok){ spinner.style.display = 'none'; progressCard.hidden = true; showError(prepared.error || 'خطا در آماده‌سازی.'); submitBtn.disabled = false; return; }

    progressTitle.textContent = 'در حال پردازش شماره‌ها...';
    const result = await runBatches(prepared.import_id, prepared.total, { mode, agentId });

    spinner.style.display = 'none';
    progressTitle.textContent = result.finished ? 'پردازش کامل شد.' : 'پردازش ناتمام ماند.';

    renderResult({
      stats: result.stats, rows: result.rows,
      assignment_mode: mode,
      assigned_agent_name: mode === 'shared' ? null : (agents.find(a => a.id === agentId) || {}).name || null,
      completed: result.finished, error: result.error,
    });

    submitBtn.disabled = false;
    if (!result.finished) showResumeBanner(prepared.import_id);
  });

  function showResumeBanner(importId){
    if (document.getElementById('reiResumeBanner')) return;
    const banner = document.createElement('div');
    banner.className = 'alert alert-warning py-3';
    banner.id = 'reiResumeBanner';
    banner.innerHTML = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
      '<div><i class="fa-solid fa-triangle-exclamation"></i> <strong>پردازش ناتمام ماند.</strong></div>' +
      '<button type="button" class="btn btn-sm btn-primary" id="reiResumeBtn" data-import-id="' + importId + '">' +
      '<i class="fa-solid fa-play"></i> ادامه‌ی پردازش</button></div>';
    globalErrors.parentNode.insertBefore(banner, globalErrors);
    bindResumeBtn();
  }

  function bindResumeBtn(){
    const resumeBtn = document.getElementById('reiResumeBtn');
    if (!resumeBtn || resumeBtn.dataset.bound === '1') return;
    resumeBtn.dataset.bound = '1';
    resumeBtn.addEventListener('click', async function(){
      const importId = parseInt(resumeBtn.dataset.importId, 10);
      resumeBtn.disabled = true;
      resumeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> در حال ادامه...';
      progressCard.hidden = false;
      resultCard.hidden = true;
      resultCard.innerHTML = '';
      spinner.style.display = 'inline-block';
      progressTitle.textContent = 'در حال ادامه‌ی پردازش...';
      clearErrors();

      const fd = new FormData();
      fd.append('ajax_action', 'resume_import');
      fd.append('import_id', String(importId));
      const csrfInput = form.querySelector('input[name="csrf_token"]');
      if (csrfInput) fd.append('csrf_token', csrfInput.value);

      let info;
      try { info = await postWithRetry(window.location.href, fd, 5); }
      catch (e) { showError('خطا در ارتباط.'); resumeBtn.disabled = false; resumeBtn.innerHTML = '<i class="fa-solid fa-play"></i> ادامه‌ی پردازش'; return; }
      if (!info.ok){ showError(info.error || 'خطا.'); resumeBtn.disabled = false; resumeBtn.innerHTML = '<i class="fa-solid fa-play"></i> ادامه‌ی پردازش'; return; }

      const result = await runBatches(importId, info.remaining, { mode: 'shared', agentId: 0 });

      spinner.style.display = 'none';
      progressTitle.textContent = result.finished ? 'پردازش کامل شد.' : 'پردازش ناتمام.';

      renderResult({
        stats: result.stats, rows: result.rows,
        assignment_mode: 'shared', assigned_agent_name: null,
        completed: result.finished, error: result.error,
      });

      if (result.finished){ const b = document.getElementById('reiResumeBanner'); if (b) b.remove(); }
      resumeBtn.disabled = false;
      resumeBtn.innerHTML = '<i class="fa-solid fa-play"></i> ادامه‌ی پردازش';
    });
  }
  bindResumeBtn();

  function renderResult(result){
    const isShared = result.assignment_mode === 'shared';
    const modeLine = isShared
      ? '<strong>حالت:</strong> بانک مشترک'
      : '<strong>کارشناس:</strong> ' + esc(result.assigned_agent_name || '—');

    const statusLine = result.completed
      ? '<div class="alert alert-success py-2 mb-3"><i class="fa-solid fa-circle-check"></i> پردازش کامل شد.</div>'
      : '<div class="alert alert-warning py-2 mb-3"><i class="fa-solid fa-triangle-exclamation"></i> پردازش ناتمام ماند.</div>';

    const rowsHtml = result.rows.map(function(r){
      let badge = '';
      if (r.status === 'success') badge = '<span class="badge badge-success-soft">موفق</span>';
      else if (r.status === 'duplicate') badge = '<span class="badge badge-warning-soft">تکراری</span>';
      else badge = '<span class="badge badge-danger-soft">خطا</span>';
      return '<tr><td>'+esc(r.row)+'</td><td>'+esc(r.full_name)+'</td><td dir="ltr">'+esc(r.mobile)+'</td><td>'+badge+'</td><td class="text-muted small">'+esc(r.error || '—')+'</td></tr>';
    }).join('');

    resultCard.innerHTML =
      '<div class="card p-4 mb-3">' +
        '<h6 class="mb-3"><i class="fa-solid fa-circle-check text-success"></i> نتیجه‌ی پردازش</h6>' +
        statusLine +
        '<div class="alert alert-light border mb-3">'+modeLine+'</div>' +
        '<div class="row g-3 mb-3">' +
          '<div class="col-md-3 col-6"><div class="card stat-mini p-3 text-center"><div class="text-muted small">کل</div><div class="fs-4 fw-bold">'+(result.stats.success+result.stats.duplicate+result.stats.error)+'</div></div></div>' +
          '<div class="col-md-3 col-6"><div class="card stat-mini p-3 text-center"><div class="text-muted small">موفق</div><div class="fs-4 fw-bold text-success">'+result.stats.success+'</div></div></div>' +
          '<div class="col-md-3 col-6"><div class="card stat-mini p-3 text-center"><div class="text-muted small">تکراری</div><div class="fs-4 fw-bold text-warning">'+result.stats.duplicate+'</div></div></div>' +
          '<div class="col-md-3 col-6"><div class="card stat-mini p-3 text-center"><div class="text-muted small">خطا</div><div class="fs-4 fw-bold text-danger">'+result.stats.error+'</div></div></div>' +
        '</div>' +
        '<div class="table-responsive"><table class="table table-sm align-middle mb-0">' +
          '<thead><tr><th>ردیف</th><th>نام</th><th>موبایل</th><th>نتیجه</th><th>توضیح</th></tr></thead>' +
          '<tbody>'+rowsHtml+'</tbody></table></div>' +
      '</div>';
    resultCard.hidden = false;
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>