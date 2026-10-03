<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;

/**
 * SSO client for my.aradbranding.me.
 *
 * Two integration modes are supported so that whatever the official "my" API provides can be plugged in
 * from the admin panel without code changes:
 *   1) oauth2 : Authorization Code + PKCE → token endpoint → userinfo endpoint (Laravel Passport / OIDC style)
 *   2) jwt    : "my" redirects the user to /sso/callback?token=<JWT HS256> signed with a shared secret
 * Secrets (client secret, shared secret, API key) are read ONLY from .env, never from the database.
 */
final class SsoClient
{
    public static function enabled(): bool
    {
        return setting('sso_enabled') === '1' && !self::configErrors();
    }

    public static function mode(): string
    {
        return setting('sso_mode') === 'jwt' ? 'jwt' : 'oauth2';
    }

    public static function callbackUrl(): string
    {
        return url('/sso/callback');
    }

    public static function configErrors(): array
    {
        $e = [];
        if (self::mode() === 'oauth2') {
            if (!setting('sso_authorize_url')) $e[] = 'آدرس Authorize تعیین نشده';
            if (!setting('sso_token_url')) $e[] = 'آدرس Token تعیین نشده';
            if (!setting('sso_userinfo_url')) $e[] = 'آدرس User Info تعیین نشده';
            if (!env('SSO_CLIENT_ID')) $e[] = 'SSO_CLIENT_ID در .env خالی است';
            if (!env('SSO_CLIENT_SECRET')) $e[] = 'SSO_CLIENT_SECRET در .env خالی است';
            if (!extension_loaded('curl')) $e[] = 'افزونه curl فعال نیست';
        } else {
            if (!env('SSO_SHARED_SECRET') || strlen((string)env('SSO_SHARED_SECRET')) < 32) $e[] = 'SSO_SHARED_SECRET در .env حداقل ۳۲ کاراکتر باشد';
            if (!setting('sso_authorize_url')) $e[] = 'آدرس صفحه ورود my تعیین نشده';
        }
        return $e;
    }

    /** Build the redirect URL to my.aradbranding.me */
    public static function authorizeUrl(string $intent = 'login'): string
    {
        $state = bin2hex(random_bytes(20));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $_SESSION['sso'] = ['state' => $state, 'verifier' => $verifier, 'intent' => $intent, 'at' => time()];
        $base = (string)setting('sso_authorize_url');
        if (self::mode() === 'jwt') {
            $q = ['return_url' => self::callbackUrl(), 'state' => $state, 'client' => 'edu'];
        } else {
            $q = [
                'response_type' => 'code', 'client_id' => env('SSO_CLIENT_ID'), 'redirect_uri' => self::callbackUrl(),
                'scope' => (string)setting('sso_scopes'), 'state' => $state,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
            ];
        }
        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($q);
    }

    /**
     * Validate callback and return a normalized profile:
     *  ['id','first_name','last_name','mobile','email','avatar','active','raw']
     */
    public static function handleCallback(array $query): array
    {
        $sess = $_SESSION['sso'] ?? null;
        unset($_SESSION['sso']);
        if (!empty($query['error'])) throw new HttpException(400, 'ورود از طریق my لغو شد یا ناموفق بود.');
        if (!$sess || empty($query['state']) || !hash_equals($sess['state'], (string)$query['state']) || time() - $sess['at'] > 900) {
            throw new HttpException(400, 'درخواست ورود نامعتبر یا منقضی است. دوباره تلاش کنید.');
        }
        if (self::mode() === 'jwt') {
            $claims = self::verifyJwt((string)($query['token'] ?? ''));
            $data = $claims;
            if (setting('sso_userinfo_url') && env('MY_API_KEY')) {
                $idKey = (string)setting('sso_map_id');
                $fresh = self::fetchUser((string)self::dig($claims, $idKey));
                if ($fresh) $data = $fresh;
            }
            return self::normalize($data) + ['intent' => $sess['intent']];
        }
        if (empty($query['code'])) throw new HttpException(400, 'کد احراز هویت دریافت نشد.');
        $tok = self::http('POST', (string)setting('sso_token_url'), [
            'grant_type' => 'authorization_code', 'code' => (string)$query['code'], 'redirect_uri' => self::callbackUrl(),
            'client_id' => env('SSO_CLIENT_ID'), 'client_secret' => env('SSO_CLIENT_SECRET'), 'code_verifier' => $sess['verifier'],
        ]);
        if (empty($tok['access_token'])) throw new HttpException(502, 'دریافت توکن از my ناموفق بود.');
        $_SESSION['sso_token'] = ['access' => $tok['access_token'], 'exp' => time() + (int)($tok['expires_in'] ?? 3600)];
        $info = self::http('GET', (string)setting('sso_userinfo_url'), null, ['Authorization: Bearer ' . $tok['access_token']]);
        $info = isset($info['data']) && is_array($info['data']) ? $info['data'] : $info;
        return self::normalize($info) + ['intent' => $sess['intent']];
    }

    /** Fetch up-to-date user data from my (server-to-server with API key). */
    public static function fetchUser(string $myId): ?array
    {
        $url = (string)setting('sso_userinfo_url');
        if (!$url || !env('MY_API_KEY') || $myId === '') return null;
        $u = str_contains($url, '{id}') ? str_replace('{id}', rawurlencode($myId), $url) : $url . (str_contains($url, '?') ? '&' : '?') . 'id=' . rawurlencode($myId);
        try {
            $r = self::http('GET', $u, null, ['Authorization: Bearer ' . env('MY_API_KEY')]);
            return isset($r['data']) && is_array($r['data']) ? $r['data'] : $r;
        } catch (\Throwable $e) {
            Logger::warning('SSO fetchUser failed: ' . $e->getMessage());
            return null;
        }
    }

