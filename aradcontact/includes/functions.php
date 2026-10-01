<?php
/**
 * توابع کمکی عمومی: تاریخ شمسی، لینک‌های تماس/پیام‌رسان، وضعیت‌ها
 */

// =====================================================================
// خروجی امن HTML
// =====================================================================
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// =====================================================================
// پیام‌های فلش
// =====================================================================
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_get(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// =====================================================================
// تبدیل تاریخ شمسی <-> میلادی
// =====================================================================
function jalali_to_gregorian_arr(int $j_y, int $j_m, int $j_d): array
{
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $jy = $j_y - 979; $jm = $j_m - 1; $jd = $j_d - 1;
    $j_day_no = 365 * $jy + (int)intdiv($jy, 33) * 8 + (int)intdiv(($jy % 33) + 3, 4);
    for ($i = 0; $i < $jm; ++$i) $j_day_no += $j_days_in_month[$i];
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400 * (int)intdiv($g_day_no, 146097);
    $g_day_no = $g_day_no % 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * (int)intdiv($g_day_no, 36524);
        $g_day_no = $g_day_no % 36524;
        if ($g_day_no >= 365) $g_day_no++; else $leap = false;
    }
    $gy += 4 * (int)intdiv($g_day_no, 1461);
    $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false; $g_day_no--;
        $gy += (int)intdiv($g_day_no, 365);
        $g_day_no = $g_day_no % 365;
    }
    for ($i = 0; $g_day_no >= $g_days_in_month[$i] + (($i == 1 && $leap) ? 1 : 0); $i++) {
        $g_day_no -= $g_days_in_month[$i] + (($i == 1 && $leap) ? 1 : 0);
    }
    return [$gy, $i + 1, $g_day_no + 1];
}

function gregorian_to_jalali_arr(int $g_y, int $g_m, int $g_d): array
{
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $gy = $g_y - 1600; $gm = $g_m - 1; $gd = $g_d - 1;
    $g_day_no = 365 * $gy + (int)intdiv($gy + 3, 4) - (int)intdiv($gy + 99, 100) + (int)intdiv($gy + 399, 400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = (int)intdiv($j_day_no, 12053);
    $j_day_no %= 12053;
    $jy = 979 + 33 * $j_np + 4 * (int)intdiv($j_day_no, 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)intdiv($j_day_no - 1, 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) $j_day_no -= $j_days_in_month[$i];
    return [$jy, $i + 1, $j_day_no + 1];
}

function to_jalali(?string $gregorianYmd): string
{
    if (empty($gregorianYmd) || $gregorianYmd === '0000-00-00') return '-';
    [$gy, $gm, $gd] = array_map('intval', explode('-', substr($gregorianYmd, 0, 10)));
    [$jy, $jm, $jd] = gregorian_to_jalali_arr($gy, $gm, $gd);
    return to_persian_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

function normalize_digits(string $str): string
{
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, str_replace($persian, $english, $str));
}

function to_persian_digits(string $str): string
{
    $english = ['0','1','2','3','4','5','6','7','8','9'];
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($english, $persian, $str);
}

function to_gregorian(?string $jalaliYmd): ?string
{
    if (empty($jalaliYmd)) return null;
    $jalaliYmd = normalize_digits(trim($jalaliYmd));
    $parts = preg_split('/[\/\-]/', $jalaliYmd);
    if (count($parts) !== 3) return null;
    [$jy, $jm, $jd] = array_map('intval', $parts);
    if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;
    [$gy, $gm, $gd] = jalali_to_gregorian_arr($jy, $jm, $jd);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

function today_jalali(): string { return to_jalali(date('Y-m-d')); }

// =====================================================================
// ادغام مشتری
// =====================================================================
function merge_load_customer(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.id = ? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function merge_customer_records(PDO $pdo, array $primary, array $duplicate): array
{
    $snapshotStmt = $pdo->prepare('SELECT COUNT(*) FROM followups WHERE customer_id IN (?, ?)');
    $snapshotStmt->execute([$primary['id'], $duplicate['id']]);
    $expectedFollowupTotal = (int) $snapshotStmt->fetchColumn();
    $numbers = array_filter([$primary['mobile'], $primary['mobile_2']]);
    $numberSlotMap = [$primary['mobile'] => 'mobile'];
    if (!empty($primary['mobile_2'])) $numberSlotMap[$primary['mobile_2']] = 'mobile_2';
    $newMobile2 = $primary['mobile_2'];
    $skippedNumbers = [];
    foreach (array_filter([$duplicate['mobile'], $duplicate['mobile_2']]) as $num) {
        if (in_array($num, $numbers, true)) continue;
        if (empty($newMobile2)) {
            $newMobile2 = $num; $numbers[] = $num; $numberSlotMap[$num] = 'mobile_2';
        } else {
            $skippedNumbers[] = $num;
        }
    }
    if ($newMobile2 !== $primary['mobile_2']) {
        $pdo->prepare('UPDATE customers SET mobile_2 = ? WHERE id = ?')->execute([$newMobile2, $primary['id']]);
        sync_customer_phone_normalized($pdo, (int) $primary['id'], $primary['mobile'], $newMobile2);
    }
    $descParts = [];
    if (!empty($primary['description'])) $descParts[] = $primary['description'];
    if (!empty($duplicate['description']) && $duplicate['description'] !== ($primary['description'] ?? '')) {
        $descParts[] = '[از رکورد ادغام‌شده]: ' . $duplicate['description'];
    }
    $newDescription = $descParts ? implode("\n\n", $descParts) : null;
    $pdo->prepare('UPDATE customers SET description = ?, city = ?, job = ? WHERE id = ?')
        ->execute([$newDescription, $primary['city'] ?: $duplicate['city'], $primary['job'] ?: $duplicate['job'], $primary['id']]);
    $dupMessengers = customer_messengers_by_slot($pdo, $duplicate['id']);
    foreach (['mobile' => $duplicate['mobile'], 'mobile_2' => $duplicate['mobile_2']] as $dupSlot => $dupNumber) {
        if (empty($dupNumber) || empty($dupMessengers[$dupSlot])) continue;
        $targetSlot = $numberSlotMap[$dupNumber] ?? null;
        if (!$targetSlot) continue;
        $existing = customer_messengers_by_slot($pdo, $primary['id'])[$targetSlot] ?? [];
        $merged = array_values(array_unique(array_merge($existing, $dupMessengers[$dupSlot])));
        save_customer_messengers($pdo, $primary['id'], $targetSlot, $merged);
    }
    $allFollowups = $pdo->prepare('SELECT id, customer_id FROM followups WHERE customer_id IN (?, ?) ORDER BY followup_date ASC, id ASC');
    $allFollowups->execute([$primary['id'], $duplicate['id']]);
    $rows = $allFollowups->fetchAll();
    if (count($rows) !== $expectedFollowupTotal) {
        throw new RuntimeException('تعداد پیگیری‌ها هنگام خواندن برای ادغام مطابقت نداشت؛ عملیات لغو شد.');
    }
    if ($rows) {
        $updateCustStmt = $pdo->prepare('UPDATE followups SET customer_id = ? WHERE id = ? AND customer_id = ?');
        foreach ($rows as $f) {
            if ((int) $f['customer_id'] === (int) $duplicate['id']) {
                $updateCustStmt->execute([$primary['id'], $f['id'], $duplicate['id']]);
            }
        }
    }
    $leftoverStmt = $pdo->prepare('SELECT COUNT(*) FROM followups WHERE customer_id = ?');
    $leftoverStmt->execute([$duplicate['id']]);
    if ((int) $leftoverStmt->fetchColumn() > 0) {
        throw new RuntimeException('برخی پیگیری‌ها هنوز به مشتری تکراری وصلن؛ عملیات لغو شد.');
    }
    $renumberStmt = $pdo->prepare('SELECT id FROM followups WHERE customer_id = ? ORDER BY followup_date ASC, id ASC');
    $renumberStmt->execute([$primary['id']]);
    $allIds = $renumberStmt->fetchAll(PDO::FETCH_COLUMN);
    $updateNumStmt = $pdo->prepare('UPDATE followups SET followup_number = ? WHERE id = ?');
    $num = 1;
    foreach ($allIds as $fid) { $updateNumStmt->execute([$num, $fid]); $num++; }
    $pdo->prepare('UPDATE customers SET followup_count = ? WHERE id = ?')->execute([$num - 1, $primary['id']]);
    $dependentTables = [
        'customer_activity_logs' => 'customer_id', 'customer_referrals' => 'customer_id',
        'service_requests' => 'customer_id', 'quotes' => 'customer_id',
    ];
    foreach ($dependentTables as $table => $column) {
        try {
            $upd = $pdo->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $upd->execute([$primary['id'], $duplicate['id']]);
        } catch (Throwable $e) {
            throw new RuntimeException("خطا در انتقال جدول «{$table}»: " . $e->getMessage());
        }
    }
    if (!empty($duplicate['had_meeting'])) {
        $pdo->prepare('UPDATE customers SET had_meeting = 1 WHERE id = ?')->execute([$primary['id']]);
    }
    merge_customer_multi_relation_data($pdo, (int) $primary['id'], (int) $duplicate['id'], $skippedNumbers);
    $preDeleteChecks = [
        'followups' => 'SELECT COUNT(*) FROM followups WHERE customer_id = ?',
        'customer_activity_logs' => 'SELECT COUNT(*) FROM customer_activity_logs WHERE customer_id = ?',
        'customer_referrals' => 'SELECT COUNT(*) FROM customer_referrals WHERE customer_id = ?',
        'service_requests' => 'SELECT COUNT(*) FROM service_requests WHERE customer_id = ?',
        'quotes' => 'SELECT COUNT(*) FROM quotes WHERE customer_id = ?',
        'customer_phones' => 'SELECT COUNT(*) FROM customer_phones WHERE customer_id = ?',
        'customer_employee_relations' => 'SELECT COUNT(*) FROM customer_employee_relations WHERE customer_id = ?',
        'customer_messengers' => 'SELECT COUNT(*) FROM customer_messengers WHERE customer_id = ?',
    ];
    foreach ($preDeleteChecks as $tableName => $sql) {
        try {
            $chk = $pdo->prepare($sql);
            $chk->execute([$duplicate['id']]);
            $left = (int) $chk->fetchColumn();
            if ($left > 0) {
                throw new RuntimeException('جدول «' . $tableName . '» هنوز ' . $left . ' رکورد به مشتری تکراری دارد؛ ادغام متوقف شد.');
            }
        } catch (RuntimeException $re) { throw $re; } catch (Throwable $e) { continue; }
    }
    $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$duplicate['id']]);
    return $skippedNumbers;
}

function merge_customer_multi_relation_data(PDO $pdo, int $primaryId, int $duplicateId, array $skippedNumbers = []): void
{
    if (!customer_relations_ready($pdo)) return;
    foreach (array_filter(array_unique($skippedNumbers)) as $num) {
        add_customer_phone($pdo, $primaryId, (string) $num, 'از ادغام', 'manual');
    }
    $phonesStmt = $pdo->prepare('SELECT * FROM customer_phones WHERE customer_id = ?');
    $phonesStmt->execute([$duplicateId]);
    foreach ($phonesStmt->fetchAll(PDO::FETCH_ASSOC) as $phoneRow) {
        try {
            $pdo->prepare('UPDATE customer_phones SET customer_id = ?, is_primary = 0 WHERE id = ?')
                ->execute([$primaryId, (int) $phoneRow['id']]);
        } catch (Throwable $e) {
            $pdo->prepare('DELETE FROM customer_phones WHERE id = ?')->execute([(int) $phoneRow['id']]);
        }
    }
    $pdo->prepare("UPDATE customer_phones SET is_primary = 0 WHERE customer_id = ? AND id NOT IN (
        SELECT id FROM (SELECT id FROM customer_phones WHERE customer_id = ? AND is_primary = 1 ORDER BY id ASC LIMIT 1) t
    )")->execute([$primaryId, $primaryId]);
    $relStmt = $pdo->prepare('SELECT * FROM customer_employee_relations WHERE customer_id = ?');
    $relStmt->execute([$duplicateId]);
    foreach ($relStmt->fetchAll(PDO::FETCH_ASSOC) as $relRow) {
        $existingStmt = $pdo->prepare('SELECT id FROM customer_employee_relations WHERE customer_id = ? AND employee_id = ? LIMIT 1');
        $existingStmt->execute([$primaryId, (int) $relRow['employee_id']]);
        $existingId = $existingStmt->fetchColumn();
        if (!$existingId) {
            try {
                $pdo->prepare('UPDATE customer_employee_relations SET customer_id = ?, is_primary = 0 WHERE id = ?')
                    ->execute([$primaryId, (int) $relRow['id']]);
                continue;
            } catch (Throwable $e) {}
        }
        if ($existingId) {
            $pdo->prepare('UPDATE customer_employee_relations SET followup_count = followup_count + ? WHERE id = ?')
                ->execute([(int) $relRow['followup_count'], (int) $existingId]);
            $pdo->prepare('UPDATE followups SET relation_id = ? WHERE relation_id = ?')
                ->execute([(int) $existingId, (int) $relRow['id']]);
            $pdo->prepare('DELETE FROM customer_employee_relations WHERE id = ?')->execute([(int) $relRow['id']]);
        }
    }
    $pdo->prepare("UPDATE customer_employee_relations SET is_primary = 0 WHERE customer_id = ? AND id NOT IN (
        SELECT id FROM (SELECT id FROM customer_employee_relations WHERE customer_id = ? AND is_primary = 1 ORDER BY id ASC LIMIT 1) t
    )")->execute([$primaryId, $primaryId]);
    $hasPrimaryStmt = $pdo->prepare('SELECT 1 FROM customer_employee_relations WHERE customer_id = ? AND is_primary = 1 LIMIT 1');
    $hasPrimaryStmt->execute([$primaryId]);
    if (!$hasPrimaryStmt->fetchColumn()) {
        $ownerStmt = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ? LIMIT 1');
        $ownerStmt->execute([$primaryId]);
        $ownerId = $ownerStmt->fetchColumn();
        if ($ownerId) get_or_create_relation($pdo, $primaryId, (int) $ownerId, 'legacy_owner', true);
    }
}

// =====================================================================
// گروه‌بندی شغلی
// =====================================================================
function job_category_keywords(): array
{
    return [
        'کارمند دولت/عمومی' => ['کارمند دولت', 'کارمند اداره', 'کارمند بانک', 'شهرداری', 'آموزش و پرورش', 'کارمند دولتی', 'کارمند بیمه', 'کارمند شهرداری', 'مامور', 'نظامی', 'سرباز', 'دادگستری', 'قاضی', 'وکیل دادگستری'],
        'کارمند بخش خصوصی' => ['کارمند شرکت', 'کارمند خصوصی', 'کارمند', 'منشی', 'دفتردار', 'حسابدار', 'حسابرس', 'کارشناس فروش', 'کارشناس بازرگانی', 'اداری'],
        'بازرگانی و کسب‌وکار آزاد' => ['بازرگان', 'تاجر', 'مغازه‌دار', 'مغازه دار', 'فروشنده', 'بازاریاب', 'کسب و کار آزاد', 'آزاد', 'خرید و فروش', 'واردات', 'صادرات', 'نمایندگی', 'فروشگاه', 'بازار'],
        'صنعت، تولید و ساخت‌وساز' => ['مهندس عمران', 'مهندس صنایع', 'مهندس مکانیک', 'پیمانکار', 'بنا', 'معمار', 'کارخانه‌دار', 'صنعتگر', 'تولیدکننده', 'ساخت و ساز', 'مقاطعه‌کار'],
        'کارگر' => ['کارگر', 'کارگری', 'جوشکار', 'نقاش ساختمان', 'بتونی', 'آهنگر', 'کارگر ساده'],
        'پزشکی و سلامت' => ['پزشک', 'دکتر', 'پرستار', 'دندانپزشک', 'داروساز', 'ماما', 'فیزیوتراپ', 'رادیولوژی', 'بهیار', 'روانشناس', 'روان‌شناس'],
        'آموزش' => ['معلم', 'دبیر', 'استاد', 'مدرس', 'آموزگار', 'مربی', 'مدیر مدرسه', 'اموزش'],
        'حمل‌ونقل' => ['راننده', 'تاکسی', 'کامیون‌دار', 'کامیون دار', 'خلبان', 'لجستیک', 'باربری', 'اسنپ', 'تیپاکس'],
        'کشاورزی و دامداری' => ['کشاورز', 'دامدار', 'باغدار', 'زنبوردار', 'مرغدار', 'دامپرور', 'کشاورزی'],
        'فناوری اطلاعات' => ['برنامه‌نویس', 'برنامه نویس', 'طراح سایت', 'it', 'آی‌تی', 'شبکه', 'پشتیبان فنی', 'گرافیست', 'دیجیتال مارکتینگ'],
        'خانه‌دار' => ['خانه‌دار', 'خانه دار', 'خانم خانه‌دار'],
        'بازنشسته' => ['بازنشسته', 'بازنشستگی'],
        'دانشجو/محصل' => ['دانشجو', 'محصل', 'دانش‌آموز', 'دانش آموز'],
        'بیکار' => ['بیکار', 'بی‌کار', 'فاقد شغل'],
    ];
}

function classify_job_text(string $raw, array $overrides = []): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    if (isset($overrides[$raw])) return $overrides[$raw];
    foreach (job_category_keywords() as $category => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_stripos($raw, $kw) !== false) return $category;
        }
    }
    return null;
}

