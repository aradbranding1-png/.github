<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Notify;
use App\Core\RateLimiter;
use App\Core\Request;

/**
 * Integration with «آراد کانتکت» (sales system).
 *
 *  GET  /api/integrations/arad-contact/services   → chargeable services catalog
 *  POST /api/integrations/arad-contact/provision  → find/create the trader by mobile and charge the purchased services
 *
 * Auth: "Authorization: Bearer <token>". The token is set in «تنظیمات ← اتصال آراد کانتکت» (only its SHA-256 is stored)
 * or, alternatively, as ARAD_CONTACT_TOKEN in .env.
 *
 * Idempotency: external_id is unique (arad_contact_orders). A repeated external_id charges nothing and returns the
 * stored response with duplicate:true. The generated password of a new account is kept encrypted (APP_KEY) only until
 * the user changes it, so a retry after a network failure still delivers it.
 */
final class AradContact
{
    /**
     * Chargeable services. units: label => [kind, factor]
     *   minutes: quantity × factor minutes are added to the counter credit
     *   count:   quantity × factor items
     *   months:  the subscription is extended by quantity × factor months
     */
    public const SERVICES = [
        'platform_account' => ['title' => 'اکانت سامانه آموزش آراد برندینگ', 'credit' => 'account', 'units' => ['سالانه' => ['months', 12]]],
        'webinar' => ['title' => 'وبینار تجاری', 'credit' => 'webinar', 'units' => ['ساعت' => ['minutes', 60]]],
        'workshop' => ['title' => 'کارگاه تجاری آنلاین', 'credit' => 'workshop', 'units' => ['عدد' => ['count', 1]]],
        'skill_files' => ['title' => 'فایل‌های تجاری مهارت‌محور', 'credit' => 'course', 'units' => ['ساعت' => ['minutes', 60]]],
        'online_meeting' => ['title' => 'میتینگ آنلاین', 'credit' => 'meeting', 'units' => ['سالانه' => ['months', 12]]],
    ];

    /** subscription credit type => [from column, until column] */
    private const SUBSCRIPTIONS = ['account' => ['account_from', 'account_until'], 'meeting' => ['meeting_from', 'meeting_until']];

    public const DEFAULT_ROLE = 'تاجر فراگیر';
    public const DEFAULT_GROUP = 'تاجران';
    public const DEFAULT_LEVEL = 'تاجر آموز';

    public const MAX_ITEMS = 50;
    public const MAX_QUANTITY = 100000;

    // ------------------------------------------------------------------ catalog

    public static function servicesResponse(): array
    {
        $out = [];
        foreach (self::SERVICES as $code => $s) $out[] = ['code' => $code, 'title' => $s['title'], 'units' => array_keys($s['units'])];
        return ['success' => true, 'services' => $out];
    }

    // ------------------------------------------------------------------ authentication

    public static function envToken(): string
    {
        return trim((string)env('ARAD_CONTACT_TOKEN', ''));
    }

    public static function configured(): bool
    {
        return (string)setting('arad_contact_token_hash', '') !== '' || self::envToken() !== '';
    }

    public static function enabled(): bool
    {
        return setting('arad_contact_enabled', '1') === '1';
    }

