<?php
/**
 * توابع مشترکِ ماژولِ «پذیرش کارشناس».
 * این فایل هیچ خروجی HTML تولید نمی‌کند و فقط شامل توابعِ کمکی است.
 * تمامِ توابع، defensive نوشته شده‌اند تا در صورتِ نبودِ جدول/ستونِ موردنیاز
 * (پیش از اجرای مایگریشن) با خطای Fatal متوقف نشوند.
 */

if (!function_exists('reception_table_exists')) {
    function reception_table_exists(PDO $pdo, string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $stmt->execute([$table]);
            $cache[$table] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }
}

if (!function_exists('reception_column_exists')) {
    function reception_column_exists(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $column]);
            $cache[$key] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('reception_module_ready')) {
    function reception_module_ready(PDO $pdo): bool
    {
        return reception_table_exists($pdo, 'reception_applicants')
            && reception_table_exists($pdo, 'reception_statuses');
    }
}

if (!function_exists('reception_normalize_mobile')) {
    function reception_normalize_mobile(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', to_english_digits_safe($raw));
        if ($digits === null) {
            $digits = '';
        }
        if (strpos($digits, '0098') === 0) {
            $digits = '0' . substr($digits, 4);
        } elseif (strpos($digits, '98') === 0 && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (strpos($digits, '9') === 0 && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        if (reception_is_valid_mobile($digits)) {
            return $digits;
        }

        if (function_exists('normalize_phone_for_match')) {
            $n = normalize_phone_for_match($raw);
            if ($n !== '' && reception_is_valid_mobile($n)) {
                return $n;
            }
        }

        return $digits;
    }
}

if (!function_exists('to_english_digits_safe')) {
    function to_english_digits_safe(string $s): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $s = str_replace($persian, $english, $s);
        $s = str_replace($arabic, $english, $s);
        return $s;
    }
}

if (!function_exists('reception_is_valid_mobile')) {
    function reception_is_valid_mobile(string $normalized): bool
    {
        return (bool) preg_match('/^09\d{9}$/', $normalized);
    }
}

if (!function_exists('reception_mobile_intl')) {
    function reception_mobile_intl(string $normalized): string
    {
        if (preg_match('/^0(9\d{9})$/', $normalized, $m)) {
            return '98' . $m[1];
        }
        return preg_replace('/\D+/', '', $normalized);
    }
}

if (!function_exists('reception_password_from_mobile')) {
    function reception_password_from_mobile(string $normalizedMobile): string
    {
        $digits = preg_replace('/\D+/', '', $normalizedMobile);
        return substr($digits, -6);
    }
}

if (!function_exists('reception_load_statuses')) {
    function reception_load_statuses(PDO $pdo, bool $activeOnly = true): array
    {
        if (!reception_table_exists($pdo, 'reception_statuses')) {
            return [];
        }
        try {
            $sql = "SELECT * FROM reception_statuses" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, id ASC";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_status_label')) {
    function reception_status_label(PDO $pdo, string $code): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (reception_load_statuses($pdo, false) as $row) {
                $map[$row['code']] = $row;
            }
        }
        return $map[$code]['label'] ?? $code;
    }
}

if (!function_exists('reception_status_color')) {
    function reception_status_color(PDO $pdo, string $code): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (reception_load_statuses($pdo, false) as $row) {
                $map[$row['code']] = $row;
            }
        }
        return $map[$code]['color'] ?? 'secondary';
    }
}