function get_job_category_overrides(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT raw_text, category FROM job_category_overrides');
        $out = [];
        foreach ($stmt->fetchAll() as $row) $out[$row['raw_text']] = $row['category'];
        return $out;
    } catch (Throwable $e) { return []; }
}

function iran_city_coordinates(): array
{
    return [
        'تهران' => [35.6892, 51.3890], 'مشهد' => [36.2605, 59.6168], 'اصفهان' => [32.6546, 51.6680],
        'کرج' => [35.8400, 50.9391], 'شیراز' => [29.5918, 52.5837], 'تبریز' => [38.0800, 46.2919],
        'قم' => [34.6416, 50.8746], 'اهواز' => [31.3183, 48.6706], 'کرمانشاه' => [34.3277, 47.0778],
        'ارومیه' => [37.5527, 45.0761], 'رشت' => [37.2808, 49.5832], 'زاهدان' => [29.4963, 60.8629],
        'کرمان' => [30.2839, 57.0834], 'اراک' => [34.0917, 49.6892], 'یزد' => [31.8974, 54.3569],
        'اردبیل' => [38.2498, 48.2933], 'بندرعباس' => [27.1865, 56.2808], 'قزوین' => [36.2688, 50.0041],
        'سنندج' => [35.3144, 46.9923], 'خرم‌آباد' => [33.4878, 48.3558], 'خرم آباد' => [33.4878, 48.3558],
        'گرگان' => [36.8427, 54.4392], 'ساری' => [36.5633, 53.0601], 'بوشهر' => [28.9234, 50.8203],
        'بجنورد' => [37.4747, 57.3290], 'زنجان' => [36.6736, 48.4787], 'یاسوج' => [30.6682, 51.5880],
        'سمنان' => [35.5729, 53.3971], 'بیرجند' => [32.8663, 59.2211], 'شهرکرد' => [32.3256, 50.8644],
        'ایلام' => [33.6374, 46.4227], 'همدان' => [34.7992, 48.5146], 'کاشان' => [33.9850, 51.4100],
    ];
}

function __norm_city_str(string $s): string
{
    $s = str_replace(["\u{200C}", "\xC2\xA0"], ' ', $s);
    return preg_replace('/\s+/u', ' ', trim($s));
}

function __city_loose_key(string $s): string
{
    $s = str_replace(["\u{200C}", ' ', "\xC2\xA0"], '', $s);
    return str_replace(['ي', 'ك', 'أ', 'إ', 'ة', 'ئ', 'ؤ', 'آ'], ['ی', 'ک', 'ا', 'ا', 'ه', 'ی', 'و', 'ا'], $s);
}

function looks_like_description_not_city(string $raw): bool
{
    $raw = trim($raw);
    if (mb_strlen($raw) > 25) return true;
    if (substr_count($raw, '/') >= 1 && mb_strlen($raw) > 12) return true;
    $keywords = ['دارن', 'داره', 'قراره', 'پولدار', 'بیکار', 'ساله', 'دهه', 'همسرش', 'پسرش', 'دخترش', 'شوهرش', 'ویلا', 'تلگرام', 'واتساپ', 'اینستا', 'مجرد', 'متاهل', 'بازنشسته شده', 'فوت شده'];
    foreach ($keywords as $kw) if (mb_stripos($raw, $kw) !== false) return true;
    return false;
}

function province_capital_map(): array
{
    return [
        'تهران' => 'تهران', 'البرز' => 'کرج', 'قم' => 'قم', 'مرکزی' => 'اراک', 'قزوین' => 'قزوین',
        'گیلان' => 'رشت', 'مازندران' => 'ساری',
        'آذربایجان شرقی' => 'تبریز', 'آذربایجان غربی' => 'ارومیه',
        'اردبیل' => 'اردبیل', 'کردستان' => 'سنندج', 'کرمانشاه' => 'کرمانشاه', 'همدان' => 'همدان',
        'زنجان' => 'زنجان', 'لرستان' => 'خرم آباد', 'خوزستان' => 'اهواز',
        'چهارمحال و بختیاری' => 'شهرکرد', 'کهگیلویه و بویراحمد' => 'یاسوج',
        'بوشهر' => 'بوشهر', 'فارس' => 'شیراز', 'کرمان' => 'کرمان', 'خراسان جنوبی' => 'بیرجند',
        'خراسان رضوی' => 'مشهد', 'خراسان شمالی' => 'بجنورد', 'سمنان' => 'سمنان', 'اصفهان' => 'اصفهان',
        'یزد' => 'یزد', 'سیستان و بلوچستان' => 'زاهدان', 'هرمزگان' => 'بندرعباس', 'ایلام' => 'ایلام', 'گلستان' => 'گرگان',
    ];
}

function classify_city_name(string $raw, array $cityGroups): ?string
{
    $normRaw = __norm_city_str($raw);
    if ($normRaw === '') return null;
    static $lookup = null;
    static $looseLookup = null;
    if ($lookup === null) {
        $lookup = []; $looseLookup = [];
        foreach ($cityGroups as $group) {
            foreach ($group['names'] as $name) {
                $lookup[__norm_city_str($name)] = $group['canonical'];
                $looseLookup[__city_loose_key($name)] = $group['canonical'];
            }
        }
    }
    if (isset($lookup[$normRaw])) return $lookup[$normRaw];
    $looseFull = __city_loose_key($normRaw);
    if (isset($looseLookup[$looseFull])) return $looseLookup[$looseFull];
    $words = explode(' ', $normRaw);
    for ($i = 0; $i < count($words) - 1; $i++) {
        $pair = $words[$i] . ' ' . $words[$i + 1];
        if (isset($lookup[$pair])) return $lookup[$pair];
        $loosePair = __city_loose_key($pair);
        if (isset($looseLookup[$loosePair])) return $looseLookup[$loosePair];
    }
    foreach ($words as $w) {
        if (isset($lookup[$w])) return $lookup[$w];
        $looseW = __city_loose_key($w);
        if (isset($looseLookup[$looseW])) return $looseLookup[$looseW];
    }
    return null;
}

function guess_province_for_city(string $city): string
{
    $map = ['تهران' => ['تهران'], 'البرز' => ['کرج'], 'قم' => ['قم'], 'فارس' => ['شیراز'], 'اصفهان' => ['اصفهان']];
    foreach ($map as $province => $cities) {
        if (in_array($city, $cities, true)) return $province;
    }
    return 'نامشخص';
}