    public static function normalize(array $d): array
    {
        $m = fn(string $k) => self::dig($d, (string)setting('sso_map_' . $k));
        $id = trim((string)$m('id'));
        if ($id === '') throw new HttpException(502, 'شناسه کاربر از my دریافت نشد. نگاشت فیلدها را در تنظیمات SSO بررسی کنید.');
        $statusVal = $m('status');
        $activeValues = array_map('trim', explode(',', strtolower((string)setting('sso_active_values', 'active,1,true'))));
        $active = $statusVal === null ? true : in_array(strtolower(is_bool($statusVal) ? ($statusVal ? 'true' : 'false') : (string)$statusVal), $activeValues, true);
        $mobile = normalize_input((string)$m('mobile'));
        if (preg_match('/^\+?98(9\d{9})$/', $mobile, $mm)) $mobile = '0' . $mm[1];
        return [
            'id' => $id,
            'first_name' => trim((string)$m('first_name')),
            'last_name' => trim((string)$m('last_name')),
            'mobile' => $mobile ?: null,
            'email' => filter_var((string)$m('email'), FILTER_VALIDATE_EMAIL) ? strtolower((string)$m('email')) : null,
            'avatar' => filter_var((string)$m('avatar'), FILTER_VALIDATE_URL) ? (string)$m('avatar') : null,
            'active' => $active,
            'raw_keys' => array_keys($d),
        ];
    }

    /** Read a value by dot path, e.g. "profile.mobile" */
    public static function dig(array $d, string $path): mixed
    {
        if ($path === '') return null;
        $cur = $d;
        foreach (explode('.', $path) as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
            $cur = $cur[$p];
        }
        return is_array($cur) ? null : $cur;
    }

    public static function verifyJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new HttpException(400, 'توکن ورود نامعتبر است.');
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64d($h), true);
        if (($header['alg'] ?? '') !== 'HS256') throw new HttpException(400, 'الگوریتم امضای توکن پشتیبانی نمی‌شود.');
        $expected = hash_hmac('sha256', $h . '.' . $p, (string)env('SSO_SHARED_SECRET'), true);
        if (!hash_equals($expected, self::b64d($s))) throw new HttpException(400, 'امضای توکن ورود معتبر نیست.');
        $claims = json_decode(self::b64d($p), true);
        if (!is_array($claims)) throw new HttpException(400, 'توکن ورود نامعتبر است.');
        $now = time();
        if (isset($claims['exp']) && $now > (int)$claims['exp'] + 30) throw new HttpException(400, 'توکن ورود منقضی شده است.');
        if (isset($claims['nbf']) && $now + 30 < (int)$claims['nbf']) throw new HttpException(400, 'توکن ورود هنوز معتبر نیست.');
        if (!isset($claims['exp'])) throw new HttpException(400, 'توکن ورود فاقد زمان انقضا است.');
        $jti = (string)($claims['jti'] ?? '');
        if ($jti !== '') {
            // replay protection
            if (\App\Core\DB::value('SELECT 1 FROM rate_limits WHERE `key` = ?', [substr(hash('sha256', 'jti:' . $jti), 0, 64)])) throw new HttpException(400, 'این توکن قبلاً استفاده شده است.');
            \App\Core\RateLimiter::hit('jti:' . $jti, 1, 86400);
        }
        return $claims;
    }

    private static function b64d(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** JSON HTTP call with TLS verification and strict timeouts. */
    public static function http(string $method, string $url, ?array $form = null, array $headers = []): array
    {
        if (!preg_match('~^https://~i', $url) && is_production()) throw new HttpException(502, 'آدرس سرویس my باید https باشد.');
        $ch = curl_init($url);
        $headers[] = 'Accept: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'AradEdu-SSO/' . app_version(),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form ?? []));
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            Logger::error('SSO HTTP error: ' . $err, ['url' => parse_url($url, PHP_URL_HOST)]);
            throw new HttpException(502, 'ارتباط با my.aradbranding.me برقرار نشد.');
        }
        $json = json_decode((string)$body, true);
        if ($code >= 400 || !is_array($json)) {
            Logger::error('SSO HTTP ' . $code, ['url' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH), 'body' => mb_substr((string)$body, 0, 300)]);
            throw new HttpException(502, 'پاسخ نامعتبر از my.aradbranding.me دریافت شد.');
        }
        return $json;
    }

    public static function probe(): true|string
    {
        $url = (string)setting('sso_authorize_url');
        if (!$url || !extension_loaded('curl')) return 'امکان بررسی نیست';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code > 0 && $code < 500) ? true : 'سرور my در دسترس نیست (کد ' . $code . ')';
    }

    /** Download a remote avatar (max 3MB, images only). */
    public static function downloadAvatar(string $url): ?string
    {
        if (!extension_loaded('curl') || !preg_match('~^https?://~', $url)) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_MAXFILESIZE => 3 * 1024 * 1024]);
        $bin = curl_exec($ch);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (!is_string($bin) || $bin === '' || strlen($bin) > 3 * 1024 * 1024 || !str_starts_with($type, 'image/')) return null;
        try {
            return \App\Core\Upload::saveAvatarFromString($bin);
        } catch (\Throwable) {
            return null;
        }
    }
}
