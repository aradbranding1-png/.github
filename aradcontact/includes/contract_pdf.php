<?php
/**
 * فایلِ PDFِ اسنادِ قرارداد برای پیوستِ تیکت:
 * PDF در مرورگرِ همان کارشناس (مالی/قرارداد) ساخته می‌شود (html2pdf) و این‌جا ذخیره می‌شود؛
 * سپس لینکِ مستقیمِ فایل (doc_pdf.php?t=…) در فیلدِ «پیوست‌ها»ی API آراد برندینگ فرستاده می‌شود.
 */

function cpdf_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.contract_pdfs_v1';
    if (is_file($flag)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS contract_pdfs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token CHAR(40) NOT NULL,
            contract_id INT UNSIGNED NOT NULL,
            doc_type VARCHAR(20) NOT NULL,
            file_path VARCHAR(300) NOT NULL,
            file_name VARCHAR(200) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uq_cpdf_token (token), KEY idx_cpdf_contract (contract_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('cpdf_ready: ' . $e->getMessage());
        return $ok = false;
    }
    if (!is_dir(dirname($flag))) @mkdir(dirname($flag), 0755, true);
    @file_put_contents($flag, (string) time());
    return $ok = true;
}

function cpdf_dir(): string
{
    $d = __DIR__ . '/../uploads/contract_pdfs';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($d . '/index.php')) @file_put_contents($d . '/index.php', "<?php http_response_code(404);\n");
    return $d;
}

/** ذخیره‌ی PDFِ آپلودشده از مرورگر؛ خروجی: token */
function cpdf_store(PDO $pdo, array $contract, string $docType, array $file, int $userId, int $days): array
{
    if (!cpdf_ready($pdo)) return ['ok' => false, 'message' => 'جدولِ PDF آماده نیست.'];
    if ((int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) return ['ok' => false, 'message' => 'آپلودِ PDF ناموفق بود.'];
    if ((int) $file['size'] <= 0 || (int) $file['size'] > 25 * 1024 * 1024) return ['ok' => false, 'message' => 'حجمِ PDF نامعتبر است.'];
    $head = (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 5);
    if ($head !== '%PDF-') return ['ok' => false, 'message' => 'فایل PDF نیست.'];
    $token = bin2hex(random_bytes(20));
    $rel = date('Y/m') . '/' . $token . '.pdf';
    $dir = cpdf_dir();
    if (!is_dir($dir . '/' . date('Y/m'))) @mkdir($dir . '/' . date('Y/m'), 0755, true);
    if (!@move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $rel)) return ['ok' => false, 'message' => 'ذخیره‌ی PDF ناموفق بود.'];
    $types = function_exists('ctr_doc_types') ? ctr_doc_types(true) : [];
    $label = (string) ($types[$docType]['file'] ?? $docType);
    $name = $label . '-' . preg_replace('/[^0-9A-Za-z\-]/', '', normalize_digits((string) $contract['contract_number'])) . '.pdf';
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO contract_pdfs (token, contract_id, doc_type, file_path, file_name, size_bytes, created_by, created_at, expires_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$token, (int) $contract['id'], $docType, $rel, $name, (int) $file['size'], $userId, $now, date('Y-m-d H:i:s', strtotime('+' . max(1, $days) . ' days'))]);
    // لینک‌های قبلیِ همین سند (پیوستِ تیکت‌های قبلی) هم از این به بعد همین نسخه‌ی تازه را می‌دهند؛
    // پس «ارسالِ مجددِ» تیکتِ قبلی هم PDFِ درست را می‌فرستد.
    try {
        $old = $pdo->prepare('SELECT id, file_path FROM contract_pdfs WHERE contract_id = ? AND doc_type = ? AND token <> ?');
        $old->execute([(int) $contract['id'], $docType, $token]);
        $upd = $pdo->prepare('UPDATE contract_pdfs SET size_bytes = ?, file_name = ?, expires_at = GREATEST(expires_at, ?) WHERE id = ?');
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) {
            $dst = $dir . '/' . $o['file_path'];
            if (is_file($dst) && @copy($dir . '/' . $rel, $dst)) {
                $upd->execute([(int) $file['size'], $name, date('Y-m-d H:i:s', strtotime('+' . max(1, $days) . ' days')), (int) $o['id']]);
            }
        }
    } catch (Throwable $e) {
        error_log('cpdf_store refresh old: ' . $e->getMessage());
    }
    return ['ok' => true, 'token' => $token, 'name' => $name];
}

/** توکن‌های PDFِ ساخته‌شده برای همین قرارداد (فقط همان قرارداد و نوعِ سند) */
function cpdf_get(PDO $pdo, string $token, ?int $contractId = null): ?array
{
    if (!cpdf_ready($pdo) || !preg_match('/^[a-f0-9]{40}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM contract_pdfs WHERE token = ?' . ($contractId ? ' AND contract_id = ' . (int) $contractId : ''));
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