function seed_cities_from_legacy_list_if_empty(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM cities')->fetchColumn();
    if ($count > 0) return;
    $legacy = iran_city_coordinates();
    $groups = [];
    foreach ($legacy as $name => $coord) {
        $key = round($coord[0], 3) . ',' . round($coord[1], 3);
        $groups[$key][] = $name;
    }
    $pdo->beginTransaction();
    try {
        $provinceIds = [];
        $insProvince = $pdo->prepare('INSERT INTO provinces (name) VALUES (?)');
        $insCity = $pdo->prepare('INSERT INTO cities (province_id, name) VALUES (?, ?)');
        $insAlias = $pdo->prepare('INSERT IGNORE INTO city_aliases (city_id, alias) VALUES (?, ?)');
        foreach ($groups as $names) {
            usort($names, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $canonical = $names[0];
            $province = guess_province_for_city($canonical);
            if (!isset($provinceIds[$province])) {
                $stmt = $pdo->prepare('SELECT id FROM provinces WHERE name = ?');
                $stmt->execute([$province]);
                $pid = $stmt->fetchColumn();
                if (!$pid) { $insProvince->execute([$province]); $pid = (int) $pdo->lastInsertId(); }
                $provinceIds[$province] = (int) $pid;
            }
            $insCity->execute([$provinceIds[$province], $canonical]);
            $cityId = (int) $pdo->lastInsertId();
            foreach ($names as $alias) {
                if ($alias !== $canonical) $insAlias->execute([$cityId, $alias]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

function get_cities_with_aliases(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $stmt = $pdo->query('SELECT c.id, c.name, GROUP_CONCAT(a.alias SEPARATOR "\u{1}") AS aliases
                             FROM cities c LEFT JOIN city_aliases a ON a.city_id = c.id
                             GROUP BY c.id, c.name');
        foreach ($stmt->fetchAll() as $row) {
            $names = [$row['name']];
            if (!empty($row['aliases'])) $names = array_merge($names, explode("\u{1}", $row['aliases']));
            $cache[] = ['canonical' => $row['name'], 'names' => $names];
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

function render_funnel_viz(array $stages, array $cumulative): string
{
    $colors = ['#60a5fa','#3b82f6','#38bdf8','#22d3ee','#34d399','#16a34a'];
    $total = $cumulative[0] ?? 0;
    $html = '<div class="funnel-viz">';
    foreach ($stages as $i => $stage) {
        $count = $cumulative[$i] ?? 0;
        $pct = $total > 0 ? round(($count / $total) * 100) : 0;
        $widthPct = $total > 0 ? max(28, round(($count / $total) * 100)) : 28;
        $color = $colors[$i % count($colors)];
        $html .= '<div class="funnel-stage" style="width:' . $widthPct . '%; background:' . $color . ';">';
        $html .= '<div class="funnel-name">' . e($stage) . '</div>';
        $html .= '<div class="funnel-count">' . to_persian_digits((string) $count) . '<span class="funnel-pct">(' . to_persian_digits((string) $pct) . '٪)</span></div>';
        $html .= '</div>';
        if ($i < count($stages) - 1) $html .= '<div class="funnel-connector"></div>';
    }
    $html .= '</div>';
    return $html;
}

function current_jalali_year(): int
{
    [$jy,] = gregorian_to_jalali_arr((int) date('Y'), (int) date('n'), (int) date('j'));
    return $jy;
}

function jalali_month_range(int $jy, int $jm): array
{
    [$sy, $sm, $sd] = jalali_to_gregorian_arr($jy, $jm, 1);
    $nextJm = $jm + 1; $nextJy = $jy;
    if ($nextJm > 12) { $nextJm = 1; $nextJy++; }
    [$ey, $em, $ed] = jalali_to_gregorian_arr($nextJy, $nextJm, 1);
    return [sprintf('%04d-%02d-%02d', $sy, $sm, $sd), sprintf('%04d-%02d-%02d', $ey, $em, $ed)];
}

function jalali_month_name(int $jm): string
{
    $names = ['', 'فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    return $names[$jm] ?? '';
}

function unified_status_options(): array
{
    return ['جدید','در حال پیگیری','جلسه برگزار شد','در انتظار تصمیم','در انتظار پرداخت','خرید کرده','عدم پاسخ','انصرافی','تعویق','نامرتبط','مشتری قدیمی','شاکی'];
}

function status_descriptions(): array
{
    return [
        'جدید' => 'مشتری تازه به کارشناس ارجاع شده و هنوز تماس مؤثری با او برقرار نشده.',
        'در حال پیگیری' => 'کارشناس با مشتری در ارتباط است و پیگیری ادامه دارد.',
        'جلسه برگزار شد' => 'جلسه یا مشاوره حضوری/آنلاین با مشتری برگزار شده است.',
        'در انتظار تصمیم' => 'مذاکره انجام شده و مشتری برای تصمیم‌گیری زمان می‌خواهد.',
        'در انتظار پرداخت' => 'مشتری تصمیم به خرید گرفته اما پرداخت هنوز انجام نشده.',
        'خرید کرده' => 'مشتری خرید را نهایی و پرداخت را انجام داده است.',
        'عدم پاسخ' => 'کارشناس تلاش کرده اما مشتری پاسخ نمی‌دهد.',
        'انصرافی' => 'مشتری از خرید منصرف شده است.',
        'تعویق' => 'مشتری فعلاً ادامه نمی‌دهد اما بعداً پیگیری می‌کند.',
        'نامرتبط' => 'این مشتری مخاطب مناسب نیست.',
        'مشتری قدیمی' => 'مشتری‌ای که قبلاً خرید داشته است.',
        'شاکی' => 'مشتریانی که شاکی هستند.',
    ];
}

function status_options_for_role(string $role): array { return unified_status_options(); }

function status_options_with_current(array $baseOptions, ?string $current): array
{
    if ($current !== null && $current !== '' && !in_array($current, $baseOptions, true)) $baseOptions[] = $current;
    return $baseOptions;
}

function role_label(string $role): string
{
    $labels = ['A' => 'واحد A', 'B' => 'واحد B', 'C' => 'واحد C', 'admin' => 'مدیر سیستم', 'leader' => 'سرپرست', 'nonsales' => 'ستادی'];
    if (isset($labels[$role])) return $labels[$role];
    return function_exists('perm_role_label') ? perm_role_label($role) : $role;
}

function valid_user_roles(): array { return ['A', 'B', 'C', 'admin', 'leader', 'nonsales']; }
function valid_job_groups(): array { return ['توسعه', 'عملیات', 'ستادی']; }

function normalize_job_group(string $raw): ?string
{
    $t = preg_replace('/\s+/', ' ', str_replace('‌', ' ', trim($raw)));
    return in_array($t, valid_job_groups(), true) ? $t : null;
}

function normalize_role_selection(string $raw): ?string
{
    $t = mb_strtolower(str_replace(['واحد', ' ', '‌'], '', trim($raw)));
    $map = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'سرپرست' => 'leader', 'leader' => 'leader'];
    return $map[$t] ?? null;
}

function users_job_group_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT job_group FROM users LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function users_arad_code_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT arad_code FROM users LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function generate_next_arad_code(PDO $pdo): string
{
    $max = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(arad_code, 3) AS UNSIGNED)), 0) FROM users WHERE arad_code REGEXP '^AR[0-9]+$'")->fetchColumn();
    return 'AR' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
}

function find_team_id_by_leader_name(PDO $pdo, string $leaderName): ?int
{
    $leaderName = trim($leaderName);
    if ($leaderName === '') return null;
    $stmt = $pdo->prepare("SELECT t.id FROM teams t JOIN users u ON u.id = t.leader_user_id WHERE u.role = 'leader' AND u.full_name = ? LIMIT 2");
    $stmt->execute([$leaderName]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return count($rows) === 1 ? (int) $rows[0] : null;
}

function calendar_hour_slots(): array
{
    $slots = [];
    for ($h = 9; $h < 20; $h++) $slots[] = ['start' => sprintf('%02d:00:00', $h), 'end' => sprintf('%02d:00:00', $h + 1)];
    return $slots;
}

function ensure_calendar_slots_for_day(PDO $pdo, int $staffId, string $dateG): void
{
    $chk = $pdo->prepare('SELECT COUNT(*) FROM calendar_slots WHERE staff_id = ? AND slot_date = ?');
    $chk->execute([$staffId, $dateG]);
    if ((int) $chk->fetchColumn() > 0) return;
    $ins = $pdo->prepare("INSERT INTO calendar_slots (staff_id, slot_date, start_time, end_time, status) VALUES (?,?,?,?,'free')");
    foreach (calendar_hour_slots() as $slot) {
        try { $ins->execute([$staffId, $dateG, $slot['start'], $slot['end']]); } catch (Throwable $e) {}
    }
}

function log_booking_history(PDO $pdo, int $bookingId, string $eventType, string $description, ?int $actorId): void
{
    $pdo->prepare('INSERT INTO meeting_booking_history (booking_id, event_type, description, actor_user_id) VALUES (?,?,?,?)')
        ->execute([$bookingId, $eventType, $description, $actorId]);
}

function booking_status_label(string $status): string
{
    $labels = ['booked' => 'رزروشده', 'held' => 'برگزارشده', 'not_held' => 'برگزارنشده', 'cancelled' => 'لغوشده', 'needs_followup' => 'نیازمند پیگیری'];
    return $labels[$status] ?? $status;
}

function booking_status_badge_class(string $status): string
{
    $classes = [
        'booked' => 'bg-primary-subtle text-primary-emphasis',
        'held' => 'bg-success-subtle text-success-emphasis',
        'not_held' => 'bg-danger-subtle text-danger-emphasis',
        'cancelled' => 'bg-secondary-subtle text-secondary-emphasis',
        'needs_followup' => 'bg-warning-subtle text-warning-emphasis',
    ];
    return $classes[$status] ?? 'bg-light text-dark';
}

function team_led_by(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE leader_user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function team_member_ids(PDO $pdo, int $teamId): array
{
    if ($teamId <= 0) return [];
    $stmt = $pdo->prepare('SELECT id FROM users WHERE team_id = ?');
    $stmt->execute([$teamId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function visible_owner_ids_for(PDO $pdo, array $user): array
{
    $ids = [(int) $user['id']];
    if (($user['role'] ?? null) === 'leader') {
        $team = team_led_by($pdo, (int) $user['id']);
        if ($team) $ids = array_merge($ids, team_member_ids($pdo, (int) $team['id']));
    }
    return array_values(array_unique($ids));
}

function leader_supervises_owner(PDO $pdo, array $user, int $ownerId): bool
{
    if (($user['role'] ?? null) !== 'leader' || $ownerId === (int) $user['id']) return false;
    $team = team_led_by($pdo, (int) $user['id']);
    if (!$team) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND team_id = ? LIMIT 1');
    $stmt->execute([$ownerId, (int) $team['id']]);
    return (bool) $stmt->fetchColumn();
}

function team_display_name(?string $name, int $id): string
{
    return $name !== null && $name !== '' ? ($name . ' (تیم ' . to_persian_digits((string) $id) . ')') : ('تیم ' . to_persian_digits((string) $id));
}

function is_super_admin(array $user): bool { return !empty($user['is_super_admin']); }

function can_manage_service_requests(array $user): bool
{
    return $user['role'] === 'admin' || in_array($user['service_access_role'] ?? null, ['supervisor', 'financial_liaison'], true);
}

function can_view_customer_basic(array $user, int $ownerId): bool
{
    return $ownerId === (int) $user['id'] || can_manage_service_requests($user);
}

function record_meeting_flag_if_needed(PDO $pdo, int $customerId, ?string $status): void
{
    if ($status === 'جلسه برگزار شد') {
        $pdo->prepare('UPDATE customers SET had_meeting = 1 WHERE id = ? AND COALESCE(had_meeting, 0) <> 1')->execute([$customerId]);
    }
}

/**
 * «نوعِ شناخته‌شده»ی یک شماره در کلِ سامانه:
 *   - موبایلِ یکی از کارکنان ← همکار
 *   - اگر کسی این شماره را «همکار» یا «خانواده» ثبت کرده ← همان نوع (همکار مقدم است)
 * null = مشتریِ عادی (یا ناشناخته)
 */
function known_contact_type_for_phone(PDO $pdo, ?string $phone, int $excludeCustomerId = 0): ?string
{
    $key = $phone !== null && trim($phone) !== '' ? normalize_phone_for_match($phone) : null;
    if ($key === null || $key === '') return null;
    static $staff = null;
    if ($staff === null) {
        $staff = [];
        try {
            foreach ($pdo->query("SELECT mobile FROM users WHERE mobile IS NOT NULL AND mobile <> ''") as $r) {
                $k = normalize_phone_for_match((string) $r['mobile']);
                if ($k) $staff[$k] = true;
            }
        } catch (Throwable $e) {}
    }
    if (isset($staff[$key])) return 'colleague';
    try {
        if (customer_phone_normalized_ready($pdo)) {
            $st = $pdo->prepare("SELECT contact_type FROM customers WHERE (mobile_normalized = ? OR mobile2_normalized = ?)
                AND contact_type IN ('colleague','family') AND id <> ? ORDER BY contact_type = 'colleague' DESC LIMIT 1");
            $st->execute([$key, $key, $excludeCustomerId]);
        } else {
            $st = $pdo->prepare("SELECT contact_type FROM customers WHERE (mobile = ? OR mobile_2 = ?)
                AND contact_type IN ('colleague','family') AND id <> ? ORDER BY contact_type = 'colleague' DESC LIMIT 1");
            $st->execute([$phone, $phone, $excludeCustomerId]);
        }
        $t = $st->fetchColumn();
        return $t ? (string) $t : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * پس از ثبت/وارد کردنِ یک مخاطب (دستی، اکسل، کالیزر، نواتل): اگر این شماره قبلاً «همکار/خانواده» ثبت شده
 * (یا موبایلِ یکی از کارکنان است)، همین رکورد هم همان نوع می‌گیرد و «قفل» می‌شود تا فقط دارنده‌ی مجوز بتواند عوضش کند.
 * رکوردی که مدیر دستی قفل کرده (contact_type_locked = 1) دست نمی‌خورد. نوعِ اعمال‌شده را برمی‌گرداند.
 */
function apply_known_contact_type(PDO $pdo, int $customerId, ?string $mobile, ?string $mobile2 = null): ?string
{
    $type = known_contact_type_for_phone($pdo, $mobile, $customerId) ?? known_contact_type_for_phone($pdo, $mobile2, $customerId);
    if ($type === null) return null;
    try {
        $st = $pdo->prepare("UPDATE customers SET contact_type = ?, contact_type_locked = 1 WHERE id = ? AND contact_type_locked = 0 AND contact_type <> ?");
        $st->execute([$type, $customerId, $type]);
        if ($st->rowCount() > 0) {
            try { $pdo->prepare('UPDATE followups SET contact_type = ? WHERE customer_id = ?')->execute([$type, $customerId]); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        return null;
    }
    return $type;
}

/**
 * وقتی شماره‌ای «همکار/خانواده» شد (یا موبایلِ یک کارمند شد)، همه‌ی رکوردهای دیگرِ همین شماره هم
 * همان نوع و قفل می‌شوند (به‌جز رکوردهایی که مدیر دستی قفل کرده).
 */
function sync_colleague_contact_type(PDO $pdo, ?string ...$phones): void
{
    foreach (array_unique(array_filter($phones)) as $phone) {
        try {
            $type = known_contact_type_for_phone($pdo, $phone);
            if ($type === null) continue;
            $key = normalize_phone_for_match($phone);
            if (customer_phone_normalized_ready($pdo) && $key) {
                $ids = $pdo->prepare("SELECT id FROM customers WHERE (mobile_normalized = ? OR mobile2_normalized = ?) AND contact_type <> ? AND contact_type_locked = 0");
                $ids->execute([$key, $key, $type]);
            } else {
                $ids = $pdo->prepare("SELECT id FROM customers WHERE (mobile = ? OR mobile_2 = ?) AND contact_type <> ? AND contact_type_locked = 0");
                $ids->execute([$phone, $phone, $type]);
            }
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
                $pdo->prepare('UPDATE customers SET contact_type = ?, contact_type_locked = 1 WHERE id = ?')->execute([$type, (int) $cid]);
                try { $pdo->prepare('UPDATE followups SET contact_type = ? WHERE customer_id = ?')->execute([$type, (int) $cid]); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {}
    }
}

/** آیا این کاربر اجازه دارد نوعِ «همکار/خانواده»ی قفل‌شده را تغییر دهد؟ */
function can_change_locked_contact_type(array $user): bool
{
    return (function_exists('can_manage_service_requests') && can_manage_service_requests($user))
        || (function_exists('user_can') && user_can('customer_contact_type_change', $user));
}

/**
 * یک‌بار: همه‌ی شماره‌هایی که جایی «همکار/خانواده» ثبت شده‌اند (یا موبایلِ کارکنان هستند) ولی در رکوردهای دیگر
 * «مشتری» مانده‌اند (مثلاً از اکسل/کالیزر/نواتل آمده‌اند) اصلاح و قفل می‌شوند.
 */
function contact_type_backfill_v1(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/../storage/.contact_type_backfill_v1';
    if (is_file($flag) || !customer_phone_normalized_ready($pdo)) return;
    @file_put_contents($flag, (string) time());
    @set_time_limit(600);
    try {
        $map = [];
        foreach ($pdo->query("SELECT mobile FROM users WHERE mobile IS NOT NULL AND mobile <> ''") as $r) {
            $k = normalize_phone_for_match((string) $r['mobile']);
            if ($k) $map[$k] = 'colleague';
        }
        foreach ($pdo->query("SELECT contact_type, mobile_normalized, mobile2_normalized FROM customers WHERE contact_type IN ('colleague','family')") as $r) {
            foreach ([$r['mobile_normalized'], $r['mobile2_normalized']] as $k) {
                if (!$k) continue;
                if (!isset($map[$k]) || $r['contact_type'] === 'colleague') $map[$k] = (string) $r['contact_type'];
            }
        }
        $byType = ['colleague' => [], 'family' => []];
        foreach ($map as $k => $t) $byType[$t][] = $k;
        foreach ($byType as $type => $keys) {
            foreach (array_chunk($keys, 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare("SELECT id FROM customers WHERE (mobile_normalized IN ($ph) OR mobile2_normalized IN ($ph)) AND contact_type <> ? AND contact_type_locked = 0");
                $st->execute(array_merge($chunk, $chunk, [$type]));
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
                foreach (array_chunk($ids, 500) as $idc) {
                    $in = implode(',', $idc);
                    $pdo->prepare("UPDATE customers SET contact_type = ?, contact_type_locked = 1 WHERE id IN ($in)")->execute([$type]);
                    try { $pdo->prepare("UPDATE followups SET contact_type = ? WHERE customer_id IN ($in)")->execute([$type]); } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {
        error_log('contact_type_backfill_v1: ' . $e->getMessage());
    }
}

function fetch_call_duration_by_contact_type(PDO $pdo, int $userId, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT c.contact_type, COUNT(*) AS call_count, COALESCE(SUM(f.call_duration_seconds), 0) AS total_duration
        FROM followups f JOIN customers c ON c.id = f.customer_id
        WHERE f.created_by = ? AND f.source IN ('call_import', 'novatel_import') AND f.followup_date BETWEEN ? AND ? AND f.call_duration_seconds > 10
        GROUP BY c.contact_type");
    $stmt->execute([$userId, $from, $to]);
    $result = ['customer' => ['count' => 0, 'duration' => 0], 'colleague' => ['count' => 0, 'duration' => 0], 'family' => ['count' => 0, 'duration' => 0]];
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['contact_type'] ?? 'customer';
        if (isset($result[$key])) $result[$key] = ['count' => (int) $row['call_count'], 'duration' => (int) $row['total_duration']];
    }
    return $result;
}

// =====================================================================
// ★ تابع قدیمی — برای سازگاری حفظ شده
// =====================================================================
function compute_avg_referral_response(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT r.created_at AS referred_at,
            (SELECT MIN(f.created_at) FROM followups f WHERE f.customer_id = r.customer_id AND f.created_by = r.to_user_id AND f.created_at > r.created_at) AS first_response_at
        FROM customer_referrals r WHERE r.to_user_id = ?
    ");
    $stmt->execute([$userId]);
    $totalMinutes = 0; $count = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['first_response_at'])) {
            $totalMinutes += (strtotime($row['first_response_at']) - strtotime($row['referred_at'])) / 60;
            $count++;
        }
    }
    return ['avg_minutes' => $count > 0 ? (int) round($totalMinutes / $count) : null, 'responded_count' => $count];
}

// =====================================================================
// ★ نسخه‌ی batch — برای همه‌ی کاربران در یک کوئری
// =====================================================================
function compute_avg_referral_response_batch(PDO $pdo, array $userIds, ?string $rangeFrom = null, ?string $rangeTo = null): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($id) => $id > 0);
    if (!$userIds) return [];
    $userIds = array_values($userIds);
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "
        SELECT r.to_user_id AS uid,
               AVG(TIMESTAMPDIFF(MINUTE, r.created_at, fr.first_response_at)) AS avg_minutes,
               COUNT(fr.first_response_at) AS responded_count
        FROM customer_referrals r
        LEFT JOIN (
            SELECT f.customer_id, f.created_by, MIN(f.created_at) AS first_response_at
            FROM followups f
            WHERE f.created_by IN ($in)
            GROUP BY f.customer_id, f.created_by
        ) fr ON fr.customer_id = r.customer_id
             AND fr.created_by = r.to_user_id
             AND fr.first_response_at > r.created_at
        WHERE r.to_user_id IN ($in)
    ";
    $params = array_merge($userIds, $userIds);
    if ($rangeFrom !== null && $rangeTo !== null && $rangeFrom !== '') {
        $sql .= " AND r.created_at >= ? AND r.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
        $params[] = $rangeFrom;
        $params[] = $rangeTo;
    }
    $sql .= " GROUP BY r.to_user_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['uid']] = [
                'avg_minutes' => $row['avg_minutes'] !== null ? (int) round((float) $row['avg_minutes']) : null,
                'responded_count' => (int) $row['responded_count'],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('compute_avg_referral_response_batch: ' . $e->getMessage());
        return [];
    }
}

function format_minutes_readable(int $minutes): string
{
    if ($minutes < 60) return to_persian_digits((string) $minutes) . ' دقیقه';
    $hours = intdiv($minutes, 60);
    $rem = $minutes % 60;
    if ($hours < 24) return to_persian_digits((string) $hours) . ' ساعت' . ($rem > 0 ? ' و ' . to_persian_digits((string) $rem) . ' دقیقه' : '');
    return to_persian_digits((string) intdiv($hours, 24)) . ' روز';
}

function service_access_role_label(?string $role): string
{
    $labels = ['supervisor' => 'نظارت', 'financial_liaison' => 'رابط مالی'];
    return $labels[$role] ?? '';
}

function role_letter(string $role): string
{
    $letters = ['A' => 'A', 'B' => 'B', 'C' => 'C', 'admin' => 'مدیر سیستم', 'leader' => 'سرپرست'];
    return $letters[$role] ?? $role;
}

function status_badge_class(string $status): string
{
    $classes = [
        'جدید' => 'status-new', 'در حال پیگیری' => 'status-following', 'جلسه برگزار شد' => 'status-meeting-held',
        'در انتظار تصمیم' => 'status-awaiting-decision', 'در انتظار پرداخت' => 'status-awaiting-payment',
        'خرید کرده' => 'status-purchased', 'عدم پاسخ' => 'status-no-answer', 'انصرافی' => 'status-cancelled',
        'تعویق' => 'status-postponed', 'نامرتبط' => 'status-irrelevant', 'مشتری قدیمی' => 'status-old-customer',
        'شاکی' => 'status-cancelled',
    ];
    return $classes[$status] ?? 'status-default';
}

function status_needs_no_followup(string $status): bool
{
    return in_array($status, ['انصرافی', 'نامرتبط', 'شاکی'], true);
}

function followup_date_migration_pending(PDO $pdo): bool
{
    static $pending = null;
    if ($pending !== null) return $pending;
    try {
        $stmt = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'next_followup_date'");
        $stmt->execute();
        $pending = ($stmt->fetchColumn() === 'NO');
    } catch (Exception $e) { $pending = false; }
    return $pending;
}

function render_followup_migration_warning(bool $isAdmin): void
{
    if ($isAdmin) {
        echo '<div class="alert alert-danger compact-text"><i class="fa-solid fa-triangle-exclamation"></i> یک بروزرسانی دیتابیس هنوز روی سایت اجرا نشده.</div>';
    } else {
        echo '<div class="alert alert-warning compact-text"><i class="fa-solid fa-triangle-exclamation"></i> ثبت وضعیت «انصرافی» یا «نامرتبط» با خطا مواجه می‌شود؛ به مدیر اطلاع دهید.</div>';
    }
}

function success_statuses_for_role(string $role): array { return ['خرید کرده']; }

function contact_type_options(): array
{
    return ['customer' => 'مشتری', 'family' => 'خانواده', 'colleague' => 'همکار'];
}

function contact_type_label(?string $type): string
{
    $options = contact_type_options();
    return $options[$type] ?? $options['customer'];
}

function social_network_options(): array
{
    return ['bale' => 'بله', 'eitaa' => 'ایتا', 'rubika' => 'روبیکا', 'telegram' => 'تلگرام', 'whatsapp' => 'واتساپ'];
}

function format_intl_phone(string $mobile): string
{
    $digits = preg_replace('/\D/', '', $mobile);
    if (str_starts_with($digits, '0')) $digits = '98' . substr($digits, 1);
    elseif (!str_starts_with($digits, '98')) $digits = '98' . $digits;
    return $digits;
}

function call_link(string $mobile): string { return 'tel:' . preg_replace('/\D/', '', $mobile); }

function messenger_link(?string $network, string $mobile): ?string
{
    if (empty($network)) return null;
    $intl = format_intl_phone($mobile);
    return match ($network) {
        'whatsapp' => 'https://wa.me/' . $intl,
        'telegram' => 'https://t.me/+' . $intl,
        'eitaa' => 'https://eitaa.com/+' . $intl,
        'bale' => 'https://ble.ir/+' . $intl,
        'rubika' => 'https://rubika.ir/',
        default => null,
    };
}

function social_network_icon_class(string $network): string
{
    $icons = [
        'whatsapp' => 'fa-brands fa-whatsapp', 'telegram' => 'fa-brands fa-telegram',
        'bale' => 'fa-solid fa-comment-dots', 'eitaa' => 'fa-solid fa-comment-dots', 'rubika' => 'fa-solid fa-comment-dots',
    ];
    return $icons[$network] ?? 'fa-solid fa-message';
}

const MAX_MESSENGERS_PER_MOBILE = 3;

function customer_messengers_by_slot(PDO $pdo, int $customerId): array
{
    $result = ['mobile' => [], 'mobile_2' => []];
    $stmt = $pdo->prepare('SELECT mobile_slot, messenger FROM customer_messengers WHERE customer_id = ? ORDER BY id ASC');
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll() as $row) $result[$row['mobile_slot']][] = $row['messenger'];
    return $result;
}

function render_messenger_checkboxes(string $slot, array $checked, string $legend): void
{
    $groupId = 'messengers_' . $slot;
    echo '<div class="col-12">';
    echo '<div class="messenger-field-label"><label class="form-label mb-0">' . e($legend) . '</label>';
    echo '<span class="text-muted small">حداکثر ' . to_persian_digits((string) MAX_MESSENGERS_PER_MOBILE) . ' مورد</span></div>';
    echo '<div class="d-flex flex-wrap gap-2 messenger-group" id="' . e($groupId) . '" data-max="' . MAX_MESSENGERS_PER_MOBILE . '">';
    foreach (social_network_options() as $val => $label) {
        $isChecked = in_array($val, $checked, true);
        $inputId = $groupId . '_' . $val;
        echo '<input type="checkbox" class="btn-check" name="' . e($slot) . '_messengers[]" value="' . e($val) . '" id="' . e($inputId) . '"' . ($isChecked ? ' checked' : '') . ' autocomplete="off">';
        echo '<label class="btn btn-outline-secondary messenger-chip" for="' . e($inputId) . '"><i class="' . e(social_network_icon_class($val)) . '"></i>' . e($label) . '</label>';
    }
    echo '</div></div>';
}

function save_customer_messengers(PDO $pdo, int $customerId, string $slot, array $messengers): void
{
    $valid = array_keys(social_network_options());
    $messengers = array_slice(array_values(array_unique(array_intersect($messengers, $valid))), 0, MAX_MESSENGERS_PER_MOBILE);
    $pdo->prepare('DELETE FROM customer_messengers WHERE customer_id = ? AND mobile_slot = ?')->execute([$customerId, $slot]);
    if ($messengers) {
        $ins = $pdo->prepare('INSERT INTO customer_messengers (customer_id, mobile_slot, messenger) VALUES (?,?,?)');
        foreach ($messengers as $m) $ins->execute([$customerId, $slot, $m]);
    }
}

function find_duplicate_name_customers(PDO $pdo, int $excludeId, string $fullName, bool $isAdmin, int $ownerId): array
{
    $fullName = trim($fullName);
    if ($fullName === '') return [];
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.full_name = ? AND c.id != ? ORDER BY c.id ASC LIMIT 20');
        $stmt->execute([$fullName, $excludeId]);
    } else {
        $stmt = $pdo->prepare('SELECT c.*, u.full_name AS owner_name FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.full_name = ? AND c.id != ? AND c.owner_user_id = ? ORDER BY c.id ASC LIMIT 20');
        $stmt->execute([$fullName, $excludeId, $ownerId]);
    }
    return $stmt->fetchAll();
}

function person_pick_label(string $name, ?string $mobile = null, string $extra = ''): string
{
    $label = trim($name);
    $m = trim((string) $mobile);
    if ($m !== '') $label .= ' (' . $m . ')';
    if (trim($extra) !== '') $label .= ' — ' . trim($extra);
    return $label;
}

function referral_target_staff(PDO $pdo, int $excludeUserId = 0): array
{
    $stmt = $pdo->prepare("SELECT id, full_name, role, mobile FROM users WHERE role != 'admin' AND is_approved = 1 AND is_active = 1 AND id != ? ORDER BY role, full_name");
    $stmt->execute([$excludeUserId]);
    return $stmt->fetchAll();
}

function admin_reassign_customer_silently(PDO $pdo, int $customerId, int $newOwnerId, int $adminId): void
{
    $prevOwnerStmt = $pdo->prepare('SELECT owner_user_id FROM customers WHERE id = ?');
    $prevOwnerStmt->execute([$customerId]);
    $prevOwnerId = (int) $prevOwnerStmt->fetchColumn();
    $pdo->prepare('UPDATE customers SET owner_user_id = ? WHERE id = ?')->execute([$newOwnerId, $customerId]);
    try { referral_log($pdo, $customerId, $prevOwnerId, $newOwnerId, $adminId, 'admin_return', 'بازگرداندن به ارجاع‌دهنده‌ی اصلی توسطِ مدیر'); } catch (Throwable $e) {}
    try {
        $nameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $nameStmt->execute([$newOwnerId]);
        $newOwnerName = (string) ($nameStmt->fetchColumn() ?: 'کارشناس دیگر');
        $log = $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)');
        $log->execute([$customerId, $adminId, 'referral', 'مالکیت مشتری توسط مدیر سیستم به «' . $newOwnerName . '» اصلاح شد.']);
    } catch (Throwable $e) {}
}

function referral_source_label(string $source): string
{
    $labels = [
        'manual' => 'ارجاعِ دستیِ تکی', 'bulk' => 'ارجاعِ گروهی',
        'phone_conflict' => 'ارجاعِ خودکار توسط ابزار حل تداخل شماره‌ها',
    ];
    return $labels[$source] ?? 'نامشخص';
}

function refer_customer(PDO $pdo, int $customerId, int $fromUserId, int $toUserId, int $referredBy, string $source = 'manual'): void
{
    if ($fromUserId === $toUserId) return;
    $pdo->prepare("UPDATE customers SET owner_user_id = ?, new_customer_notified = 0, status = 'جدید', next_followup_date = CURDATE() WHERE id = ?")
        ->execute([$toUserId, $customerId]);
    referral_log($pdo, $customerId, $fromUserId, $toUserId, $referredBy, $source); // مثلِ قبل در customer_referrals (+ نوع)
    try {
        $fromNameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $fromNameStmt->execute([$fromUserId]);
        $fromName = (string) ($fromNameStmt->fetchColumn() ?: 'کارشناس قبلی');
        $toNameStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
        $toNameStmt->execute([$toUserId]);
        $toName = (string) ($toNameStmt->fetchColumn() ?: 'کارشناس مقصد');
        $desc = 'مشتری از «' . $fromName . '» به «' . $toName . '» ارجاع شد. مسیر: ' . referral_source_label($source) . '.';
        $log = $pdo->prepare('INSERT INTO customer_activity_logs (customer_id, user_id, activity_type, description) VALUES (?,?,?,?)');
        $log->execute([$customerId, $referredBy, 'referral', $desc]);
    } catch (Throwable $e) {}
}

// ═══════════════════════════════════════════════════════════════════════
//  «تاریخچه ارجاع»ِ کامل: هر تغییرِ مالکیتِ پرونده با «نوع» ثبت می‌شود
// ═══════════════════════════════════════════════════════════════════════
//  قبلاً فقط ارجاعِ دستی/گروهی/هم‌سطح (customer_referrals) ثبت می‌شد؛ مشتری‌ای که بعد از «جلسه‌ی برگزارشده»
//  یا با «دریافت از Box» دستِ کارشناسِ دیگری می‌رفت، در «ارجاع‌های من»ِ ارجاع‌دهنده و «دریافتی»ِ گیرنده نبود.
//  این انتقال‌ها در جدولِ جدای customer_handoffs ثبت می‌شوند (تا آمارِ «ارجاع» در گزارش‌ها و اعلانِ ارجاعِ جدید
//  مثلِ قبل بماند) و صفحه‌ی «تاریخچه ارجاع» هر دو را با هم نشان می‌دهد.

function referral_source_labels(): array
{
    return [
        'manual' => 'ارجاعِ دستی', 'bulk' => 'ارجاعِ گروهی', 'phone_conflict' => 'حلِ تداخلِ شماره', 'peer' => 'ارجاعِ هم‌سطح',
        'meeting' => 'بعد از جلسه‌ی برگزارشده', 'box' => 'دریافت از Box', 'admin_return' => 'بازگرداندن توسطِ مدیر', 'import' => 'انتقال با ایمپورت',
    ];
}

/** انواعی که «ارجاع» حساب می‌شوند (customer_referrals)؛ بقیه «انتقالِ پرونده» (customer_handoffs) */
function referral_is_referral_source(string $source): bool
{
    return in_array($source, ['manual', 'bulk', 'phone_conflict', 'peer'], true);
}

/** ستون‌های «نوع / توضیح» روی customer_referrals + جدولِ customer_handoffs + پُر کردنِ یک‌باره‌ی سابقه (جلسه‌ها و Boxها) */
function referral_log_ready(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    $flag = __DIR__ . '/../storage/.referral_log_v1';
    try {
        if (is_file($flag)) return $ok = true;
        $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_referrals'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (!$cols) return $ok = false;
        if (!in_array('source', $cols, true)) $pdo->exec('ALTER TABLE customer_referrals ADD COLUMN source VARCHAR(30) NULL DEFAULT NULL');
        if (!in_array('note', $cols, true)) $pdo->exec('ALTER TABLE customer_referrals ADD COLUMN note VARCHAR(255) NULL DEFAULT NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_handoffs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            from_user_id INT UNSIGNED NOT NULL,
            to_user_id INT UNSIGNED NOT NULL,
            referred_by INT UNSIGNED NOT NULL,
            source VARCHAR(30) NOT NULL,
            note VARCHAR(255) NULL,
            ref_key VARCHAR(40) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_customer_handoffs_ref (ref_key),
            KEY idx_customer_handoffs_customer (customer_id),
            KEY idx_customer_handoffs_from (from_user_id, created_at),
            KEY idx_customer_handoffs_to (to_user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $ok = true;
        referral_log_backfill($pdo);
        @file_put_contents($flag, date('c'));
        return true;
    } catch (Throwable $e) {
        error_log('referral_log_ready: ' . $e->getMessage());
        return $ok = false;
    }
}

/** سابقه‌ی قبلی: انتقال‌های بعد از جلسه و دریافت‌های Box */
function referral_log_backfill(PDO $pdo): void
{
    try {
        // جلسه: از «رزروکننده» به «برگزارکننده»، در زمانِ ثبتِ انتقال
        $pdo->exec("INSERT IGNORE INTO customer_handoffs (customer_id, from_user_id, to_user_id, referred_by, created_at, source, note, ref_key)
            SELECT mb.customer_id, mb.booked_by, mb.staff_id, COALESCE(h.actor_user_id, mb.booked_by),
                   COALESCE(h.created_at, mb.updated_at, mb.created_at), 'meeting', 'مسئولیتِ مشتری بعد از برگزاریِ جلسه منتقل شد', CONCAT('mb', mb.id)
            FROM meeting_bookings mb
            LEFT JOIN meeting_booking_history h ON h.id = (SELECT MAX(h2.id) FROM meeting_booking_history h2 WHERE h2.booking_id = mb.id AND h2.event_type = 'responsibility_transferred')
            WHERE mb.responsibility_transferred = 1 AND mb.booked_by IS NOT NULL AND mb.booked_by <> mb.staff_id");
    } catch (Throwable $e) {
        error_log('referral_log_backfill meeting: ' . $e->getMessage());
    }
    try {
        // Box: از صاحبِ قبلی (یا واردکننده) به دریافت‌کننده — ارجاعِ هم‌سطح خودش در customer_referrals هست
        $pdo->exec("INSERT IGNORE INTO customer_handoffs (customer_id, from_user_id, to_user_id, referred_by, created_at, source, note, ref_key)
            SELECT b.customer_id, COALESCE(b.prev_owner_id, b.entered_by), b.claimed_by, COALESCE(b.entered_by, b.claimed_by), b.claimed_at,
                   'box', CONCAT('Box ', b.box), CONCAT('bx', b.id)
            FROM ps_box_items b
            WHERE b.claimed_by IS NOT NULL AND b.claimed_at IS NOT NULL AND b.source <> 'peer'
              AND COALESCE(b.prev_owner_id, b.entered_by) IS NOT NULL AND COALESCE(b.prev_owner_id, b.entered_by) <> b.claimed_by
              AND COALESCE(b.prev_owner_id, b.entered_by) NOT IN (SELECT id FROM users WHERE mobile = 'BOX-SYSTEM')");
    } catch (Throwable $e) {
        error_log('referral_log_backfill box: ' . $e->getMessage());
    }
}

/**
 * ثبتِ یک تغییرِ مالکیت. ارجاع‌ها (دستی/گروهی/هم‌سطح/حلِ تداخل) مثلِ قبل در customer_referrals (با اعلان برای گیرنده)؛
 * انتقال‌های دیگر (جلسه/Box/بازگرداندن/ایمپورت) در customer_handoffs. تکراری‌ها با $refKey یک‌بار ثبت می‌شوند.
 */
function referral_log(PDO $pdo, int $customerId, int $fromUserId, int $toUserId, int $referredBy, string $source, string $note = '', ?string $refKey = null): void
{
    if ($customerId <= 0 || $toUserId <= 0 || $fromUserId <= 0 || $fromUserId === $toUserId) return;
    $ready = referral_log_ready($pdo);
    if (referral_is_referral_source($source)) {
        if ($ready) {
            $pdo->prepare('INSERT INTO customer_referrals (customer_id, from_user_id, to_user_id, referred_by, source, note) VALUES (?,?,?,?,?,?)')
                ->execute([$customerId, $fromUserId, $toUserId, $referredBy ?: $fromUserId, $source, $note !== '' ? mb_substr($note, 0, 255) : null]);
        } else {
            $pdo->prepare('INSERT INTO customer_referrals (customer_id, from_user_id, to_user_id, referred_by) VALUES (?,?,?,?)')
                ->execute([$customerId, $fromUserId, $toUserId, $referredBy ?: $fromUserId]);
        }
        return;
    }
    if (!$ready) return;
    if (function_exists('ps_box_user_id') && $fromUserId === ps_box_user_id($pdo)) return; // «Box سیستم» کسی نیست
    // همین مشتری به همین گیرنده قبلاً ارجاع خورده یا همین مسیر ثبت شده ← تکراری ثبت نشود
    $dup = $pdo->prepare('SELECT (SELECT COUNT(*) FROM customer_referrals WHERE customer_id = ? AND to_user_id = ?)
        + (SELECT COUNT(*) FROM customer_handoffs WHERE customer_id = ? AND from_user_id = ? AND to_user_id = ?)');
    $dup->execute([$customerId, $toUserId, $customerId, $fromUserId, $toUserId]);
    if ((int) $dup->fetchColumn() > 0) return;
    $pdo->prepare('INSERT IGNORE INTO customer_handoffs (customer_id, from_user_id, to_user_id, referred_by, source, note, ref_key) VALUES (?,?,?,?,?,?,?)')
        ->execute([$customerId, $fromUserId, $toUserId, $referredBy ?: $fromUserId, $source, $note !== '' ? mb_substr($note, 0, 255) : null, $refKey]);
}

/**
 * همه‌ی ارجاع‌ها + انتقال‌ها به‌صورتِ یک «جدول» (برای FROM ... r).
 * انتقالی که همان مشتری به همان گیرنده قبلاً «ارجاع» هم خورده، یا تکرارِ همان مسیر است، نشان داده نمی‌شود (هر مشتری یک بار).
 */
function referral_union_sql(): string
{
    return "(SELECT id, customer_id, from_user_id, to_user_id, referred_by, created_at, source, note, 'referral' AS kind FROM customer_referrals
             UNION ALL
             SELECT h.id, h.customer_id, h.from_user_id, h.to_user_id, h.referred_by, h.created_at, h.source, h.note, 'handoff' AS kind FROM customer_handoffs h
             WHERE NOT EXISTS (SELECT 1 FROM customer_referrals r0 WHERE r0.customer_id = h.customer_id AND r0.to_user_id = h.to_user_id)
               AND h.id = (SELECT MIN(h2.id) FROM customer_handoffs h2 WHERE h2.customer_id = h.customer_id AND h2.from_user_id = h.from_user_id AND h2.to_user_id = h.to_user_id))";
}

/**
 * یک‌بار: تماس‌هایی که در آپلودِ کالیزر برای «مشتریِ جدید» (شماره‌ی ناشناسی که همان‌جا مشتری شد) ثبت شده بودند
 * به‌اشتباه is_phone_call = 0 گرفته بودند و در «گزارش‌های تماس» شمرده نمی‌شدند (ولی در «گزارش تیم‌ها» بودند).
 * همان قاعده‌ی بقیه‌ی ردیف‌ها: تماسِ کالیزر/نواتل، با مشتری، بیش از ۱۰ ثانیه ← is_phone_call = 1.
 */
function followups_phone_call_backfill_v1(PDO $pdo): void
{
    $flag = __DIR__ . '/../storage/.followups_phone_call_backfill_v1';
    if (is_file($flag)) return;
    @file_put_contents($flag, date('c'));
    try {
        $pdo->exec("UPDATE followups SET is_phone_call = 1
            WHERE source IN ('call_import', 'novatel_import') AND is_phone_call = 0 AND contact_type = 'customer' AND call_duration_seconds > 10");
    } catch (Throwable $e) {
        error_log('followups_phone_call_backfill_v1: ' . $e->getMessage());
        @unlink($flag);
    }
}

// ═══════════════════════════════════════════════════════════════════════
//  «محل فعالیت / وضعیت حضور»: دو ستونِ هم‌معنا (work_location در ویرایشِ کاربر و گزارشِ سرپرست، work_mode در لیستِ کاربران)
//  از این به بعد هر دو با هم نوشته و خوانده می‌شوند تا هر صفحه‌ای ویرایش شود، همه‌جا یکی باشد.
// ═══════════════════════════════════════════════════════════════════════
function users_work_cols(PDO $pdo): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = [];
    try {
        foreach ($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('work_location','work_mode','department')")->fetchAll(PDO::FETCH_COLUMN) ?: [] as $n) $c[$n] = true;
    } catch (Throwable $e) {}
    return $c;
}

/** ثبتِ «حضوری/دورکار» در هر دو ستون (null = نامشخص) */
function users_set_work_location(PDO $pdo, int $userId, ?string $val): void
{
    $val = in_array($val, ['onsite', 'remote'], true) ? $val : null;
    $cols = users_work_cols($pdo);
    foreach (['work_location', 'work_mode'] as $col) {
        if (!empty($cols[$col])) $pdo->prepare("UPDATE users SET `$col` = ? WHERE id = ?")->execute([$val, $userId]);
    }
}

/** مقدارِ واحدِ «محل فعالیت» برای یک ردیفِ کاربر */
function users_work_location_of(array $u): ?string
{
    foreach (['work_location', 'work_mode'] as $col) {
        if (in_array($u[$col] ?? null, ['onsite', 'remote'], true)) return $u[$col];
    }
    return null;
}

/** یک‌بار: یکسان‌سازیِ دو ستون (هر کدام که پر است، دیگری را پر می‌کند؛ اگر هر دو پر و متفاوت‌اند work_location معتبر است) */
function users_work_sync_v1(PDO $pdo): void
{
    $flag = __DIR__ . '/../storage/.users_work_sync_v1';
    if (is_file($flag)) return;
    $cols = users_work_cols($pdo);
    if (empty($cols['work_location']) || empty($cols['work_mode'])) return;
    try {
        $pdo->exec("UPDATE users SET work_location = work_mode WHERE (work_location IS NULL OR work_location = '') AND work_mode IN ('onsite','remote')");
        $pdo->exec("UPDATE users SET work_mode = work_location WHERE work_location IN ('onsite','remote') AND (work_mode IS NULL OR work_mode <> work_location)");
        @file_put_contents($flag, date('c'));
    } catch (Throwable $e) {
        error_log('users_work_sync_v1: ' . $e->getMessage());
    }
}

/**
 * شرطِ جست‌وجوی کاربر با نام یا موبایل: ارقامِ فارسی/عربی/انگلیسی، با یا بدونِ صفرِ اول (۰۹۱۲ / ۹۱۲ / +98912…).
 * @return array{0:string,1:array} [SQL, params]
 */
function users_search_sql(string $q, string $alias = ''): array
{
    $a = $alias !== '' ? $alias . '.' : '';
    $q = trim($q);
    $sql = ["{$a}full_name LIKE ?"];
    $params = ['%' . $q . '%'];
    $digits = preg_replace('/\D+/', '', normalize_digits($q));
    if ($digits !== '' && mb_strlen($digits) >= 3 && preg_match('/^[\d\s+\-()]+$/u', normalize_digits($q))) {
        if (str_starts_with($digits, '0098')) $digits = substr($digits, 4);
        elseif (str_starts_with($digits, '98') && strlen($digits) >= 12) $digits = substr($digits, 2);
        $core = ltrim($digits, '0');
        if ($core !== '') {
            $sql[] = "{$a}mobile LIKE ?";
            $params[] = '%' . $core . '%';
        }
    }
    return ['(' . implode(' OR ', $sql) . ')', $params];
}

function apply_call_import_followup_outcome(PDO $pdo, int $customerId, bool $connected, string $baseDateG, string $currentStatus, bool $statusLocked): string
{
    if ($connected) {
        $nextDate = date('Y-m-d', strtotime($baseDateG . ' +3 days'));
        $pdo->prepare('UPDATE customers SET next_followup_date = ? WHERE id = ?')->execute([$nextDate, $customerId]);
        return $currentStatus;
    }
    $nextDate = date('Y-m-d', strtotime($baseDateG . ' +1 day'));
    $newStatus = $statusLocked ? $currentStatus : 'عدم پاسخ';
    $pdo->prepare('UPDATE customers SET status = ?, next_followup_date = ? WHERE id = ? AND status_locked = 0')
        ->execute([$newStatus, $nextDate, $customerId]);
    if ($statusLocked) {
        $pdo->prepare('UPDATE customers SET next_followup_date = ? WHERE id = ? AND status_locked = 1')
            ->execute([$nextDate, $customerId]);
    }
    return $newStatus;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

function sql_first_time_mobile_condition(PDO $pdo, string $alias = 'c1'): string
{
    if (customer_phone_normalized_ready($pdo)) {
        return "NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile_normalized IS NOT NULL AND c2.mobile_normalized = {$alias}.mobile_normalized)
             AND NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile_normalized IS NOT NULL AND c2.mobile2_normalized = {$alias}.mobile_normalized)
             AND NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile2_normalized IS NOT NULL AND c2.mobile_normalized = {$alias}.mobile2_normalized)
             AND NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile2_normalized IS NOT NULL AND c2.mobile2_normalized = {$alias}.mobile2_normalized)";
    }
    return "NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile IS NOT NULL AND (c2.mobile = {$alias}.mobile OR c2.mobile_2 = {$alias}.mobile))
         AND NOT EXISTS (SELECT 1 FROM customers c2 WHERE c2.created_at < {$alias}.created_at AND {$alias}.mobile_2 IS NOT NULL AND (c2.mobile = {$alias}.mobile_2 OR c2.mobile_2 = {$alias}.mobile_2))";
}

function followup_priority_order(): array
{
    return [
        'در انتظار پرداخت' => 1, 'در انتظار تصمیم' => 2, 'جلسه برگزار شد' => 3,
        'در حال پیگیری' => 4, 'جدید' => 5, 'عدم پاسخ' => 6, 'مشتری قدیمی' => 7, 'تعویق' => 8,
    ];
}

function export_table_as_xls(string $filename, array $headers, array $rows): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    echo '<html dir="rtl"><head><meta charset="UTF-8"></head><body><table border="1"><thead><tr>';
    foreach ($headers as $h) echo '<th style="background:#eee; font-weight:bold;">' . htmlspecialchars((string) $h, ENT_QUOTES, 'UTF-8') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) echo '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></body></html>';
    exit;
}

function login_attempts_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts'");
        $exists = (bool) $chk->fetchColumn();
    } catch (Throwable $e) { $exists = false; }
    return $exists;
}

function login_is_locked_out(PDO $pdo, string $identifier): int
{
    if (!login_attempts_table_exists($pdo)) return 0;
    $stmt = $pdo->prepare('SELECT COUNT(*), MAX(attempted_at) FROM login_attempts WHERE identifier = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
    $stmt->execute([$identifier]);
    [$count, $lastAttempt] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int) $count < LOGIN_MAX_ATTEMPTS || !$lastAttempt) return 0;
    return max(1, LOGIN_LOCKOUT_MINUTES - (int) floor((time() - strtotime($lastAttempt)) / 60));
}

function login_record_failed_attempt(PDO $pdo, string $identifier): void
{
    if (!login_attempts_table_exists($pdo)) return;
    $pdo->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$identifier]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

function login_clear_attempts(PDO $pdo, string $identifier): void
{
    if (!login_attempts_table_exists($pdo)) return;
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$identifier]);
}

function is_valid_iran_mobile(string $mobile): bool
{
    return (bool) preg_match('/^09\d{9}$/', trim($mobile));
}

const REMEMBER_TOKEN_COOKIE = 'ac_remember';
const REMEMBER_TOKEN_DAYS = 30;

function is_https_request(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function remember_tokens_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) return $exists;
    try {
        $chk = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_remember_tokens'");
        $exists = (bool) $chk->fetchColumn();
    } catch (Throwable $e) { $exists = false; }
    return $exists;
}

function remember_cookie_set(string $value, int $expiresAt): void
{
    setcookie(REMEMBER_TOKEN_COOKIE, $value, ['expires' => $expiresAt, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[REMEMBER_TOKEN_COOKIE] = $value;
}

function remember_cookie_clear(): void
{
    setcookie(REMEMBER_TOKEN_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https_request(), 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[REMEMBER_TOKEN_COOKIE]);
}

function create_remember_token(PDO $pdo, int $userId): void
{
    if (!remember_tokens_table_exists($pdo)) return;
    try {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(33));
        $expiresAt = time() + REMEMBER_TOKEN_DAYS * 86400;
        $pdo->prepare('INSERT INTO user_remember_tokens (user_id, selector, validator_hash, expires_at, user_agent) VALUES (?,?,?,?,?)')
            ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expiresAt), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        remember_cookie_set($selector . ':' . $validator, $expiresAt);
    } catch (Throwable $e) {}
}

function try_login_from_remember_cookie(): int
{
    $cookieVal = $_COOKIE[REMEMBER_TOKEN_COOKIE] ?? '';
    if ($cookieVal === '' || !str_contains($cookieVal, ':')) return 0;
    [$selector, $validator] = explode(':', $cookieVal, 2);
    if ($selector === '' || $validator === '') return 0;
    try {
        $pdo = db();
        if (!remember_tokens_table_exists($pdo)) return 0;
        $stmt = $pdo->prepare('SELECT * FROM user_remember_tokens WHERE selector = ? LIMIT 1');
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
        if (!$row) return 0;
        if (strtotime($row['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')->execute([$row['id']]);
            remember_cookie_clear();
            return 0;
        }
        if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([(int) $row['user_id']]);
            remember_cookie_clear();
            return 0;
        }
        $userChk = $pdo->prepare('SELECT is_active FROM users WHERE id = ? LIMIT 1');
        $userChk->execute([(int) $row['user_id']]);
        $u = $userChk->fetch();
        if (!$u || !$u['is_active']) {
            $pdo->prepare('DELETE FROM user_remember_tokens WHERE id = ?')->execute([$row['id']]);
            remember_cookie_clear();
            return 0;
        }
        $newValidator = bin2hex(random_bytes(33));
        $newExpiresAt = time() + REMEMBER_TOKEN_DAYS * 86400;
        $pdo->prepare('UPDATE user_remember_tokens SET validator_hash = ?, expires_at = ? WHERE id = ?')
            ->execute([hash('sha256', $newValidator), date('Y-m-d H:i:s', $newExpiresAt), $row['id']]);
        remember_cookie_set($selector . ':' . $newValidator, $newExpiresAt);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $row['user_id'];
        return (int) $row['user_id'];
    } catch (Throwable $e) { return 0; }
}

function delete_current_remember_token(PDO $pdo): void
{
    $cookieVal = $_COOKIE[REMEMBER_TOKEN_COOKIE] ?? '';
    if ($cookieVal !== '' && str_contains($cookieVal, ':')) {
        [$selector] = explode(':', $cookieVal, 2);
        if ($selector !== '' && remember_tokens_table_exists($pdo)) {
            try { $pdo->prepare('DELETE FROM user_remember_tokens WHERE selector = ?')->execute([$selector]); } catch (Throwable $e) {}
        }
    }
    remember_cookie_clear();
}

function invalidate_all_remember_tokens(PDO $pdo, int $userId): void
{
    if (!remember_tokens_table_exists($pdo)) return;
    try { $pdo->prepare('DELETE FROM user_remember_tokens WHERE user_id = ?')->execute([$userId]); } catch (Throwable $e) {}
}

function avatar_markup(array $user, string $base, string $circleClass = 'sidebar-avatar'): string
{
    $initial = mb_substr($user['full_name'] ?? '?', 0, 1);
    if (!empty($user['profile_image'])) {
        return '<span class="' . e($circleClass) . ' avatar-has-img"><img src="' . e($base . $user['profile_image']) . '" alt=""></span>';
    }
    return '<span class="' . e($circleClass) . '">' . e($initial) . '</span>';
}

function avatar_allowed_extensions(): array { return ['jpg', 'jpeg', 'png', 'webp', 'gif']; }

function validate_avatar_upload(array $file): ?array
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 2 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, avatar_allowed_extensions(), true)) return null;
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return null;
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
    return ['ext' => $ext === 'jpeg' ? 'jpg' : $ext];
}

function chat_thread_key(int $userA, ?int $userB): string
{
    if ($userB === null) return 'broadcast';
    $ids = [$userA, $userB]; sort($ids);
    return 'dm:' . $ids[0] . ':' . $ids[1];
}

function chat_can_broadcast(array $user): bool { return $user['role'] === 'admin'; }
function chat_image_allowed_extensions(): array { return ['jpg', 'jpeg', 'png', 'webp']; }

function validate_chat_image_upload(array $file): ?array
{
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 3 * 1024 * 1024) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, chat_image_allowed_extensions(), true)) return null;
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return null;
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    return ['ext' => $ext === 'jpeg' ? 'jpg' : $ext];
}

// =====================================================================
// ★ admin_classify_imported_calls — طبقه‌بندی تماس‌های وارداتی
// =====================================================================
function admin_classify_imported_calls(PDO $pdo, string $from, string $to, int $minSeconds = 0): array
{
    $stmt = $pdo->prepare("SELECT f.id, f.created_by, f.customer_id, f.created_at, f.event_time,
                                  f.call_duration_seconds, c.full_name, c.mobile, c.mobile_2,
                                  c.mobile_normalized, c.mobile2_normalized
                           FROM followups f JOIN customers c ON c.id = f.customer_id
                           WHERE f.source IN ('call_import','novatel_import') AND f.followup_date BETWEEN ? AND ? AND c.contact_type = 'customer'
                           ORDER BY f.id");
    $stmt->execute([$from, $to]);
    $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = ['total' => count($calls), 'connected' => 0, 'missed' => 0, 'new_ids' => [], 'followup_ids' => [], 'calls' => $calls];
    if (!$calls) return $result;
    $normalize = static function (?string $phone): string {
        $phone = trim((string) $phone);
        if ($phone === '') return '';
        $n = normalize_phone_for_match($phone);
        return $n ?? '';
    };
    $sameUploadGrace = 120;
    $keys = [];
    foreach ($calls as $i => $call) {
        if ((int)($call['call_duration_seconds'] ?? 0) > 0) $result['connected']++;
        else $result['missed']++;
        $callKeys = [];
        foreach (['mobile_normalized','mobile2_normalized','mobile','mobile_2'] as $col) {
            $key = $normalize($call[$col] ?? null);
            if ($key !== '') { $keys[$key] = true; $callKeys[$key] = true; }
        }
        $calls[$i]['_keys'] = array_keys($callKeys);
    }
    $firstSeen = [];
    $see = static function (string $key, ?string $created) use (&$firstSeen, $keys): void {
        if ($key === '' || !isset($keys[$key]) || $created === null || $created === '') return;
        if (!isset($firstSeen[$key]) || $created < $firstSeen[$key]) $firstSeen[$key] = $created;
    };
    $keysList = array_keys($keys);
    if ($keysList && customer_phone_normalized_ready($pdo)) {
        foreach (array_chunk($keysList, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['mobile_normalized', 'mobile2_normalized'] as $col) {
                $q = $pdo->prepare("SELECT MIN(created_at) AS first_at, $col AS k FROM customers WHERE $col IN ($ph) GROUP BY $col");
                $q->execute($chunk);
                while ($r = $q->fetch(PDO::FETCH_ASSOC)) $see((string) $r['k'], (string) $r['first_at']);
            }
        }
    }
    $missing = array_values(array_filter($keysList, static fn($k) => !isset($firstSeen[$k])));
    if ($missing) {
        foreach (array_chunk($missing, 500) as $chunk) {
            $variants = [];
            foreach ($chunk as $k) {
                $variants[] = $k; $variants[] = substr($k, 1);
                $variants[] = '98' . substr($k, 1); $variants[] = '+98' . substr($k, 1);
            }
            $variants = array_values(array_unique($variants));
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $q = $pdo->prepare("SELECT created_at, mobile, mobile_2 FROM customers WHERE mobile IN ($ph) OR mobile_2 IN ($ph)");
            $q->execute(array_merge($variants, $variants));
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                $see($normalize($r['mobile'] ?? null), (string) $r['created_at']);
                $see($normalize($r['mobile_2'] ?? null), (string) $r['created_at']);
            }
        }
    }
    if ($keysList && function_exists('customer_relations_ready') && customer_relations_ready($pdo)) {
        try {
            foreach (array_chunk($keysList, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $q = $pdo->prepare("SELECT MIN(created_at) AS first_at, phone_normalized AS k FROM customer_phones WHERE phone_normalized IN ($ph) GROUP BY phone_normalized");
                $q->execute($chunk);
                while ($r = $q->fetch(PDO::FETCH_ASSOC)) $see((string) $r['k'], (string) $r['first_at']);
            }
        } catch (Throwable $e) {}
    }
    foreach ($calls as $call) {
        if ((int)($call['call_duration_seconds'] ?? 0) <= $minSeconds) continue;
        $callTs = strtotime((string) $call['created_at']) ?: 0;
        $isOld = false;
        foreach ($call['_keys'] as $key) {
            if (isset($firstSeen[$key]) && (strtotime($firstSeen[$key]) ?: PHP_INT_MAX) < $callTs - $sameUploadGrace) {
                $isOld = true; break;
            }
        }
        if ($isOld) $result['followup_ids'][] = (int)$call['id'];
        else $result['new_ids'][] = (int)$call['id'];
    }
    foreach ($result['calls'] as &$c) unset($c['_keys']);
    unset($c);
    return $result;
}

function calls_new_followup_counts(PDO $pdo, string $from, string $to, int $minSeconds = 0): array
{
    static $cache = [];
    $ck = $from . '|' . $to . '|' . $minSeconds;
    if (isset($cache[$ck])) return $cache[$ck];
    $out = ['by_user' => [], 'class' => []];
    try {
        $r = admin_classify_imported_calls($pdo, $from, $to, $minSeconds);
        $owner = [];
        foreach ($r['calls'] as $c) $owner[(int) $c['id']] = (int) ($c['created_by'] ?? 0);
        foreach (['new' => $r['new_ids'], 'followup' => $r['followup_ids']] as $kind => $ids) {
            foreach ($ids as $fid) {
                $u = $owner[$fid] ?? 0;
                $out['class'][$fid] = $kind;
                if (!isset($out['by_user'][$u])) $out['by_user'][$u] = ['new' => 0, 'followup' => 0];
                $out['by_user'][$u][$kind]++;
            }
        }
    } catch (Throwable $e) { error_log('calls_new_followup_counts: ' . $e->getMessage()); }
    return $cache[$ck] = $out;
}

function customer_phone_normalized_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT mobile_normalized, mobile2_normalized FROM customers LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function customer_landline_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT landline_phone FROM customers LIMIT 1'); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function sync_customer_phone_normalized(PDO $pdo, int $customerId, ?string $mobile, ?string $mobile2 = null): void
{
    if (!customer_phone_normalized_ready($pdo)) return;
    $n1 = (!empty($mobile) && trim($mobile) !== '') ? normalize_phone_for_match($mobile) : null;
    $n2 = (!empty($mobile2) && trim($mobile2) !== '') ? normalize_phone_for_match($mobile2) : null;
    $pdo->prepare('UPDATE customers SET mobile_normalized = ?, mobile2_normalized = ? WHERE id = ?')->execute([$n1, $n2, $customerId]);
}

function find_phone_conflicts(PDO $pdo, int $customerId, ?string $mobile, ?string $mobile2 = null): array
{
    $keys = array_values(array_unique(array_filter([
        $mobile !== null && trim($mobile) !== '' ? normalize_phone_for_match($mobile) : null,
        $mobile2 !== null && trim($mobile2) !== '' ? normalize_phone_for_match($mobile2) : null,
    ], fn($v) => $v !== null && $v !== '')));
    if (!$keys) return [];
    if (customer_phone_normalized_ready($pdo)) {
        $conflicts = [];
        foreach ($keys as $key) {
            $stmt = $pdo->prepare("SELECT c.id, c.owner_user_id, c.full_name, c.mobile, c.mobile_2, c.status, c.updated_at, u.full_name AS owner_name, u.role AS owner_role
                                   FROM customers c JOIN users u ON u.id = c.owner_user_id
                                   WHERE c.contact_type = 'customer' AND c.id != ? AND (c.mobile_normalized = ? OR c.mobile2_normalized = ?) ORDER BY c.id");
            $stmt->execute([$customerId, $key, $key]);
            foreach ($stmt->fetchAll() as $row) $conflicts[$row['id']] = $row;
        }
        return array_values($conflicts);
    }
    $stmt = $pdo->query("SELECT c.id, c.owner_user_id, c.full_name, c.mobile, c.mobile_2, c.status, c.updated_at, u.full_name AS owner_name, u.role AS owner_role
                         FROM customers c JOIN users u ON u.id = c.owner_user_id WHERE c.contact_type = 'customer' ORDER BY c.id");
    $rows = $stmt->fetchAll();
    $conflicts = [];
    foreach ($rows as $row) {
        if ((int) $row['id'] === $customerId) continue;
        $rowKeys = array_values(array_unique(array_filter([
            !empty($row['mobile']) ? normalize_phone_for_match((string) $row['mobile']) : null,
            !empty($row['mobile_2']) ? normalize_phone_for_match((string) $row['mobile_2']) : null,
        ], fn($v) => $v !== null && $v !== '')));
        if (array_intersect($keys, $rowKeys)) $conflicts[] = $row;
    }
    return $conflicts;
}

function get_vapid_keys(): ?array
{
    static $keys = null;
    static $loaded = false;
    if ($loaded) return $keys;
    $loaded = true;
    $file = __DIR__ . '/../config/vapid.php';
    if (!is_file($file)) return null;
    $data = include $file;
    if (!is_array($data) || empty($data['private_pem']) || empty($data['public_raw_b64url'])) return null;
    $keys = $data;
    return $keys;
}

function send_push_to_user(PDO $pdo, int $userId, string $title, string $body, string $url = 'dashboard.php'): void
{
    $vapidKeys = get_vapid_keys();
    if (!$vapidKeys || !ec_p256_available()) return;
    $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $subs = $stmt->fetchAll();
    if (!$subs) return;
    $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url], JSON_UNESCAPED_UNICODE);
    $subject = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'aradcontact.ir');
    foreach ($subs as $sub) {
        $result = web_push_send(['endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth']], $payload, $vapidKeys, $subject);
        if ($result['expired']) $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$sub['id']]);
    }
}