if (!function_exists('reception_load_social_networks')) {
    function reception_load_social_networks(PDO $pdo, bool $activeOnly = true): array
    {
        if (!reception_table_exists($pdo, 'reception_social_networks')) {
            return [];
        }
        try {
            $sql = "SELECT * FROM reception_social_networks" . ($activeOnly ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, id ASC";
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_social_link')) {
    function reception_social_link(array $network, string $normalizedMobile): string
    {
        $intl = reception_mobile_intl($normalizedMobile);
        $tpl  = (string) ($network['link_template'] ?? '');
        $tpl  = str_replace('{phone_intl}', $intl, $tpl);
        $tpl  = str_replace('{phone}', $normalizedMobile, $tpl);
        return $tpl;
    }
}

if (!function_exists('reception_log_action')) {
    function reception_log_action(PDO $pdo, ?int $applicantId, ?int $actorUserId, string $action, string $details = ''): void
    {
        if (!reception_table_exists($pdo, 'reception_audit_log')) {
            return;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO reception_audit_log (applicant_id, actor_user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$applicantId, $actorUserId, $action, $details]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('reception_record_status_change')) {
    function reception_record_status_change(PDO $pdo, int $applicantId, ?string $oldStatus, string $newStatus, ?int $changedBy): void
    {
        if (reception_table_exists($pdo, 'reception_status_history')) {
            try {
                $stmt = $pdo->prepare("INSERT INTO reception_status_history (applicant_id, old_status, new_status, changed_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$applicantId, $oldStatus, $newStatus, $changedBy]);
            } catch (Throwable $e) {
            }
        }
        reception_log_action($pdo, $applicantId, $changedBy, 'status_change', 'از «' . (string) $oldStatus . '» به «' . $newStatus . '»');
    }
}

if (!function_exists('reception_notify')) {
    function reception_notify(PDO $pdo, int $userId, ?int $applicantId, string $title, string $body = '', ?string $url = null): void
    {
        if (!reception_table_exists($pdo, 'reception_notifications')) {
            return;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO reception_notifications (user_id, applicant_id, title, body, url, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
            $stmt->execute([$userId, $applicantId, $title, $body, $url]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('reception_unread_notification_count')) {
    function reception_unread_notification_count(PDO $pdo, int $userId): int
    {
        if (!reception_table_exists($pdo, 'reception_notifications')) {
            return 0;
        }
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('reception_chat_message_column')) {
    function reception_chat_message_column(PDO $pdo): ?string
    {
        static $col = false;
        if ($col !== false) {
            return $col;
        }
        if (!reception_table_exists($pdo, 'chat_messages')) {
            $col = null;
            return $col;
        }
        $candidates = ['body', 'message', 'content', 'text_body', 'msg'];
        foreach ($candidates as $c) {
            if (reception_column_exists($pdo, 'chat_messages', $c)) {
                $col = $c;
                return $col;
            }
        }
        $col = null;
        return $col;
    }
}

if (!function_exists('reception_send_chat_message')) {
    function reception_send_chat_message(PDO $pdo, int $fromUserId, int $toUserId, string $text): bool
    {
        if (!function_exists('chat_thread_key')) {
            return false;
        }
        $col = reception_chat_message_column($pdo);
        if ($col === null) {
            return false;
        }
        try {
            $threadKey = chat_thread_key($fromUserId, $toUserId);
            $sql = "INSERT INTO chat_messages (sender_id, recipient_id, thread_key, `$col`, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fromUserId, $toUserId, $threadKey, $text]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('reception_supervisor_welcome_message')) {
    function reception_supervisor_welcome_message(string $specialistFirstName, string $supervisorFullName, string $meetingUrl, string $supervisorMobile): string
    {
        $lines   = [];
        $lines[] = 'سلام ' . $specialistFirstName;
        $lines[] = 'به تیم ' . $supervisorFullName . ' خوش آمدید.';
        $lines[] = 'جلسات آنلاین شما از طریق لینک زیر برگزار می‌شود:';
        $lines[] = $meetingUrl;
        $lines[] = '';
        $lines[] = 'سرپرست شما: ' . $supervisorFullName;
        $lines[] = 'شماره تماس سرپرست: ' . $supervisorMobile;
        $lines[] = '';
        $lines[] = 'ساعت جلسه نیز در همین گفتگو به شما اعلام خواهد شد.';
        return implode("\n", $lines);
    }
}

if (!function_exists('reception_specialist_join_message')) {
    function reception_specialist_join_message(string $specialistFullName, string $specialistMobile): string
    {
        $lines   = [];
        $lines[] = 'سلام';
        $lines[] = 'کارشناسِ جدید ' . $specialistFullName . ' (شماره: ' . $specialistMobile . ') به تیمِ شما اضافه شد.';
        return implode("\n", $lines);
    }
}

if (!function_exists('reception_meeting_slots_ready')) {
    function reception_meeting_slots_ready(PDO $pdo): bool
    {
        return reception_table_exists($pdo, 'reception_meeting_slots')
            && reception_table_exists($pdo, 'reception_meeting_bookings');
    }
}

if (!function_exists('reception_agent_meeting_message')) {
    function reception_agent_meeting_message(string $specialistFirstName, string $supervisorFullName, string $slotDateJalali, string $slotTime, string $meetingUrl): string
    {
        $lines   = [];
        $lines[] = 'سلام ' . $specialistFirstName;
        $lines[] = 'جلسه‌ی شما با سرپرست ' . $supervisorFullName . ' رزرو شد:';
        $lines[] = 'تاریخ: ' . $slotDateJalali;
        $lines[] = 'ساعت: ' . $slotTime;
        if ($meetingUrl !== '') {
            $lines[] = 'لینکِ جلسه:';
            $lines[] = $meetingUrl;
        }
        $lines[] = 'لطفاً سرِ وقت در جلسه حضور داشته باشید.';
        return implode("\n", $lines);
    }
}

if (!function_exists('reception_load_supervisor_slots')) {
    function reception_load_supervisor_slots(PDO $pdo, int $supervisorUserId, bool $activeOnly = false, bool $onlyFuture = false): array
    {
        if (!reception_meeting_slots_ready($pdo)) {
            return [];
        }
        try {
            $sql = "SELECT s.*,
                        (SELECT COUNT(*) FROM reception_meeting_bookings b WHERE b.slot_id = s.id AND b.status = 'booked') AS booked_count
                    FROM reception_meeting_slots s
                    WHERE s.supervisor_user_id = ?";
            if ($activeOnly) {
                $sql .= " AND s.is_active = 1";
            }
            if ($onlyFuture) {
                $sql .= " AND s.slot_date >= CURDATE()";
            }
            $sql .= " ORDER BY s.slot_date ASC, s.start_time ASC, s.id ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supervisorUserId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$row) {
                $row['booked_count'] = (int) $row['booked_count'];
                $row['capacity']     = (int) $row['capacity'];
                $row['remaining']    = max(0, $row['capacity'] - $row['booked_count']);
            }
            unset($row);
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_slot_bookings')) {
    function reception_slot_bookings(PDO $pdo, int $slotId): array
    {
        if (!reception_meeting_slots_ready($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT bk.*, ra.first_name, ra.last_name, ra.mobile, ag.full_name AS agent_name
                FROM reception_meeting_bookings bk
                LEFT JOIN reception_applicants ra ON ra.id = bk.applicant_id
                LEFT JOIN users ag ON ag.id = bk.agent_user_id
                WHERE bk.slot_id = ? AND bk.status = 'booked'
                ORDER BY bk.created_at ASC");
            $stmt->execute([$slotId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_book_meeting_slot')) {
    function reception_book_meeting_slot(PDO $pdo, int $slotId, int $applicantId, int $agentUserId): array
    {
        if (!reception_meeting_slots_ready($pdo)) {
            return ['ok' => false, 'message' => 'سامانه‌ی رزروِ جلسه هنوز آماده نیست.', 'slot' => null];
        }
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM reception_meeting_slots WHERE id = ? AND is_active = 1 FOR UPDATE");
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$slot) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'این تایم یافت نشد یا غیرفعال شده است.', 'slot' => null];
            }

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM reception_meeting_bookings WHERE slot_id = ? AND status = 'booked'");
            $cnt->execute([$slotId]);
            $bookedCount = (int) $cnt->fetchColumn();

            if ($bookedCount >= (int) $slot['capacity']) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'ظرفیتِ این تایم تکمیل شده است.', 'slot' => $slot];
            }

            $dup = $pdo->prepare("SELECT COUNT(*) FROM reception_meeting_bookings WHERE slot_id = ? AND applicant_id = ? AND status = 'booked'");
            $dup->execute([$slotId, $applicantId]);
            if ((int) $dup->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'این متقاضی از قبل برایِ همین تایم رزرو شده است.', 'slot' => $slot];
            }

            $ins = $pdo->prepare("INSERT INTO reception_meeting_bookings (slot_id, applicant_id, agent_user_id, status, created_at) VALUES (?, ?, ?, 'booked', NOW())");
            $ins->execute([$slotId, $applicantId, $agentUserId]);
            $bookingId = (int) $pdo->lastInsertId();

            reception_log_action($pdo, $applicantId, $agentUserId, 'book_meeting_slot', 'رزروِ تایمِ #' . $slotId . ' برایِ متقاضیِ #' . $applicantId);

            $pdo->commit();
            // مسیرِ پیگیری: متقاضی «دعوت‌شده» می‌شود و یادآوری/ثبتِ حضورش خودکار زمان‌بندی می‌شود
            if (function_exists('rp_on_invite') && $bookingId > 0) {
                rp_on_invite($pdo, $applicantId, 'online', $bookingId, rp_meeting_datetime((string) $slot['slot_date'], (string) $slot['start_time']),
                    (int) $slot['supervisor_user_id'], $agentUserId);
            }
            return ['ok' => true, 'message' => 'تایمِ جلسه با موفقیت رزرو شد.', 'slot' => $slot];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'message' => 'خطا در رزروِ تایمِ جلسه. دوباره تلاش کنید.', 'slot' => null];
        }
    }
}

if (!function_exists('reception_cancel_meeting_booking')) {
    function reception_cancel_meeting_booking(PDO $pdo, int $bookingId, int $applicantId): array
    {
        if (!reception_meeting_slots_ready($pdo)) {
            return ['ok' => false, 'message' => 'سامانه‌ی رزروِ جلسه هنوز آماده نیست.'];
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM reception_meeting_bookings WHERE id = ? AND applicant_id = ? AND status = 'booked' LIMIT 1");
            $stmt->execute([$bookingId, $applicantId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$booking) {
                return ['ok' => false, 'message' => 'این رزرو یافت نشد یا قبلاً لغو شده است.'];
            }
            $pdo->prepare("UPDATE reception_meeting_bookings SET status = 'cancelled' WHERE id = ?")->execute([$bookingId]);
            if (function_exists('rp_on_meeting_cancelled')) {
                rp_on_meeting_cancelled($pdo, $applicantId, 'online', $bookingId, rp_current_actor());
            }
            return ['ok' => true, 'message' => 'رزرو لغو شد و ظرفیتِ آن تایم دوباره خالی شد.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'خطا در لغوِ رزرو.'];
        }
    }
}

if (!function_exists('reception_supervisor_booking_notice_message')) {
    function reception_supervisor_booking_notice_message(string $specialistFullName, string $slotDateJalali, string $slotTime, string $agentFullName): string
    {
        $lines   = [];
        $lines[] = 'سلام';
        $lines[] = 'یک جلسه‌ی جدید برایِ شما رزرو شد:';
        $lines[] = 'کارشناس: ' . $specialistFullName;
        $lines[] = 'تاریخ: ' . $slotDateJalali . ' — ساعت: ' . $slotTime;
        $lines[] = 'رزروکننده: ' . $agentFullName;
        $lines[] = '';
        $lines[] = 'برایِ دیدنِ فهرستِ کاملِ رزروها به بخشِ «جلسات» مراجعه کنید.';
        return implode("\n", $lines);
    }
}

if (!function_exists('reception_next_unassigned_applicant_id')) {
    function reception_next_unassigned_applicant_id(PDO $pdo): ?int
    {
        try {
            $stmt = $pdo->query("SELECT id FROM reception_applicants WHERE assigned_agent_id IS NULL ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE");
            $id = $stmt->fetchColumn();
            return $id !== false ? (int) $id : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('reception_assign_next_applicant')) {
    function reception_assign_next_applicant(PDO $pdo, int $agentUserId): array
    {
        try {
            $pdo->beginTransaction();
            $id = reception_next_unassigned_applicant_id($pdo);
            if ($id === null) {
                $pdo->commit();
                return ['ok' => false, 'applicant_id' => null, 'message' => 'در حالِ حاضر متقاضیِ بدونِ کارشناسی در صف وجود ندارد.'];
            }
            $upd = $pdo->prepare("UPDATE reception_applicants SET assigned_agent_id = ?, assigned_at = NOW(), status = IF(status = 'new', 'calling', status), last_activity_at = NOW() WHERE id = ? AND assigned_agent_id IS NULL");
            $upd->execute([$agentUserId, $id]);
            if ($upd->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'applicant_id' => null, 'message' => 'این متقاضی هم‌زمان توسطِ کارشناسِ دیگری برداشته شد. دوباره تلاش کنید.'];
            }
            reception_record_status_change($pdo, $id, 'new', 'calling', $agentUserId);
            reception_log_action($pdo, $id, $agentUserId, 'assign_agent', 'واگذاریِ متقاضی به کارشناسِ #' . $agentUserId);
            $pdo->commit();
            // ورود به «مسیرِ پیگیری» در مرحله‌ی تماسِ اولیه
            if (function_exists('rp_ensure')) {
                rp_ensure($pdo, $id, $agentUserId, true);
            }
            return ['ok' => true, 'applicant_id' => $id, 'message' => 'متقاضی با موفقیت به شما اختصاص یافت.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'applicant_id' => null, 'message' => 'خطا در واگذاریِ متقاضی. دوباره تلاش کنید.'];
        }
    }
}

if (!function_exists('reception_touch_activity')) {
    function reception_touch_activity(PDO $pdo, int $applicantId): void
    {
        try {
            $stmt = $pdo->prepare("UPDATE reception_applicants SET last_activity_at = NOW() WHERE id = ?");
            $stmt->execute([$applicantId]);
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('reception_get_applicant')) {
    function reception_get_applicant(PDO $pdo, int $applicantId): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT ra.*, au.full_name AS agent_name, su.full_name AS supervisor_name, su.mobile AS supervisor_mobile
                FROM reception_applicants ra
                LEFT JOIN users au ON au.id = ra.assigned_agent_id
                LEFT JOIN users su ON su.id = ra.supervisor_user_id
                WHERE ra.id = ?");
            $stmt->execute([$applicantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('reception_applicant_phones')) {
    function reception_applicant_phones(PDO $pdo, int $applicantId): array
    {
        if (!reception_table_exists($pdo, 'reception_applicant_phones')) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT * FROM reception_applicant_phones WHERE applicant_id = ? ORDER BY is_primary DESC, id ASC");
            $stmt->execute([$applicantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_applicant_notes')) {
    function reception_applicant_notes(PDO $pdo, int $applicantId): array
    {
        if (!reception_table_exists($pdo, 'reception_followups')) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT f.id, f.applicant_id, f.agent_user_id, f.activity_type, f.notes, f.created_at,
                                          u.full_name AS agent_name
                                   FROM reception_followups f
                                   LEFT JOIN users u ON u.id = f.agent_user_id
                                   WHERE f.applicant_id = ?
                                     AND TRIM(COALESCE(f.notes, '')) <> ''
                                   ORDER BY f.created_at DESC, f.id DESC");
            $stmt->execute([$applicantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_delete_applicant_note')) {
    function reception_delete_applicant_note(PDO $pdo, int $noteId, int $applicantId, int $userId): bool
    {
        if (!reception_table_exists($pdo, 'reception_followups')) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM reception_followups
                                   WHERE id = ?
                                     AND applicant_id = ?
                                     AND agent_user_id = ?
                                   LIMIT 1");
            $stmt->execute([$noteId, $applicantId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('reception_applicant_timeline')) {
    function reception_applicant_timeline(PDO $pdo, int $applicantId): array
    {
        $items = [];

        if (reception_table_exists($pdo, 'reception_calls')) {
            try {
                $stmt = $pdo->prepare("SELECT c.*, u.full_name AS agent_name FROM reception_calls c LEFT JOIN users u ON u.id = c.agent_user_id WHERE c.applicant_id = ? ORDER BY c.started_at ASC");
                $stmt->execute([$applicantId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $items[] = [
                        'type'     => 'call',
                        'at'       => $row['started_at'],
                        'actor_id' => $row['agent_user_id'],
                        'summary'  => 'تماسِ تلفنی توسطِ ' . ($row['agent_name'] ?? '—') . ($row['result'] ? ' — نتیجه: ' . $row['result'] : ''),
                        'notes'    => (string) ($row['notes'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (reception_table_exists($pdo, 'reception_followups')) {
            try {
                $stmt = $pdo->prepare("SELECT f.*, u.full_name AS agent_name FROM reception_followups f LEFT JOIN users u ON u.id = f.agent_user_id WHERE f.applicant_id = ? ORDER BY f.created_at ASC");
                $stmt->execute([$applicantId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $items[] = [
                        'type'     => 'followup',
                        'at'       => $row['created_at'],
                        'actor_id' => $row['agent_user_id'],
                        'summary'  => 'پیگیری (' . $row['activity_type'] . ') توسطِ ' . ($row['agent_name'] ?? '—'),
                        'notes'    => (string) ($row['notes'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        if (reception_table_exists($pdo, 'reception_status_history')) {
            try {
                $stmt = $pdo->prepare("SELECT h.*, u.full_name AS actor_name FROM reception_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.applicant_id = ? ORDER BY h.created_at ASC");
                $stmt->execute([$applicantId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $items[] = [
                        'type'     => 'status',
                        'at'       => $row['created_at'],
                        'actor_id' => $row['changed_by'],
                        'summary'  => 'تغییرِ وضعیت به «' . reception_status_label($pdo, $row['new_status']) . '» توسطِ ' . ($row['actor_name'] ?? '—'),
                        'notes'    => '',
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        // جلسات مصاحبه‌ی حضوری
        if (reception_table_exists($pdo, 'reception_inperson_interviews')) {
            try {
                $stmt = $pdo->prepare("SELECT ii.*, u.full_name AS agent_name
                    FROM reception_inperson_interviews ii
                    LEFT JOIN users u ON u.id = ii.agent_user_id
                    WHERE ii.applicant_id = ?
                    ORDER BY ii.created_at ASC");
                $stmt->execute([$applicantId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $timePart = !empty($row['interview_time']) ? ' ساعت ' . substr((string) $row['interview_time'], 0, 5) : '';
                    $items[] = [
                        'type'     => 'inperson',
                        'at'       => $row['created_at'],
                        'actor_id' => $row['agent_user_id'],
                        'summary'  => 'ثبت جلسه مصاحبه حضوری برای ' . to_jalali($row['interview_date']) . $timePart
                                    . ' توسطِ ' . ($row['agent_name'] ?? '—'),
                        'notes'    => (string) ($row['notes'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
            }
        }

        // مراحلِ «مسیرِ پیگیری» (دعوت، یادآوری، حضور/غیاب، تعیین تکلیف، …)
        if (function_exists('rp_events_of')) {
            foreach (rp_events_of($pdo, $applicantId) as $ev) {
                if ($ev['event'] === 'call') {
                    continue; // خودِ تماس در بالا آمده است
                }
                $items[] = [
                    'type'     => 'pipeline',
                    'event'    => (string) $ev['event'],
                    'at'       => $ev['created_at'],
                    'actor_id' => $ev['actor_user_id'],
                    'summary'  => rp_event_summary($ev) . ' — ' . ($ev['actor_name'] ?? 'سامانه'),
                    'notes'    => (string) ($ev['note'] ?? ''),
                ];
            }
        }

        usort($items, static function ($a, $b) {
            return strcmp((string) $a['at'], (string) $b['at']);
        });

        return $items;
    }
}

/* =====================================================================
   توابع ماژول «جلسه مصاحبه حضوری»
   ---------------------------------------------------------------------
   دو نوع جلسه برای متقاضیان داریم:
     • میتینگ آنلاین  → رزروِ تایمِ سرپرست (reception_meeting_bookings)
     • مصاحبه حضوری   → reception_inperson_interviews (همین بخش)
   هر دو در آمارِ پذیرش جداگانه شمرده می‌شوند.
   ===================================================================== */

if (!function_exists('reception_inperson_statuses')) {
    function reception_inperson_statuses(): array
    {
        return [
            'scheduled' => ['label' => 'در انتظار حضور', 'color' => 'warning', 'icon' => 'fa-hourglass-half'],
            'done'      => ['label' => 'حضور یافت / برگزار شد', 'color' => 'success', 'icon' => 'fa-circle-check'],
            'no_show'   => ['label' => 'عدم حضور', 'color' => 'danger', 'icon' => 'fa-user-xmark'],
            'cancelled' => ['label' => 'لغو شد', 'color' => 'secondary', 'icon' => 'fa-ban'],
        ];
    }
}

if (!function_exists('reception_inperson_status_label')) {
    function reception_inperson_status_label(string $code): string
    {
        $all = reception_inperson_statuses();
        return $all[$code]['label'] ?? $code;
    }
}

if (!function_exists('reception_inperson_table_ready')) {
    /**
     * جدولِ مصاحبه‌های حضوری را (اگر هنوز ساخته نشده) خودکار می‌سازد تا ثبتِ حضوری
     * بدونِ نیاز به اجرای دستیِ مایگریشن کار کند.
     */
    function reception_inperson_table_ready(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $flag = __DIR__ . '/../storage/.reception_inperson_v2';
        if (is_file($flag)) {
            $ready = true;
            return $ready;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `reception_inperson_interviews` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `applicant_id` INT UNSIGNED NOT NULL,
                `agent_user_id` INT UNSIGNED NOT NULL,
                `supervisor_user_id` INT UNSIGNED DEFAULT NULL,
                `interview_date` DATE NOT NULL,
                `interview_time` TIME DEFAULT NULL,
                `location` VARCHAR(190) DEFAULT NULL,
                `notes` TEXT,
                `status` VARCHAR(20) NOT NULL DEFAULT 'scheduled',
                `result_note` VARCHAR(500) DEFAULT NULL,
                `status_changed_by` INT UNSIGNED DEFAULT NULL,
                `status_changed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rii_applicant` (`applicant_id`),
                KEY `idx_rii_agent` (`agent_user_id`),
                KEY `idx_rii_date` (`interview_date`),
                KEY `idx_rii_status` (`status`),
                KEY `idx_rii_supervisor` (`supervisor_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // نسخه‌های قدیمیِ جدول (اگر دستی ساخته شده باشد) ستون‌های جدید را ندارند
            foreach ([
                'supervisor_user_id' => 'ALTER TABLE reception_inperson_interviews ADD COLUMN supervisor_user_id INT UNSIGNED DEFAULT NULL',
                'result_note'        => 'ALTER TABLE reception_inperson_interviews ADD COLUMN result_note VARCHAR(500) DEFAULT NULL',
                'status_changed_by'  => 'ALTER TABLE reception_inperson_interviews ADD COLUMN status_changed_by INT UNSIGNED DEFAULT NULL',
                'status_changed_at'  => 'ALTER TABLE reception_inperson_interviews ADD COLUMN status_changed_at DATETIME DEFAULT NULL',
            ] as $col => $sql) {
                if (!reception_column_exists($pdo, 'reception_inperson_interviews', $col)) {
                    try { $pdo->exec($sql); } catch (Throwable $e) {}
                }
            }
            // وضعیتِ متقاضی «دعوت به مصاحبه حضوری» (برای فیلتر در بانک متقاضیان)
            if (reception_table_exists($pdo, 'reception_statuses')) {
                try {
                    $pdo->exec("INSERT IGNORE INTO reception_statuses (code, label, color, sort_order, is_system) VALUES ('inperson_invited', 'دعوت به مصاحبه حضوری', 'info', 72, 1)");
                } catch (Throwable $e) {
                }
            }
            $ready = true;
            if (!is_dir(dirname($flag))) {
                @mkdir(dirname($flag), 0755, true);
            }
            @file_put_contents($flag, (string) time());
        } catch (Throwable $e) {
            error_log('reception_inperson_table_ready: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('reception_log_inperson_interview')) {
    /**
     * ثبت یک جلسه مصاحبه حضوری برای متقاضی.
     *
     * @return array{ok:bool, id:?int, message:string}
     */
    function reception_log_inperson_interview(PDO $pdo, int $applicantId, int $agentUserId, array $data): array
    {
        if (!reception_inperson_table_ready($pdo)) {
            return ['ok' => false, 'id' => null, 'message' => 'جدولِ مصاحبه‌های حضوری هنوز آماده نیست.'];
        }
        $date  = trim((string) ($data['interview_date'] ?? ''));
        $time  = trim((string) ($data['interview_time'] ?? ''));
        $place = trim((string) ($data['location'] ?? ''));
        $note  = trim((string) ($data['notes'] ?? ''));
        $stat  = trim((string) ($data['status'] ?? 'scheduled'));
        $supervisorId = (int) ($data['supervisor_user_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'id' => null, 'message' => 'تاریخِ مصاحبه معتبر نیست.'];
        }
        if (!isset(reception_inperson_statuses()[$stat])) {
            $stat = 'scheduled';
        }
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            $time = '';
        }

        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM reception_inperson_interviews WHERE applicant_id = ? AND interview_date = ? AND status = 'scheduled'");
            $dup->execute([$applicantId, $date]);
            if ((int) $dup->fetchColumn() > 0) {
                return ['ok' => false, 'id' => null, 'message' => 'برای همین متقاضی در همین تاریخ، یک مصاحبه‌ی حضوریِ فعال از قبل ثبت شده است.'];
            }

            $stmt = $pdo->prepare("INSERT INTO reception_inperson_interviews
                (applicant_id, agent_user_id, supervisor_user_id, interview_date, interview_time, location, notes, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([
                $applicantId,
                $agentUserId,
                $supervisorId > 0 ? $supervisorId : null,
                $date,
                $time !== '' ? $time : null,
                $place !== '' ? $place : null,
                $note !== '' ? $note : null,
                $stat,
            ]);
            $newId = (int) $pdo->lastInsertId();

            // وضعیتِ متقاضی → «دعوت به مصاحبه حضوری» (فقط اگر هنوز به مرحله‌ی بالاتری نرسیده)
            try {
                $cur = $pdo->prepare('SELECT status FROM reception_applicants WHERE id = ?');
                $cur->execute([$applicantId]);
                $oldStatus = (string) $cur->fetchColumn();
                if (!in_array($oldStatus, ['accepted', 'rejected', 'inperson_invited'], true)) {
                    $pdo->prepare("UPDATE reception_applicants SET status = 'inperson_invited', last_activity_at = NOW() WHERE id = ?")->execute([$applicantId]);
                    reception_record_status_change($pdo, $applicantId, $oldStatus, 'inperson_invited', $agentUserId);
                }
            } catch (Throwable $e) {
            }

            reception_log_action(
                $pdo,
                $applicantId,
                $agentUserId,
                'inperson_interview_logged',
                'ثبت جلسه مصاحبه حضوری #' . $newId . ' برای تاریخ ' . $date
            );

            if (function_exists('rp_on_invite') && $stat === 'scheduled') {
                rp_on_invite($pdo, $applicantId, 'inperson', $newId, rp_meeting_datetime($date, $time), $supervisorId > 0 ? $supervisorId : null, $agentUserId);
            }

            return ['ok' => true, 'id' => $newId, 'message' => 'جلسه مصاحبه حضوری ثبت شد و در آمارِ «حضوری» شمرده می‌شود.'];
        } catch (Throwable $e) {
            error_log('reception_log_inperson_interview: ' . $e->getMessage());
            return ['ok' => false, 'id' => null, 'message' => 'خطا در ثبت جلسه مصاحبه.'];
        }
    }
}

if (!function_exists('reception_update_inperson_status')) {
    /** تغییرِ نتیجه‌ی مصاحبه‌ی حضوری (برگزار شد / عدم حضور / لغو / در انتظار) */
    function reception_update_inperson_status(PDO $pdo, int $interviewId, string $status, int $byUserId, string $note = '', ?int $restrictAgentId = null): bool
    {
        if (!reception_inperson_table_ready($pdo) || !isset(reception_inperson_statuses()[$status])) {
            return false;
        }
        try {
            $sql = 'UPDATE reception_inperson_interviews SET status = ?, result_note = ?, status_changed_by = ?, status_changed_at = NOW() WHERE id = ?';
            $params = [$status, $note !== '' ? mb_substr($note, 0, 500) : null, $byUserId, $interviewId];
            if ($restrictAgentId !== null) {
                $sql .= ' AND agent_user_id = ?';
                $params[] = $restrictAgentId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $a = $pdo->prepare('SELECT applicant_id FROM reception_inperson_interviews WHERE id = ?');
                $a->execute([$interviewId]);
                $appId = (int) $a->fetchColumn();
                reception_log_action($pdo, $appId ?: null, $byUserId, 'inperson_status', 'مصاحبه #' . $interviewId . ' → ' . reception_inperson_status_label($status));
                if ($appId && function_exists('rp_on_meeting_result')) {
                    $map = ['done' => 'attended', 'no_show' => 'no_show', 'cancelled' => 'cancelled', 'scheduled' => 'scheduled'];
                    rp_on_meeting_result($pdo, $appId, 'inperson', $interviewId, $map[$status], $byUserId, $status === 'no_show' ? 'unknown' : null);
                }
                return true;
            }
        } catch (Throwable $e) {
        }
        return false;
    }
}

if (!function_exists('reception_applicant_inperson_interviews')) {
    function reception_applicant_inperson_interviews(PDO $pdo, int $applicantId): array
    {
        if (!reception_inperson_table_ready($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("SELECT ii.*, u.full_name AS agent_name, su.full_name AS supervisor_name
                FROM reception_inperson_interviews ii
                LEFT JOIN users u ON u.id = ii.agent_user_id
                LEFT JOIN users su ON su.id = ii.supervisor_user_id
                WHERE ii.applicant_id = ?
                ORDER BY ii.interview_date DESC, ii.interview_time DESC, ii.id DESC");
            $stmt->execute([$applicantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_delete_inperson_interview')) {
    function reception_delete_inperson_interview(PDO $pdo, int $interviewId, int $applicantId, int $agentUserId): bool
    {
        if (!reception_inperson_table_ready($pdo)) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM reception_inperson_interviews
                WHERE id = ? AND applicant_id = ? AND agent_user_id = ? LIMIT 1");
            $stmt->execute([$interviewId, $applicantId, $agentUserId]);
            $ok = $stmt->rowCount() > 0;
            if ($ok && function_exists('rp_on_meeting_cancelled')) {
                rp_on_meeting_cancelled($pdo, $applicantId, 'inperson', $interviewId, $agentUserId);
            }
            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('reception_inperson_filters_sql')) {
    /** شرط‌های مشترکِ فیلترِ مصاحبه‌های حضوری */
    function reception_inperson_filters_sql(array $f, array &$params): string
    {
        $where = ['1=1'];
        if (!empty($f['from'])) { $where[] = 'ii.interview_date >= ?'; $params[] = $f['from']; }
        if (!empty($f['to']))   { $where[] = 'ii.interview_date <= ?'; $params[] = $f['to']; }
        if (!empty($f['agent_id'])) { $where[] = 'ii.agent_user_id = ?'; $params[] = (int) $f['agent_id']; }
        if (!empty($f['supervisor_id'])) { $where[] = 'COALESCE(ii.supervisor_user_id, ra.supervisor_user_id) = ?'; $params[] = (int) $f['supervisor_id']; }
        if (!empty($f['status'])) {
            $where[] = 'ii.status = ?';
            $params[] = (string) $f['status'];
        } elseif (empty($f['include_cancelled'])) {
            $where[] = "ii.status <> 'cancelled'";
        }
        if (!empty($f['q'])) {
            $q = '%' . $f['q'] . '%';
            $where[] = "(CONCAT(ra.first_name, ' ', ra.last_name) LIKE ? OR ra.mobile LIKE ? OR ra.mobile_normalized LIKE ?)";
            array_push($params, $q, $q, $q);
        }
        return implode(' AND ', $where);
    }
}

if (!function_exists('reception_count_inperson_interviews')) {
    function reception_count_inperson_interviews(
        PDO $pdo,
        string $fromDate,
        string $toDate,
        int $agentFilter = 0,
        int $supervisorFilter = 0,
        string $status = ''
    ): int {
        if (!reception_inperson_table_ready($pdo)) {
            return 0;
        }
        try {
            $params = [];
            $where = reception_inperson_filters_sql(['from' => $fromDate, 'to' => $toDate, 'agent_id' => $agentFilter, 'supervisor_id' => $supervisorFilter, 'status' => $status], $params);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM reception_inperson_interviews ii JOIN reception_applicants ra ON ra.id = ii.applicant_id WHERE $where");
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('reception_list_inperson_interviews')) {
    function reception_list_inperson_interviews(
        PDO $pdo,
        string $fromDate,
        string $toDate,
        int $agentFilter = 0,
        int $supervisorFilter = 0,
        int $limit = 500
    ): array {
        if (!reception_inperson_table_ready($pdo)) {
            return [];
        }
        $limit = max(1, min(2000, $limit));
        try {
            $params = [];
            $where = reception_inperson_filters_sql(['from' => $fromDate, 'to' => $toDate, 'agent_id' => $agentFilter, 'supervisor_id' => $supervisorFilter], $params);
            $stmt = $pdo->prepare("SELECT ii.*, ra.first_name, ra.last_name, ra.mobile,
                           ag.full_name AS agent_name, COALESCE(sv2.full_name, su.full_name) AS supervisor_name
                    FROM reception_inperson_interviews ii
                    JOIN reception_applicants ra ON ra.id = ii.applicant_id
                    LEFT JOIN users ag ON ag.id = ii.agent_user_id
                    LEFT JOIN users su ON su.id = ra.supervisor_user_id
                    LEFT JOIN users sv2 ON sv2.id = ii.supervisor_user_id
                    WHERE $where
                    ORDER BY ii.interview_date DESC, ii.interview_time DESC, ii.id DESC LIMIT $limit");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_daily_inperson_interviews')) {
    function reception_daily_inperson_interviews(
        PDO $pdo,
        string $fromDate,
        string $toDate,
        int $agentFilter = 0
    ): array {
        if (!reception_inperson_table_ready($pdo)) {
            return [];
        }
        try {
            $sql = "SELECT ii.interview_date d, COUNT(*) c
                    FROM reception_inperson_interviews ii
                    WHERE ii.interview_date BETWEEN ? AND ?
                      AND ii.status <> 'cancelled'";
            $params = [$fromDate, $toDate];
            if ($agentFilter > 0) {
                $sql .= ' AND ii.agent_user_id = ?';
                $params[] = $agentFilter;
            }
            $sql .= ' GROUP BY ii.interview_date ORDER BY d ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('reception_meeting_type_summary')) {
    /**
     * خلاصه‌ی «آنلاین در برابر حضوری» برای یک بازه‌ی تاریخ (بر اساسِ تاریخِ ثبت).
     * خروجی: online, inperson, inperson_done, inperson_no_show, inperson_scheduled, inperson_cancelled
     */
    function reception_meeting_type_summary(PDO $pdo, string $fromDateTime, string $toDateTime, int $agentFilter = 0, int $supervisorFilter = 0): array
    {
        $out = ['online' => 0, 'inperson' => 0, 'inperson_done' => 0, 'inperson_no_show' => 0, 'inperson_scheduled' => 0, 'inperson_cancelled' => 0];
        if (reception_meeting_slots_ready($pdo)) {
            try {
                $sql = "SELECT COUNT(*) FROM reception_meeting_bookings bk JOIN reception_meeting_slots s ON s.id = bk.slot_id
                        WHERE bk.status = 'booked' AND bk.created_at BETWEEN ? AND ?";
                $p = [$fromDateTime, $toDateTime];
                if ($agentFilter > 0) { $sql .= ' AND bk.agent_user_id = ?'; $p[] = $agentFilter; }
                if ($supervisorFilter > 0) { $sql .= ' AND s.supervisor_user_id = ?'; $p[] = $supervisorFilter; }
                $st = $pdo->prepare($sql);
                $st->execute($p);
                $out['online'] = (int) $st->fetchColumn();
            } catch (Throwable $e) {
            }
        }
        if (reception_inperson_table_ready($pdo)) {
            try {
                $sql = "SELECT ii.status, COUNT(*) c FROM reception_inperson_interviews ii JOIN reception_applicants ra ON ra.id = ii.applicant_id
                        WHERE ii.created_at BETWEEN ? AND ?";
                $p = [$fromDateTime, $toDateTime];
                if ($agentFilter > 0) { $sql .= ' AND ii.agent_user_id = ?'; $p[] = $agentFilter; }
                if ($supervisorFilter > 0) { $sql .= ' AND COALESCE(ii.supervisor_user_id, ra.supervisor_user_id) = ?'; $p[] = $supervisorFilter; }
                $sql .= ' GROUP BY ii.status';
                $st = $pdo->prepare($sql);
                $st->execute($p);
                foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [] as $code => $c) {
                    if (isset($out['inperson_' . $code])) {
                        $out['inperson_' . $code] = (int) $c;
                    }
                    if ($code !== 'cancelled') {
                        $out['inperson'] += (int) $c;
                    }
                }
            } catch (Throwable $e) {
            }
        }
        return $out;
    }
}

if (!function_exists('rp_current_actor')) {
    function rp_current_actor(): ?int
    {
        if (function_exists('current_user')) {
            $u = current_user();
            return $u ? (int) $u['id'] : null;
        }
        return null;
    }
}

// ماژولِ «مسیرِ پیگیریِ متقاضیان» (قیفِ پذیرش)
require_once __DIR__ . '/reception_pipeline.php';
