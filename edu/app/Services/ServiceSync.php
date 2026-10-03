<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/**
 * Purchased services from the sales/services system.
 * The API is called per phone number (every phone registered for the trader) together with the trader's name;
 * results are stored in tg_user_services and the growth system reads only that table (never the API on page view).
 * Full refresh of all traders runs in resumable batches (admin page loop + cron).
 *
 * Config: settings growth_api_* (URL, method, parameter names, response mapping) and, for the secret,
 * SERVICES_API_TOKEN (+ optional SERVICES_API_HEADER) in .env.
 */
final class ServiceSync
{
    public static function configured(): bool
    {
        return setting('growth_api_enabled', '0') === '1' && trim((string)setting('growth_api_url', '')) !== '';
    }

    public static function norm(?string $s): string
    {
        $s = str_replace(['ي', 'ك', "\u{200C}", 'ـ', 'أ', 'إ', 'ة'], ['ی', 'ک', ' ', '', 'ا', 'ا', 'ه'], (string)$s);
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[\s\-_.,،]+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Canonical Iranian mobile 09xxxxxxxxx (or the cleaned digits when not a mobile) */
    public static function canonPhone(string $p): string
    {
        $d = preg_replace('/\D+/', '', normalize_input($p)) ?? '';
        if (str_starts_with($d, '0098')) $d = substr($d, 4);
        elseif (str_starts_with($d, '98') && strlen($d) === 12) $d = substr($d, 2);
        if (strlen($d) === 10 && $d[0] === '9') $d = '0' . $d;
        return $d;
    }

    public static function validPhone(string $p): bool
    {
        return (bool)preg_match('/^09\d{9}$/', self::canonPhone($p));
    }

    public static function formatPhone(string $canon): string
    {
        $rest = substr($canon, 1);
        return match ((string)setting('growth_api_phone_format', '09')) {
            '98' => '98' . $rest,
            '+98' => '+98' . $rest,
            '9' => $rest,
            default => $canon,
        };
    }

    /** All phones of a trader: primary mobile + extra phones */
    public static function phones(int $uid): array
    {
        $out = [];
        $u = DB::one('SELECT mobile FROM users WHERE id = ?', [$uid]);
        if ($u && $u['mobile']) $out[self::canonPhone((string)$u['mobile'])] = ['phone' => self::canonPhone((string)$u['mobile']), 'label' => 'شماره اصلی حساب', 'primary' => true, 'row' => null];
        foreach (DB::all('SELECT * FROM user_phones WHERE user_id = ? ORDER BY id', [$uid]) as $r) {
            $c = self::canonPhone((string)$r['phone']);
            if ($c !== '' && !isset($out[$c])) $out[$c] = ['phone' => $c, 'label' => $r['label'] ?: 'شماره اضافه', 'primary' => false, 'row' => $r];
        }
        return array_values($out);
    }

    // ------------------------------------------------------------------ HTTP
    /** @return array{ok:bool,http:int,error:?string,items:array,body:string,url:string} */
    public static function request(string $phone, string $name): array
    {
        $url = trim((string)setting('growth_api_url', ''));
        $res = ['ok' => false, 'http' => 0, 'error' => null, 'items' => [], 'body' => '', 'url' => ''];
        if ($url === '' || !preg_match('~^https?://~i', $url)) { $res['error'] = 'آدرس API تنظیم نشده یا نامعتبر است.'; return $res; }
        if (is_production() && !preg_match('~^https://~i', $url)) { $res['error'] = 'آدرس API باید https باشد.'; return $res; }
        $fp = self::formatPhone($phone);
        $method = strtoupper((string)setting('growth_api_method', 'GET')) === 'POST' ? 'POST' : 'GET';
        $pp = trim((string)setting('growth_api_phone_param', 'mobile')) ?: 'mobile';
        $np = trim((string)setting('growth_api_name_param', 'name'));
        $params = [];
        if (str_contains($url, '{mobile}') || str_contains($url, '{name}')) {
            $url = str_replace(['{mobile}', '{name}'], [rawurlencode($fp), rawurlencode($name)], $url);
        } else {
            $params[$pp] = $fp;
            if ($np !== '') $params[$np] = $name;
        }
        if ($method === 'GET' && $params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        $res['url'] = preg_replace('/(\d{4})\d{3}(\d{4})/', '$1***$2', $url) ?? $url;

        $headers = ['Accept: application/json'];
        $token = (string)env('SERVICES_API_TOKEN', '');
        if ($token !== '') {
            $h = trim((string)env('SERVICES_API_HEADER', '')) ?: 'Authorization';
            $headers[] = $h . ': ' . (strcasecmp($h, 'Authorization') === 0 && !preg_match('/^(Bearer|Basic|Token)\s/i', $token) ? 'Bearer ' . $token : $token);
        }
        $ch = curl_init($url);
        $timeout = max(3, min(60, (int)setting('growth_api_timeout', 15)));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'AradEdu-Growth/' . app_version(),
        ]);
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $body = curl_exec($ch);
        $res['http'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            $res['error'] = 'ارتباط با سامانه خدمات برقرار نشد' . ($err ? ' (' . mb_substr($err, 0, 120) . ')' : '') . '.';
            Logger::error('Growth API: ' . $err, ['host' => parse_url($url, PHP_URL_HOST)]);
            return $res;
        }
        $res['body'] = mb_substr((string)$body, 0, 6000);
        $json = json_decode((string)$body, true);
        // "customer not found" (often HTTP 404) is a valid answer: this number has no purchases
        $msg = is_array($json) ? (string)($json['error'] ?? $json['message'] ?? '') : '';
        if (in_array($res['http'], [404, 200], true) && $msg !== '' && self::notFound($msg)) { $res['ok'] = true; $res['not_found'] = true; return $res; }
        if ($res['http'] >= 400) { $res['error'] = 'سامانه خدمات خطای HTTP ' . $res['http'] . ' برگرداند' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 150) : '.'); return $res; }
        if (!is_array($json)) { $res['error'] = 'پاسخ سامانه خدمات JSON معتبر نیست.'; return $res; }
        if (isset($json['ok']) && $json['ok'] === false || isset($json['success']) && $json['success'] === false) {
            // "customer not found" style answers are a valid empty result, anything else is an error
            $msg = (string)($json['message'] ?? $json['error'] ?? '');
            if ($msg !== '' && !self::notFound($msg)) { $res['error'] = 'پاسخ سامانه خدمات: ' . mb_substr($msg, 0, 150); return $res; }
            $res['ok'] = true;
            return $res;
        }
        $res['items'] = self::parse($json);
        $res['ok'] = true;
        return $res;
    }

    public static function notFound(string $msg): bool
    {
        return (bool)preg_match('/not.?found|یافت نشد|پیدا نشد|وجود ندارد|ثبت نشده|no (customer|merchant|record|data)/iu', $msg);
    }

    private static function dig(mixed $a, string $path): mixed
    {
        if ($path === '') return $a;
        foreach (explode('.', $path) as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) return null;
            $a = $a[$k];
        }
        return $a;
    }

    private static function field(array $row, string $configured, array $fallbacks): ?string
    {
        foreach (array_filter([$configured, ...$fallbacks]) as $f) {
            $v = self::dig($row, $f);
            if (is_scalar($v) && trim((string)$v) !== '') return trim((string)$v);
        }
        return null;
    }

    /** Extract the purchased-services list from an API response */
    public static function parse(array $json): array
    {
        $path = trim((string)setting('growth_api_list_path', ''));
        $list = null;
        if ($path !== '') $list = self::dig($json, $path);
        else {
            if (array_is_list($json)) $list = $json;
            else foreach (['data', 'services', 'items', 'result', 'results', 'orders', 'purchases', 'data.services', 'data.items', 'data.orders', 'customer.services'] as $k) {
                $v = self::dig($json, $k);
                if (is_array($v) && array_is_list($v)) { $list = $v; break; }
            }
        }
        if (!is_array($list) || !array_is_list($list)) return [];
        $okStatuses = array_filter(array_map(fn($s) => self::norm($s), explode(',', (string)setting('growth_api_ok_statuses', ''))), fn($s) => $s !== '');
        $out = [];
        foreach ($list as $row) {
            if (is_string($row) || is_numeric($row)) $row = ['name' => (string)$row];
            if (!is_array($row)) continue;
            $name = self::field($row, (string)setting('growth_api_field_name', ''), ['title', 'name', 'service', 'service_name', 'service_title', 'product', 'product_name', 'product_title', 'product.title', 'product.name', 'service.title', 'service.name']);
            $code = self::field($row, (string)setting('growth_api_field_code', ''), ['code', 'service_code', 'sku', 'service_id', 'product_id', 'product.code', 'service.code', 'id']);
            $status = self::field($row, (string)setting('growth_api_field_status', ''), ['status', 'state', 'payment_status', 'order_status']);
            $date = self::field($row, (string)setting('growth_api_field_date', ''), ['purchased_at', 'paid_at', 'date', 'created_at', 'order_date', 'buy_date']);
            if ($name === null && $code === null) continue;
            $active = !$okStatuses || ($status !== null && in_array(self::norm($status), $okStatuses, true));
            $out[] = ['name' => mb_substr($name ?? ('#' . $code), 0, 250), 'code' => $code !== null ? mb_substr($code, 0, 120) : null, 'status' => $status !== null ? mb_substr($status, 0, 60) : null,
                'date' => self::parseDate($date), 'active' => $active, 'raw' => mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '', 0, 2000)];
        }
        return $out;
    }

    private static function parseDate(?string $d): ?string
    {
        if ($d === null || $d === '') return null;
        $d = normalize_input($d);
        if (ctype_digit($d) && strlen($d) >= 10) return date('Y-m-d H:i:s', (int)substr($d, 0, 10));
        if (preg_match('/^(1[34]\d\d)[\/\-](\d{1,2})[\/\-](\d{1,2})/', $d)) return \App\Core\Jalali::parse(substr($d, 0, 10)) ? \App\Core\Jalali::parse(substr($d, 0, 10)) . ' 00:00:00' : null;
        $ts = strtotime($d);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    // ------------------------------------------------------------------ matching API names → catalog
    public static function matcher(): array
    {
        $m = [];
        foreach (DB::all('SELECT id, name, match_keys FROM tg_services') as $s) {
            $exact = [self::norm($s['name'])]; $contains = [];
            foreach (preg_split('/[\r\n]+/', (string)$s['match_keys']) ?: [] as $k) {
                $k = trim($k);
                if ($k === '') continue;
                if (str_ends_with($k, '*')) { $c = self::norm(rtrim($k, '*')); if ($c !== '') $contains[] = $c; }
                else $exact[] = self::norm($k);
            }
            $m[] = ['id' => (int)$s['id'], 'exact' => array_unique($exact), 'contains' => $contains];
        }
        return $m;
    }

    public static function matchId(string $name, ?string $code, array $m): ?int
    {
        $n = self::norm($name); $c = self::norm($code);
        foreach ($m as $s) if (in_array($n, $s['exact'], true) || ($c !== '' && in_array($c, $s['exact'], true))) return $s['id'];
        foreach ($m as $s) foreach ($s['contains'] as $k) if (str_contains($n, $k)) return $s['id'];
        return null;
    }

    /** Re-link stored API rows after the catalog / keys changed */
    public static function rematch(): int
    {
        $m = self::matcher();
        $n = 0;
        foreach (DB::all("SELECT id, service_name, service_code, service_id FROM tg_user_services WHERE source = 'api'") as $r) {
            $id = self::matchId((string)$r['service_name'], $r['service_code'], $m);
            if ($id !== ($r['service_id'] === null ? null : (int)$r['service_id'])) { DB::update('tg_user_services', ['service_id' => $id], 'id = ?', [(int)$r['id']]); $n++; }
        }
        if ($n) TraderGrowth::markAllDirty();
        return $n;
    }

    public static function unmatched(int $limit = 60): array
    {
        return DB::all("SELECT service_name, MAX(service_code) AS service_code, COUNT(DISTINCT user_id) AS users FROM tg_user_services WHERE service_id IS NULL AND source = 'api' GROUP BY service_name ORDER BY users DESC, service_name LIMIT " . $limit);
    }

    // ------------------------------------------------------------------ sync one trader
    public static function syncUser(int $uid): array
    {
        if (!self::configured()) return ['ok' => false, 'count' => 0, 'error' => 'اتصال به سامانه خدمات/فروش هنوز تنظیم نشده است.'];
        $u = DB::one('SELECT id, first_name, last_name, mobile FROM users WHERE id = ?', [$uid]);
        if (!$u) return ['ok' => false, 'count' => 0, 'error' => 'کاربر یافت نشد.'];
        $name = trim($u['first_name'] . ' ' . $u['last_name']);
        $phones = self::phones($uid);
        $m = self::matcher();
        $okAny = false; $count = 0; $errors = [];
        $now = now();
        foreach ($phones as $p) {
            $r = self::request($p['phone'], $name);
            if ($r['ok']) {
                $okAny = true;
                $count += count($r['items']);
                DB::transaction(function () use ($uid, $p, $r, $m, $now) {
                    DB::delete('tg_user_services', "user_id = ? AND source = 'api' AND phone = ?", [$uid, $p['phone']]);
                    foreach ($r['items'] as $it) {
                        DB::insert('tg_user_services', ['user_id' => $uid, 'service_id' => self::matchId($it['name'], $it['code'], $m), 'phone' => $p['phone'], 'service_name' => $it['name'], 'service_code' => $it['code'],
                            'status' => $it['status'], 'is_active' => $it['active'] ? 1 : 0, 'source' => 'api', 'purchased_at' => $it['date'], 'raw' => $it['raw'], 'api_at' => $now, 'updated_at' => $now]);
                    }
                });
            } else {
                $errors[] = $p['phone'] . ': ' . $r['error'];
            }
            if ($p['row']) DB::update('user_phones', ['last_checked_at' => $now, 'last_status' => $r['ok'] ? 'ok' : 'error', 'last_count' => $r['ok'] ? count($r['items']) : null], 'id = ?', [(int)$p['row']['id']]);
        }
        // numbers removed from the profile no longer bring services
        $list = array_column($phones, 'phone');
        if ($list) DB::run("DELETE FROM tg_user_services WHERE user_id = ? AND source = 'api' AND (phone IS NULL OR phone NOT IN (" . DB::in($list) . '))', array_merge([$uid], $list));
        else DB::delete('tg_user_services', "user_id = ? AND source = 'api'", [$uid]);
        $upd = ['tg_services_api_at' => $now, 'tg_services_error' => $errors ? mb_substr(implode(' | ', $errors), 0, 250) : null];
        if ($okAny) $upd['tg_services_at'] = $now;
        DB::update('users', $upd, 'id = ?', [$uid]);
        TraderGrowth::refresh($uid);
        return ['ok' => $okAny && !$errors, 'partial' => $okAny && (bool)$errors, 'count' => $count, 'phones' => count($phones), 'error' => $errors ? implode(' | ', $errors) : null];
    }

    // ------------------------------------------------------------------ full refresh runs
    private static function participantsWhere(): array
    {
        $segs = TraderGrowth::segments() ?: ['merchant'];
        return ["u.deleted_at IS NULL AND u.status = 'active' AND u.segment IN (" . DB::in($segs) . ')', $segs];
    }

    public static function running(): ?array
    {
        try { return DB::one("SELECT * FROM tg_sync_runs WHERE status = 'running' ORDER BY id DESC LIMIT 1"); } catch (\Throwable) { return null; }
    }

    public static function lastRun(): ?array
    {
        try { return DB::one('SELECT * FROM tg_sync_runs ORDER BY id DESC LIMIT 1'); } catch (\Throwable) { return null; }
    }

    public static function startRun(?int $by): int
    {
        if ($r = self::running()) return (int)$r['id'];
        [$w, $p] = self::participantsWhere();
        $total = (int)DB::value("SELECT COUNT(*) FROM users u WHERE $w", $p);
        return DB::insert('tg_sync_runs', ['status' => 'running', 'total' => $total, 'started_by' => $by, 'started_at' => now()]);
    }

    public static function step(int $runId, float $seconds = 6.0): array
    {
        $t = microtime(true);
        $run = DB::find('tg_sync_runs', $runId);
        if (!$run || $run['status'] !== 'running') return $run ?? [];
        [$w, $p] = self::participantsWhere();
        $ids = DB::column("SELECT u.id FROM users u WHERE $w AND u.id > ? ORDER BY u.id LIMIT 40", array_merge($p, [(int)$run['last_user_id']]));
        $done = (int)$run['done']; $ok = (int)$run['ok']; $failed = (int)$run['failed']; $svc = (int)$run['services']; $last = (int)$run['last_user_id'];
        $finished = !$ids;
        foreach ($ids as $i => $id) {
            $r = self::syncUser((int)$id);
            $done++; $last = (int)$id; $svc += $r['count'];
            if ($r['ok'] || !empty($r['partial'])) $ok++; else $failed++;
            if (microtime(true) - $t > $seconds) break;
            if ($i === count($ids) - 1 && count($ids) < 40) $finished = true;
        }
        $upd = ['done' => $done, 'ok' => $ok, 'failed' => $failed, 'services' => $svc, 'last_user_id' => $last];
        if ($finished) { $upd['status'] = 'done'; $upd['finished_at'] = now(); }
        DB::update('tg_sync_runs', $upd, 'id = ?', [$runId]);
        return DB::find('tg_sync_runs', $runId) ?? [];
    }

    public static function cancel(int $runId): void
    {
        DB::update('tg_sync_runs', ['status' => 'cancelled', 'finished_at' => now()], "id = ? AND status = 'running'", [$runId]);
    }

    /** Cron: continue an unfinished full refresh */
    public static function continueRuns(float $seconds = 40.0): int
    {
        $r = self::running();
        if (!$r || !self::configured()) return 0;
        $t = microtime(true); $n = 0;
        while (microtime(true) - $t < $seconds) {
            $before = (int)$r['done'];
            $r = self::step((int)$r['id'], max(1.0, $seconds - (microtime(true) - $t)));
            $n += (int)($r['done'] ?? 0) - $before;
            if (($r['status'] ?? '') !== 'running') break;
        }
        return $n;
    }
}