function customer_relations_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $pdo->query('SELECT id FROM customer_phones LIMIT 1');
            $pdo->query('SELECT id FROM customer_employee_relations LIMIT 1');
            $ready = true;
        } catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

function customer_phones_max(): int { return 5; }

function sync_customer_phone_entries(PDO $pdo, int $customerId, ?string $mobile, ?string $mobile2 = null): void
{
    if (!customer_relations_ready($pdo)) return;
    $entries = [
        ['phone' => $mobile, 'source' => 'legacy_mobile', 'primary' => true],
        ['phone' => $mobile2, 'source' => 'legacy_mobile2', 'primary' => false],
    ];
    foreach ($entries as $entry) {
        $phone = trim((string) $entry['phone']);
        if ($phone === '') continue;
        $normalized = normalize_phone_for_match($phone);
        try {
            $exists = $pdo->prepare('SELECT id FROM customer_phones WHERE customer_id = ? AND phone = ? LIMIT 1');
            $exists->execute([$customerId, $phone]);
            if ($exists->fetchColumn()) {
                $pdo->prepare('UPDATE customer_phones SET phone_normalized = ? WHERE customer_id = ? AND phone = ?')->execute([$normalized, $customerId, $phone]);
                continue;
            }
            $hasPrimaryStmt = $pdo->prepare('SELECT 1 FROM customer_phones WHERE customer_id = ? AND is_primary = 1 LIMIT 1');
            $hasPrimaryStmt->execute([$customerId]);
            $isPrimary = $entry['primary'] && !$hasPrimaryStmt->fetchColumn();
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_phones WHERE customer_id = ?');
            $countStmt->execute([$customerId]);
            if ((int) $countStmt->fetchColumn() >= customer_phones_max()) continue;
            $pdo->prepare('INSERT INTO customer_phones (customer_id, phone, phone_normalized, is_primary, source) VALUES (?,?,?,?,?)')
                ->execute([$customerId, $phone, $normalized, $isPrimary ? 1 : 0, $entry['source']]);
        } catch (Throwable $e) {}
    }
}