    public static function allowedIps(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,،]+/u', (string)setting('arad_contact_allowed_ips', '')) ?: [])));
    }

    public static function bearer(): string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) { $h = (string)$v; break; }
        }
        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', (string)$h, $m) ? $m[1] : '';
    }

    public static function tokenValid(string $token): bool
    {
        if ($token === '') return false;
        $h = hash('sha256', $token);
        $stored = (string)setting('arad_contact_token_hash', '');
        if ($stored !== '' && hash_equals($stored, $h)) return true;
        $env = self::envToken();
        return $env !== '' && hash_equals(hash('sha256', $env), $h);
    }

    /** Throws HttpException (503 / 403 / 429 / 401) when the call may not proceed */
    public static function authenticate(): void
    {
        $ip = Request::ip();
        if (!self::enabled()) throw new HttpException(503, 'اتصال آراد کانتکت در سامانه آموزش غیرفعال است.');
        if (!self::configured()) throw new HttpException(503, 'توکن اتصال آراد کانتکت هنوز در تنظیمات سامانه آموزش تعریف نشده است.');
        $ips = self::allowedIps();
        if ($ips && !in_array($ip, $ips, true)) throw new HttpException(403, 'فراخوانی از این IP مجاز نیست (' . $ip . ').');
        if (RateLimiter::tooMany('arad-contact-fail:' . $ip, 20)) throw new HttpException(429, 'به دلیل تلاش‌های ناموفق مکرر، دسترسی موقتاً مسدود شده است.');
        if (!RateLimiter::hit('arad-contact-ip:' . $ip, 240, 60)) throw new HttpException(429, 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد تلاش کنید.');
        $token = self::bearer();
        if ($token === '') {
            RateLimiter::hit('arad-contact-fail:' . $ip, 20, 900);
            throw new HttpException(401, 'هدر Authorization با توکن Bearer ارسال نشده است.');
        }
        if (!self::tokenValid($token)) {
            RateLimiter::hit('arad-contact-fail:' . $ip, 20, 900);
            usleep(random_int(100000, 300000));
            throw new HttpException(401, 'توکن نامعتبر است.');
        }
    }

    // ------------------------------------------------------------------ validation

    /** Unit labels compared without ZWNJ / Arabic letters / extra spaces */
    private static function normUnit(string $u): string
    {
        $u = str_replace(["\u{200C}", "\u{200F}", "\u{200E}", 'ي', 'ك'], [' ', '', '', 'ی', 'ک'], $u);
        return trim((string)preg_replace('/\s+/u', ' ', $u));
    }

    private static function cleanName(mixed $v, string $field): string
    {
        if ($v === null) return '';
        if (!is_string($v)) throw new HttpException(422, "$field باید متن باشد.");
        $v = trim(strip_tags(normalize_input($v)));
        if (mb_strlen($v) > 100) throw new HttpException(422, "$field حداکثر ۱۰۰ کاراکتر است.");
        return $v;
    }

    /**
     * @return array{external_id:string, mobile:string, first_name:string, last_name:string,
     *               items: list<array{service_code:string, quantity:int, unit:string}>}
     */
    public static function validate(array $b): array
    {
        $ext = $b['external_id'] ?? null;
        if (!is_string($ext) && !is_int($ext)) throw new HttpException(422, 'external_id الزامی است.');
        $ext = trim((string)$ext);
        if ($ext === '') throw new HttpException(422, 'external_id الزامی است.');
        if (mb_strlen($ext) > 191 || preg_match('/[\x00-\x1F\x7F]/', $ext)) throw new HttpException(422, 'external_id نامعتبر است (حداکثر ۱۹۱ کاراکتر، بدون کاراکتر کنترلی).');

        $rawMobile = $b['mobile'] ?? null;
        if (!is_string($rawMobile) && !is_int($rawMobile)) throw new HttpException(422, 'mobile الزامی است.');
        $mobile = canonical_mobile((string)$rawMobile);
        if (!preg_match('/^09\d{9}$/', $mobile)) throw new HttpException(422, 'شماره موبایل نامعتبر است. نمونه معتبر: 09123456789');

        $first = self::cleanName($b['first_name'] ?? null, 'first_name');
        $last = self::cleanName($b['last_name'] ?? null, 'last_name');

        $items = $b['items'] ?? null;
        if (!is_array($items) || !$items || !array_is_list($items)) throw new HttpException(422, 'items باید آرایه‌ای غیرخالی از خدمات باشد.');
        if (count($items) > self::MAX_ITEMS) throw new HttpException(422, 'حداکثر ' . self::MAX_ITEMS . ' قلم در هر درخواست مجاز است.');

        $out = [];
        foreach ($items as $i => $it) {
            $p = "items[$i]";
            if (!is_array($it)) throw new HttpException(422, "$p نامعتبر است.");
            $code = is_string($it['service_code'] ?? null) ? strtolower(trim($it['service_code'])) : '';
            if ($code === '') throw new HttpException(422, "$p.service_code الزامی است.");
            $svc = self::SERVICES[$code] ?? null;
            if (!$svc) throw new HttpException(422, "$p.service_code «" . mb_substr($code, 0, 50) . '» ناشناخته است. کدهای مجاز: ' . implode(', ', array_keys(self::SERVICES)));

            $q = $it['quantity'] ?? null;
            if (is_string($q)) $q = normalize_input($q);
            if (is_int($q)) $qty = $q;
            elseif (is_float($q) && floor($q) === $q) $qty = (int)$q;
            elseif (is_string($q) && preg_match('/^\d+$/', $q)) $qty = (int)$q;
            else throw new HttpException(422, "$p.quantity باید عدد صحیح مثبت باشد.");
            if ($qty < 1 || $qty > self::MAX_QUANTITY) throw new HttpException(422, "$p.quantity باید بین ۱ و " . self::MAX_QUANTITY . ' باشد.');

            $units = array_keys($svc['units']);
            $unitIn = $it['unit'] ?? null;
            if ($unitIn === null || $unitIn === '') $unit = $units[0];
            else {
                if (!is_string($unitIn)) throw new HttpException(422, "$p.unit نامعتبر است.");
                $unit = null;
                foreach ($units as $u) if (self::normUnit($u) === self::normUnit($unitIn)) { $unit = $u; break; }
                if ($unit === null) throw new HttpException(422, "$p.unit «" . mb_substr($unitIn, 0, 30) . "» برای خدمت $code مجاز نیست. واحدهای مجاز: " . implode('، ', $units));
            }
            $out[] = ['service_code' => $code, 'quantity' => $qty, 'unit' => $unit];
        }
        return ['external_id' => $ext, 'mobile' => $mobile, 'first_name' => $first, 'last_name' => $last, 'items' => $out];
    }

    // ------------------------------------------------------------------ defaults for new accounts

    private static function norm(string $s): string
    {
        return ServiceSync::norm($s);
    }

    public static function roleId(): ?int
    {
        $cfg = (int)setting('arad_contact_role_id', '0');
        if ($cfg > 0 && DB::value('SELECT 1 FROM roles WHERE id = ? AND is_root = 0', [$cfg])) return $cfg;
        foreach (DB::all('SELECT id, name FROM roles WHERE is_root = 0 ORDER BY is_learner DESC, id') as $r) {
            if (self::norm((string)$r['name']) === self::norm(self::DEFAULT_ROLE)) return (int)$r['id'];
        }
        return UserService::learnerRoleId('merchant');
    }

    public static function groupId(): ?int
    {
        $cfg = (int)setting('arad_contact_group_id', '0');
        if ($cfg > 0 && DB::value('SELECT 1 FROM `groups` WHERE id = ?', [$cfg])) return $cfg;
        foreach (DB::all('SELECT id, name FROM `groups` ORDER BY (parent_id IS NULL) DESC, id') as $g) {
            if (self::norm((string)$g['name']) === self::norm(self::DEFAULT_GROUP)) return (int)$g['id'];
        }
        return UserService::segmentGroupId('merchant');
    }

    public static function levelId(?int $groupId): ?int
    {
        if (!$groupId) return null;
        $cfg = (int)setting('arad_contact_level_id', '0');
        if ($cfg > 0 && DB::value('SELECT 1 FROM levels WHERE id = ? AND group_id = ?', [$cfg, $groupId])) return $cfg;
        foreach (DB::all('SELECT id, name FROM levels WHERE group_id = ? ORDER BY rank_no, id', [$groupId]) as $l) {
            if (self::norm((string)$l['name']) === self::norm(self::DEFAULT_LEVEL)) return (int)$l['id'];
        }
        return null;
    }

    // ------------------------------------------------------------------ passwords

    /** 10 characters, upper + lower + digits, without look-alikes (0/O/o, 1/l/I/i) */
    public static function generatePassword(int $len = 10): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digit = '23456789';
        $all = $upper . $lower . $digit;
        $chars = [$upper[random_int(0, strlen($upper) - 1)], $lower[random_int(0, strlen($lower) - 1)], $digit[random_int(0, strlen($digit) - 1)]];
        while (count($chars) < $len) $chars[] = $all[random_int(0, strlen($all) - 1)];
        for ($i = count($chars) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]]; }
        return implode('', $chars);
    }

    private static function cryptKey(): ?string
    {
        $k = (string)env('APP_KEY', '');
        return $k === '' ? null : hash('sha256', 'arad-contact-password|' . $k, true);
    }

    public static function encrypt(string $plain): ?string
    {
        $key = self::cryptKey();
        if ($key === null) return null;
        $iv = random_bytes(12);
        $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $c === false ? null : base64_encode($iv . $tag . $c);
    }

    public static function decrypt(?string $enc): ?string
    {
        $key = self::cryptKey();
        if ($key === null || !$enc) return null;
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }

    /** The user chose a new password: the one generated for Arad Contact is no longer kept anywhere */
    public static function forgetPassword(int $userId): void
    {
        try { DB::run('UPDATE arad_contact_orders SET password_enc = NULL WHERE user_id = ? AND password_enc IS NOT NULL', [$userId]); } catch (\Throwable) {}
    }

    // ------------------------------------------------------------------ provisioning

    /** Existing (non-deleted) account with this mobile in any stored form; the canonical form and active accounts first */
    public static function findUser(string $mobile): ?array
    {
        $n = substr($mobile, 1);
        return DB::one("SELECT * FROM users WHERE deleted_at IS NULL AND mobile IN (?,?,?,?,?)
                        ORDER BY (mobile = ?) DESC, (status = 'active') DESC, id LIMIT 1", [$mobile, $n, '+98' . $n, '98' . $n, '0098' . $n, $mobile]);
    }

    private static function findOrder(string $externalId): ?array
    {
        return DB::one('SELECT * FROM arad_contact_orders WHERE external_id = ?', [$externalId]);
    }

    private static function isDuplicateKey(\Throwable $e): bool
    {
        return $e instanceof \PDOException && (($e->errorInfo[1] ?? null) === 1062 || str_contains($e->getMessage(), '1062'));
    }

    /** Stored response of an already processed external_id */
    private static function replay(array $order, string $requestHash): array
    {
        $resp = json_decode((string)$order['response_json'], true);
        if (!is_array($resp)) throw new HttpException(409, 'این external_id در حال پردازش است. چند ثانیه بعد دوباره تلاش کنید.');
        $resp['duplicate'] = true;
        $resp['password'] = null;
        if ($order['password_enc'] && $order['user_id']
            && (int)DB::value('SELECT must_change_password FROM users WHERE id = ? AND deleted_at IS NULL', [(int)$order['user_id']]) === 1) {
            $resp['password'] = self::decrypt((string)$order['password_enc']);
        }
        DB::run('UPDATE arad_contact_orders SET replay_count = replay_count + 1, last_replay_at = NOW() WHERE id = ?', [(int)$order['id']]);
        $mismatch = !hash_equals((string)$order['request_hash'], $requestHash);
        return [$resp, ['user_id' => $order['user_id'] ? (int)$order['user_id'] : null, 'duplicate' => true, 'payload_mismatch' => $mismatch]];
    }

    private static function requestHash(array $v): string
    {
        return hash('sha256', json_encode([$v['mobile'], $v['items']], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{0: array, 1: array} [response, meta for the log]
     */
    public static function provision(array $body): array
    {
        $v = self::validate($body);
        $hash = self::requestHash($v);
        if ($prev = self::findOrder($v['external_id'])) return self::replay($prev, $hash);

        // one purchase per mobile at a time (two orders of a brand-new trader must not both create an account)
        $lock = 'aradc_' . $v['mobile'];
        if ((int)DB::value('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) throw new HttpException(503, 'سامانه مشغول پردازش سفارش دیگری برای این شماره است. چند ثانیه بعد دوباره تلاش کنید.');
        try {
            if ($prev = self::findOrder($v['external_id'])) return self::replay($prev, $hash);
            try {
                $r = DB::transaction(fn() => self::apply($v, $hash));
            } catch (\Throwable $e) {
                if (self::isDuplicateKey($e) && ($prev = self::findOrder($v['external_id']))) return self::replay($prev, $hash);
                throw $e;
            }
        } finally {
            try { DB::value('SELECT RELEASE_LOCK(?)', [$lock]); } catch (\Throwable) {}
        }
        self::afterCommit($r, $v);
        return [$r['response'], ['user_id' => $r['user_id'], 'duplicate' => false, 'user_created' => $r['created']]];
    }

    /** Runs inside one transaction: claim external_id → find/create user → charge every item → store response */
    private static function apply(array $v, string $hash): array
    {
        $orderId = DB::insert('arad_contact_orders', ['external_id' => $v['external_id'], 'mobile' => $v['mobile'], 'request_hash' => $hash, 'created_at' => now()]);

        $user = self::findUser($v['mobile']);
        $created = false;
        $password = null;
        $activated = false;
        if ($user) {
            if ((int)$user['is_root'] === 1) throw new HttpException(409, 'این شماره موبایل متعلق به حساب مدیر کل است و از طریق آراد کانتکت قابل تغییر نیست.');
            $uid = (int)$user['id'];
            // names are only filled when the account has none; the password is never touched
            $upd = [];
            if (trim((string)$user['first_name']) === '' && $v['first_name'] !== '') $upd['first_name'] = $v['first_name'];
            if (trim((string)$user['last_name']) === '' && $v['last_name'] !== '') $upd['last_name'] = $v['last_name'];
            if ($upd) DB::update('users', $upd + ['updated_at' => now()], 'id = ?', [$uid]);
            if ($user['status'] === 'pending' && setting('arad_contact_auto_activate', '1') === '1') {
                DB::update('users', ['status' => 'active', 'updated_at' => now()], 'id = ?', [$uid]);
                $activated = true;
            }
        } else {
            $password = self::generatePassword();
            $username = DB::value('SELECT 1 FROM users WHERE LOWER(username) = ?', [$v['mobile']]) ? null : $v['mobile'];
            $roleId = self::roleId();
            $groupId = self::groupId();
            $levelId = self::levelId($groupId);
            $auto = setting('arad_contact_auto_activate', '1') === '1';
            $uid = UserService::create([
                'first_name' => $v['first_name'], 'last_name' => $v['last_name'], 'mobile' => $v['mobile'],
                'username' => $username, 'password' => $password, 'segment' => 'merchant',
                'status' => 'active', 'skip_approval' => $auto,
            ], $roleId ? [$roleId] : []);
            DB::update('users', ['must_change_password' => 1], 'id = ?', [$uid]);
            if ($groupId) DB::run('INSERT IGNORE INTO group_members (group_id, user_id, joined_at) VALUES (?,?,?)', [$groupId, $uid, now()]);
            if ($groupId && $levelId) DB::run('INSERT IGNORE INTO user_levels (user_id, group_id, level_id, assigned_at) VALUES (?,?,?,?)', [$uid, $groupId, $levelId, now()]);
            if (!$levelId) Logger::warning('arad-contact: level «' . self::DEFAULT_LEVEL . '» not found; new user created without level', ['user' => $uid]);
            $created = true;
            $user = DB::find('users', $uid);
        }

        $note = 'آراد کانتکت — ' . $v['external_id'];
        $applied = [];
        foreach ($v['items'] as $it) $applied[] = self::charge($uid, $it, $note);

        $fresh = DB::find('users', $uid) ?? $user;
        $response = [
            'success' => true,
            'duplicate' => false,
            'user_created' => $created,
            'user_id' => $uid,
            'user_status' => (string)$fresh['status'],
            'username' => (string)(($fresh['username'] ?? '') !== '' ? $fresh['username'] : canonical_mobile((string)$fresh['mobile'])),
            'password' => $password,
            'login_url' => url('/login'),
            'applied' => $applied,
        ];
        $stored = $response;
        $stored['password'] = null;
        DB::update('arad_contact_orders', [
            'user_id' => $uid, 'user_created' => $created ? 1 : 0,
            'response_json' => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'password_enc' => $password !== null ? self::encrypt($password) : null,
        ], 'id = ?', [$orderId]);

        return ['response' => $response, 'user_id' => $uid, 'created' => $created, 'activated' => $activated, 'order_id' => $orderId];
    }

    /** Charge one item and report the resulting balance */
    private static function charge(int $uid, array $it, string $note): array
    {
        $svc = self::SERVICES[$it['service_code']];
        [$kind, $factor] = $svc['units'][$it['unit']];
        $type = $svc['credit'];
        $row = ['service_code' => $it['service_code'], 'quantity' => $it['quantity'], 'unit' => $it['unit']];

        if (isset(self::SUBSCRIPTIONS[$type])) {
            [$until, $left] = self::extend($uid, $type, $it['quantity'] * $factor, $note);
            return $row + ['balance' => $left, 'balance_unit' => 'روز', 'valid_until' => $until, 'valid_until_jalali' => jdate($until)];
        }

        $col = Credit::COLUMNS[$type];
        $delta = $it['quantity'] * $factor;
        [$ok, $msg] = Credit::adjustType($uid, $type, $delta, $note, null, null, 'charge', false);
        if (!$ok) throw new \RuntimeException('charge failed: ' . $msg);
        $bal = (int)DB::value("SELECT $col FROM users WHERE id = ?", [$uid]);
        if ($kind === 'minutes') {
            $h = $bal / $factor;
            return $row + ['balance' => floor($h) == $h ? (int)$h : round($h, 2), 'balance_unit' => $it['unit']];
        }
        return $row + ['balance' => $bal, 'balance_unit' => $it['unit']];
    }

    /**
     * Extend a date subscription by $months. A running subscription is extended from its end,
     * otherwise it starts today. Returns [until (Y-m-d), days left].
     */
    private static function extend(int $uid, string $type, int $months, string $note): array
    {
        [$fromCol, $untilCol] = self::SUBSCRIPTIONS[$type];
        $u = DB::one("SELECT `$fromCol` AS f, `$untilCol` AS t FROM users WHERE id = ? FOR UPDATE", [$uid]) ?? ['f' => null, 't' => null];
        $today = date('Y-m-d');
        $running = $u['t'] && $u['t'] >= $today;
        $base = $running ? date('Y-m-d', strtotime($u['t'] . ' +1 day')) : $today;
        $until = date('Y-m-d', strtotime($base . " +$months months -1 day"));
        $upd = [$untilCol => $until];
        if (!$running) $upd[$fromCol] = $today;
        DB::update('users', $upd, 'id = ?', [$uid]);
        $added = (int)round((strtotime($until) - strtotime($base)) / 86400) + 1;
        $left = (int)round((strtotime($until) - strtotime($today)) / 86400) + 1;
        DB::insert('minute_ledger', [
            'user_id' => $uid, 'credit_type' => $type, 'delta' => $added, 'balance_after' => $left, 'kind' => 'charge',
            'note' => mb_substr($note . ' — فعال تا ' . jdate($until), 0, 250), 'created_by' => null, 'created_at' => now(),
        ]);
        return [$until, $left];
    }

    /** Side effects after commit: welcome/charge notification, auto-assignments, audit. Never fails the request. */
    private static function afterCommit(array $r, array $v): void
    {
        $uid = (int)$r['user_id'];
        try {
            $lines = [];
            foreach ($r['response']['applied'] as $a) $lines[] = (self::SERVICES[$a['service_code']]['title'] ?? $a['service_code']) . ': ' . fa($a['quantity']) . ' ' . $a['unit'];
            Notify::send($uid, 'system', $r['created'] ? 'حساب شما در سامانه آموزش آراد برندینگ ساخته شد' : 'خدمات خریداری‌شده شما فعال شد', implode("\n", $lines), url('/me/credits'), 'arad-contact-' . $r['order_id']);
        } catch (\Throwable $e) { Logger::warning('arad-contact notify failed: ' . $e->getMessage()); }
        try {
            if ($r['created'] || $r['activated']) Targeting::syncUser($uid);
        } catch (\Throwable $e) { Logger::warning('arad-contact targeting failed: ' . $e->getMessage(), ['user' => $uid]); }
        Audit::log('arad_contact.provision', 'user', $uid, 'success', [
            'external_id' => $v['external_id'], 'user_created' => $r['created'], 'activated' => $r['activated'],
            'items' => array_map(fn($i) => $i['service_code'] . ':' . $i['quantity'] . ' ' . $i['unit'], $v['items']),
        ]);
    }

    // ------------------------------------------------------------------ request log

    private static function trimJson(mixed $data, int $max = 20000): ?string
    {
        if ($data === null) return null;
        $s = is_string($data) ? $data : (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return strlen($s) > $max ? mb_strcut($s, 0, $max) . '…[truncated]' : $s;
    }

    /** Write one row per API call (+ a line in the file log). Passwords never reach the log. */
    public static function log(string $endpoint, int $status, ?array $request, string $rawBody, array $response, array $meta, int $ms): void
    {
        $resp = $response;
        if (array_key_exists('password', $resp) && $resp['password'] !== null) $resp['password'] = '[REDACTED]';
        $ext = is_array($request) && isset($request['external_id']) && (is_string($request['external_id']) || is_int($request['external_id'])) ? mb_substr(trim((string)$request['external_id']), 0, 191) : null;
        $mob = is_array($request) && isset($request['mobile']) && (is_string($request['mobile']) || is_int($request['mobile'])) ? mb_substr(canonical_mobile((string)$request['mobile']), 0, 20) : null;
        $success = !empty($response['success']);
        $msg = $success ? (!empty($meta['payload_mismatch']) ? 'external_id تکراری با محتوای متفاوت — همان پاسخ قبلی برگردانده شد' : null) : (string)($response['message'] ?? '');
        try {
            DB::insert('arad_contact_logs', [
                'endpoint' => $endpoint, 'method' => Request::method(), 'ip' => Request::ip(), 'http_status' => $status,
                'success' => $success ? 1 : 0, 'duplicate' => !empty($meta['duplicate']) ? 1 : 0, 'user_created' => !empty($meta['user_created']) ? 1 : 0,
                'external_id' => $ext ?: null, 'mobile' => $mob ?: null, 'user_id' => $meta['user_id'] ?? null,
                'message' => $msg !== null ? mb_substr($msg, 0, 500) : null,
                'request_json' => self::trimJson($request ?? ($rawBody !== '' ? $rawBody : null)),
                'response_json' => self::trimJson($resp), 'duration_ms' => max(0, $ms), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::warning('arad-contact log write failed: ' . $e->getMessage());
        }
        Logger::write($success ? 'info' : 'warning', 'arad-contact ' . $endpoint . ' ' . $status, [
            'ip' => Request::ip(), 'external_id' => $ext, 'mobile' => $mob, 'user' => $meta['user_id'] ?? null,
            'duplicate' => !empty($meta['duplicate']), 'created' => !empty($meta['user_created']), 'message' => $msg, 'ms' => $ms,
        ]);
    }
}