function add_customer_phone(PDO $pdo, int $customerId, string $rawPhone, ?string $label = null, string $source = 'manual'): array
{
    if (!customer_relations_ready($pdo)) return ['ok' => false, 'error' => 'جدول شماره‌های مشتری ساخته نشده.'];
    $phone = trim($rawPhone);
    if ($phone === '') return ['ok' => false, 'error' => 'شماره خالی است.'];
    $normalized = normalize_phone_for_match($phone);
    if ($normalized !== null) {
        $conflictStmt = $pdo->prepare('SELECT customer_id FROM customer_phones WHERE phone_normalized = ? AND customer_id <> ? LIMIT 1');
        $conflictStmt->execute([$normalized, $customerId]);
        $conflictCustomerId = $conflictStmt->fetchColumn();
        if ($conflictCustomerId) {
            $custStmt = $pdo->prepare('SELECT id, full_name, mobile FROM customers WHERE id = ? LIMIT 1');
            $custStmt->execute([(int) $conflictCustomerId]);
            return ['ok' => false, 'error' => 'این شماره قبلاً برای مشتری دیگری ثبت شده.', 'conflict' => $custStmt->fetch(PDO::FETCH_ASSOC) ?: null];
        }
    }
    $dupStmt = $pdo->prepare('SELECT id FROM customer_phones WHERE customer_id = ? AND phone = ? LIMIT 1');
    $dupStmt->execute([$customerId, $phone]);
    if ($dupStmt->fetchColumn()) return ['ok' => false, 'error' => 'این شماره از قبل ثبت شده.'];
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_phones WHERE customer_id = ?');
    $countStmt->execute([$customerId]);
    if ((int) $countStmt->fetchColumn() >= customer_phones_max()) return ['ok' => false, 'error' => 'حداکثر ' . to_persian_digits((string) customer_phones_max()) . ' شماره.'];
    $hasPrimaryStmt = $pdo->prepare('SELECT 1 FROM customer_phones WHERE customer_id = ? AND is_primary = 1 LIMIT 1');
    $hasPrimaryStmt->execute([$customerId]);
    $isPrimary = !$hasPrimaryStmt->fetchColumn();
    $pdo->prepare('INSERT INTO customer_phones (customer_id, phone, phone_normalized, label, is_primary, source) VALUES (?,?,?,?,?,?)')
        ->execute([$customerId, $phone, $normalized, $label, $isPrimary ? 1 : 0, $source]);
    return ['ok' => true, 'phone_id' => (int) $pdo->lastInsertId()];
}

function customer_phones_for(PDO $pdo, $customerId): array
{
    if (!customer_relations_ready($pdo)) return [];
    $ids = is_array($customerId) ? array_values(array_unique(array_map('intval', $customerId))) : [(int) $customerId];
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM customer_phones WHERE customer_id IN ({$placeholders}) ORDER BY is_primary DESC, id ASC");
    $stmt->execute($ids);
    $unique = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) ($row['phone_normalized'] ?? '');
        if ($key === '') $key = normalize_phone_for_match((string) $row['phone']) ?? preg_replace('/\D+/', '', normalize_digits((string) $row['phone']));
        if ($key === '' || isset($unique[$key])) continue;
        $unique[$key] = $row;
    }
    return array_values($unique);
}

function find_customer_by_any_phone(PDO $pdo, string $rawPhone): array
{
    $normalized = normalize_phone_for_match($rawPhone);
    if ($normalized === null) return ['status' => 'none'];
    $customerIds = [];
    if (customer_relations_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT DISTINCT customer_id FROM customer_phones WHERE phone_normalized = ?');
        $stmt->execute([$normalized]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $customerIds[(int) $id] = true;
    }
    if (customer_phone_normalized_ready($pdo)) {
        $legacyStmt = $pdo->prepare('SELECT id FROM customers WHERE mobile_normalized = ? OR mobile2_normalized = ?');
        $legacyStmt->execute([$normalized, $normalized]);
    } else {
        $legacyStmt = $pdo->prepare('SELECT id FROM customers WHERE mobile = ? OR mobile_2 = ?');
        $legacyStmt->execute([$rawPhone, $rawPhone]);
    }
    foreach ($legacyStmt->fetchAll(PDO::FETCH_COLUMN) as $id) $customerIds[(int) $id] = true;
    if (!$customerIds) return ['status' => 'none'];
    $ids = array_keys($customerIds);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $custStmt = $pdo->prepare("SELECT * FROM customers WHERE id IN ({$placeholders})");
    $custStmt->execute($ids);
    $customers = $custStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($customers) > 1) return ['status' => 'ambiguous', 'customers' => $customers];
    return ['status' => 'found', 'customer' => $customers[0]];
}

function customer_ids_sharing_phone(PDO $pdo, int $customerId): array
{
    $stmt = $pdo->prepare('SELECT mobile, mobile_2 FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [$customerId];
    $normalized = [];
    foreach ([$row['mobile'], $row['mobile_2']] as $raw) {
        if (!empty($raw)) {
            $n = normalize_phone_for_match((string) $raw);
            if ($n !== null) $normalized[$n] = true;
        }
    }
    if (customer_relations_ready($pdo)) {
        $stmt2 = $pdo->prepare('SELECT phone_normalized FROM customer_phones WHERE customer_id = ? AND phone_normalized IS NOT NULL');
        $stmt2->execute([$customerId]);
        foreach ($stmt2->fetchAll(PDO::FETCH_COLUMN) as $n) $normalized[$n] = true;
    }
    $ids = [$customerId => true];
    if ($normalized) {
        $numbers = array_keys($normalized);
        $placeholders = implode(',', array_fill(0, count($numbers), '?'));
        if (customer_relations_ready($pdo)) {
            $stmt3 = $pdo->prepare("SELECT DISTINCT customer_id FROM customer_phones WHERE phone_normalized IN ({$placeholders})");
            $stmt3->execute($numbers);
            foreach ($stmt3->fetchAll(PDO::FETCH_COLUMN) as $cid) $ids[(int) $cid] = true;
        }
        if (customer_phone_normalized_ready($pdo)) {
            $stmt4 = $pdo->prepare("SELECT id FROM customers WHERE mobile_normalized IN ({$placeholders}) OR mobile2_normalized IN ({$placeholders})");
            $stmt4->execute(array_merge($numbers, $numbers));
            foreach ($stmt4->fetchAll(PDO::FETCH_COLUMN) as $cid) $ids[(int) $cid] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}

function get_or_create_relation(PDO $pdo, int $customerId, int $employeeId, string $createdBySource = 'manual_link', bool $forcePrimary = false): ?array
{
    if (!customer_relations_ready($pdo)) return null;
    $find = $pdo->prepare('SELECT * FROM customer_employee_relations WHERE customer_id = ? AND employee_id = ? LIMIT 1');
    $find->execute([$customerId, $employeeId]);
    $relation = $find->fetch(PDO::FETCH_ASSOC);
    if ($relation) {
        if ($forcePrimary && !$relation['is_primary']) {
            $pdo->prepare('UPDATE customer_employee_relations SET is_primary = 0 WHERE customer_id = ?')->execute([$customerId]);
            $pdo->prepare('UPDATE customer_employee_relations SET is_primary = 1 WHERE id = ?')->execute([$relation['id']]);
            $relation['is_primary'] = 1;
        }
        return $relation;
    }
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$employeeId]);
    $unitRole = $roleStmt->fetchColumn() ?: null;
    $hasAnyStmt = $pdo->prepare('SELECT 1 FROM customer_employee_relations WHERE customer_id = ? LIMIT 1');
    $hasAnyStmt->execute([$customerId]);
    $isPrimary = $forcePrimary || !$hasAnyStmt->fetchColumn();
    if ($isPrimary) {
        $pdo->prepare('UPDATE customer_employee_relations SET is_primary = 0 WHERE customer_id = ?')->execute([$customerId]);
    }
    $custStmt = $pdo->prepare('SELECT status, status_locked, next_followup_date FROM customers WHERE id = ? LIMIT 1');
    $custStmt->execute([$customerId]);
    $cust = $custStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $ins = $pdo->prepare('INSERT INTO customer_employee_relations (customer_id, employee_id, unit_role, status, status_locked, next_followup_date, is_primary, created_by_source) VALUES (?,?,?,?,?,?,?,?)');
    $ins->execute([
        $customerId, $employeeId, $unitRole,
        $isPrimary ? ($cust['status'] ?? 'جدید') : 'جدید',
        $isPrimary ? (int) ($cust['status_locked'] ?? 0) : 0,
        $isPrimary ? ($cust['next_followup_date'] ?? null) : null,
        $isPrimary ? 1 : 0, $createdBySource,
    ]);
    $find->execute([$customerId, $employeeId]);
    return $find->fetch(PDO::FETCH_ASSOC) ?: null;
}

function create_relation_for_employee(PDO $pdo, int $customerId, int $employeeId): array
{
    $relation = get_or_create_relation($pdo, $customerId, $employeeId, 'manual_link');
    if (!$relation) return ['ok' => false, 'error' => 'امکان ایجاد ارتباط وجود ندارد.'];
    return ['ok' => true, 'relation' => $relation];
}

function customer_relations_for(PDO $pdo, $customerId): array
{
    if (!customer_relations_ready($pdo)) return [];
    $ids = is_array($customerId) ? array_values(array_unique(array_map('intval', $customerId))) : [(int) $customerId];
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT r.*, u.full_name AS employee_name, u.role AS employee_current_role, u.mobile AS employee_mobile
        FROM customer_employee_relations r JOIN users u ON u.id = r.employee_id
        WHERE r.customer_id IN ({$placeholders}) ORDER BY r.is_primary DESC, r.created_at ASC");
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_relation_status(PDO $pdo, int $relationId, string $status, ?string $nextFollowupDate, bool $statusLocked = false): void
{
    if (!customer_relations_ready($pdo)) return;
    $pdo->prepare('UPDATE customer_employee_relations SET status = ?, next_followup_date = ?, status_locked = ? WHERE id = ?')
        ->execute([$status, $nextFollowupDate, $statusLocked ? 1 : 0, $relationId]);
    $relStmt = $pdo->prepare('SELECT customer_id, is_primary FROM customer_employee_relations WHERE id = ? LIMIT 1');
    $relStmt->execute([$relationId]);
    $rel = $relStmt->fetch(PDO::FETCH_ASSOC);
    if ($rel && (int) $rel['is_primary'] === 1) {
        $pdo->prepare('UPDATE customers SET status = ?, next_followup_date = ?, status_locked = ? WHERE id = ?')
            ->execute([$status, $nextFollowupDate, $statusLocked ? 1 : 0, (int) $rel['customer_id']]);
    }
}

// =====================================================================
// ★★★ تابع کلیدی: record_activity_multi — INSERT با ستون‌های denormalized
// =====================================================================
function record_activity_multi(PDO $pdo, array $args): int
{
    $customerId = (int) $args['customer_id'];
    $employeeId = (int) $args['employee_id'];
    $phoneId = isset($args['customer_phone_id']) ? (int) $args['customer_phone_id'] : null;
    $followupDate = (string) ($args['followup_date'] ?? date('Y-m-d'));
    $eventTime = $args['event_time'] ?? null;
    $description = $args['description'] ?? null;
    $statusAfter = $args['status_after'] ?? null;
    $nextFollowupDate = $args['next_followup_date'] ?? null;
    $durationSeconds = $args['call_duration_seconds'] ?? null;
    $source = $args['source'] ?? 'manual';

    // ⭐ خوندن contact_type از customers
    $contactType = 'customer';
    try {
        $ctStmt = $pdo->prepare('SELECT contact_type FROM customers WHERE id = ? LIMIT 1');
        $ctStmt->execute([$customerId]);
        $ct = $ctStmt->fetchColumn();
        if ($ct) $contactType = (string) $ct;
    } catch (Throwable $e) {}

    // ⭐ محاسبه‌ی is_phone_call
    $isPhoneCall = (
        $contactType === 'customer' &&
        in_array($source, ['call_import', 'novatel_import'], true) &&
        ((int) $durationSeconds) > 10
    ) ? 1 : 0;

    $relation = get_or_create_relation($pdo, $customerId, $employeeId, $source);
    $relationId = $relation['id'] ?? null;

    $followupNumStmt = $pdo->prepare('SELECT COALESCE(MAX(followup_number), 0) + 1 FROM followups WHERE customer_id = ?');
    $followupNumStmt->execute([$customerId]);
    $followupNumber = (int) $followupNumStmt->fetchColumn();

    $ins = $pdo->prepare('INSERT INTO followups
        (customer_id, customer_phone_id, relation_id, followup_number, followup_date, event_time,
         description, status_after, next_followup_date, call_duration_seconds, source, created_by,
         contact_type, is_phone_call)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
        $customerId, $phoneId, $relationId, $followupNumber, $followupDate, $eventTime,
        $description, $statusAfter, $nextFollowupDate, $durationSeconds, $source, $employeeId,
        $contactType, $isPhoneCall,
    ]);
    $followupId = (int) $pdo->lastInsertId();

    $pdo->prepare('UPDATE customers SET followup_count = followup_count + 1 WHERE id = ?')->execute([$customerId]);
    if ($relationId) {
        $pdo->prepare('UPDATE customer_employee_relations SET followup_count = followup_count + 1 WHERE id = ?')->execute([$relationId]);
        if ($statusAfter !== null) {
            update_relation_status($pdo, (int) $relationId, (string) $statusAfter, $nextFollowupDate ? (string) $nextFollowupDate : null);
        }
    }

    return $followupId;
}

function customer_profile_summary(PDO $pdo, $customerId): array
{
    $ids = is_array($customerId) ? array_values(array_unique(array_map('intval', $customerId))) : [(int) $customerId];
    $summary = [
        'phones' => customer_phones_for($pdo, $ids),
        'relations' => customer_relations_for($pdo, $ids),
        'by_unit' => [], 'by_employee' => [], 'by_phone' => [],
        'total_calls' => 0, 'total_seconds' => 0,
    ];
    if (!$ids) {
        $summary['related_employee_count'] = 0; $summary['related_unit_count'] = 0; $summary['phone_count'] = 0;
        return $summary;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT f.customer_phone_id, f.relation_id, f.created_by, u.role AS unit_role, u.full_name AS employee_name,
                                  COUNT(*) AS call_count, COALESCE(SUM(f.call_duration_seconds), 0) AS total_seconds
                           FROM followups f LEFT JOIN users u ON u.id = f.created_by
                           WHERE f.customer_id IN ({$placeholders})
                           GROUP BY f.customer_phone_id, f.relation_id, f.created_by, u.role, u.full_name");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $unit = $row['unit_role'] ?: 'نامشخص';
        $employeeKey = $row['created_by'] ?: 0;
        $phoneKey = $row['customer_phone_id'] ?: 0;
        $count = (int) $row['call_count'];
        $seconds = (int) $row['total_seconds'];
        if (!isset($summary['by_unit'][$unit])) $summary['by_unit'][$unit] = ['unit' => $unit, 'call_count' => 0, 'total_seconds' => 0];
        $summary['by_unit'][$unit]['call_count'] += $count;
        $summary['by_unit'][$unit]['total_seconds'] += $seconds;
        if (!isset($summary['by_employee'][$employeeKey])) {
            $summary['by_employee'][$employeeKey] = ['employee_id' => $employeeKey, 'employee_name' => $row['employee_name'] ?: 'نامشخص', 'unit' => $unit, 'call_count' => 0, 'total_seconds' => 0];
        }
        $summary['by_employee'][$employeeKey]['call_count'] += $count;
        $summary['by_employee'][$employeeKey]['total_seconds'] += $seconds;
        if (!isset($summary['by_phone'][$phoneKey])) $summary['by_phone'][$phoneKey] = ['phone_id' => $phoneKey, 'call_count' => 0, 'total_seconds' => 0];
        $summary['by_phone'][$phoneKey]['call_count'] += $count;
        $summary['by_phone'][$phoneKey]['total_seconds'] += $seconds;
        $summary['total_calls'] += $count;
        $summary['total_seconds'] += $seconds;
    }
    $summary['by_unit'] = array_values($summary['by_unit']);
    $summary['by_employee'] = array_values($summary['by_employee']);
    $summary['by_phone'] = array_values($summary['by_phone']);
    $summary['related_employee_count'] = count($summary['relations']);
    $summary['related_unit_count'] = count(array_unique(array_column($summary['relations'], 'unit_role')));
    $summary['phone_count'] = count($summary['phones']);
    return $summary;
}

function customer_timeline(PDO $pdo, $customerId, array $filters = []): array
{
    $ids = is_array($customerId) ? array_values(array_unique(array_map('intval', $customerId))) : [(int) $customerId];
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where = ["f.customer_id IN ({$placeholders})"];
    $params = $ids;
    if (!empty($filters['employee_id'])) { $where[] = 'f.created_by = ?'; $params[] = (int) $filters['employee_id']; }
    if (!empty($filters['unit_role'])) { $where[] = 'u.role = ?'; $params[] = (string) $filters['unit_role']; }
    if (!empty($filters['customer_phone_id'])) { $where[] = 'f.customer_phone_id = ?'; $params[] = (int) $filters['customer_phone_id']; }
    if (!empty($filters['date_from'])) { $where[] = 'f.followup_date >= ?'; $params[] = (string) $filters['date_from']; }
    if (!empty($filters['date_to'])) { $where[] = 'f.followup_date <= ?'; $params[] = (string) $filters['date_to']; }
    $sql = "SELECT f.*, u.full_name AS employee_name, u.role AS unit_role, cp.phone AS phone_display
            FROM followups f LEFT JOIN users u ON u.id = f.created_by LEFT JOIN customer_phones cp ON cp.id = f.customer_phone_id
            WHERE " . implode(' AND ', $where) . " ORDER BY f.followup_date DESC, f.event_time DESC, f.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function build_phone_match_map(PDO $pdo, bool $scopeAll, int $userId): array
{
    if ($scopeAll) {
        $custStmt = $pdo->query('SELECT id, full_name, mobile, mobile_2, status, owner_user_id, contact_type, followup_count FROM customers');
    } else {
        $custStmt = null;
        if (customer_relations_ready($pdo)) {
            try {
                $custStmt = $pdo->prepare('SELECT * FROM customers WHERE owner_user_id = ? OR id IN (SELECT customer_id FROM customer_employee_relations WHERE employee_id = ?)');
                $custStmt->execute([$userId, $userId]);
            } catch (Throwable $e) { $custStmt = null; }
        }
        if ($custStmt === null) {
            $custStmt = $pdo->prepare('SELECT * FROM customers WHERE owner_user_id = ?');
            $custStmt->execute([$userId]);
        }
    }
    $phoneMap = [];
    $customersById = [];
    foreach ($custStmt->fetchAll() as $c) {
        $customersById[(int) $c['id']] = $c;
        $norm = normalize_phone_for_match($c['mobile']);
        if ($norm !== null) $phoneMap[$norm][(int) $c['id']] = $c;
        if (!empty($c['mobile_2'])) {
            $norm2 = normalize_phone_for_match($c['mobile_2']);
            if ($norm2 !== null) $phoneMap[$norm2][(int) $c['id']] = $c;
        }
    }
    if (customer_relations_ready($pdo)) {
        $ids = array_keys($customersById);
        foreach (array_chunk($ids, 2000) as $idChunk) {
            $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
            $phonesStmt = $pdo->prepare("SELECT customer_id, phone_normalized FROM customer_phones WHERE phone_normalized IS NOT NULL AND customer_id IN ({$placeholders})");
            $phonesStmt->execute($idChunk);
            foreach ($phonesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int) $row['customer_id'];
                if (isset($customersById[$cid])) $phoneMap[$row['phone_normalized']][$cid] = $customersById[$cid];
            }
        }
    }
    foreach ($phoneMap as $norm => $byId) $phoneMap[$norm] = array_values($byId);
    return $phoneMap;
}

if (!function_exists('arad_ini_bytes')) {
    function arad_ini_bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '' || $val === '-1') return 0;
        $num = (float) $val;
        switch (strtolower(substr($val, -1))) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return (int) $num;
    }
}