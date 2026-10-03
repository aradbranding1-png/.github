<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Gate;
use App\Core\Env;
use App\Core\Jalali;
use App\Core\Settings;
use App\Core\View;
use App\Core\HttpException;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}

function is_installed(): bool
{
    return is_file(BASE_PATH . '/.env') && is_file(STORAGE_PATH . '/installed.lock');
}

function app_version(): string
{
    static $v = null;
    if ($v === null) {
        $f = BASE_PATH . '/VERSION';
        $v = is_file($f) ? trim((string)file_get_contents($f)) : '0.0.0';
    }
    return $v;
}

function is_production(): bool
{
    return env('APP_ENV', 'production') === 'production';
}

function debug_enabled(): bool
{
    return !is_production() && filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
}

/** HTML escape */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    $u = rtrim((string)env('APP_URL', ''), '/');
    if ($u !== '') return $u;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

function base_path_uri(): string
{
    $p = parse_url(base_url(), PHP_URL_PATH) ?: '';
    return rtrim($p, '/');
}

/** Build application URL. Uses pretty URLs when enabled, otherwise index.php?r= */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $pretty = filter_var(env('APP_PRETTY_URLS', true), FILTER_VALIDATE_BOOLEAN);
    if ($pretty) {
        $u = base_url() . ($path === '/' ? '/' : $path);
        return $query ? $u . '?' . http_build_query($query) : $u;
    }
    $q = array_merge(['r' => $path], $query);
    return base_url() . '/index.php?' . http_build_query($q);
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public_html/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr(md5((string)filemtime($file) . app_version()), 0, 8) : app_version();
    return base_url() . '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function current_path(): string
{
    return App\Core\Request::path();
}

function redirect(string $to, int $code = 302): never
{
    if (!preg_match('~^https?://~', $to)) $to = url($to);
    // uploads sent with the progress bar (XHR): return the target so the browser navigates itself
    // (a real redirect would be followed inside the XHR and consume the flash messages)
    if (($_SERVER['HTTP_X_UPLOAD_XHR'] ?? '') === '1') json_out(['redirect' => $to]);
    header('Location: ' . $to, true, $code);
    exit;
}

/** Largest upload the server accepts, in bytes (app setting and PHP limits) */
function upload_limit_bytes(): int
{
    $toBytes = function (string $v): int {
        $v = trim($v); if ($v === '') return PHP_INT_MAX;
        $n = (float)$v; $u = strtolower(substr($v, -1));
        return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n)));
    };
    $lim = [(int)setting('max_upload_mb', 512) * 1048576, $toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size'))];
    return (int)min(array_filter($lim, fn($x) => $x > 0) ?: [PHP_INT_MAX]);
}

function back(): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $base = base_url();
    if ($ref !== '' && str_starts_with($ref, $base)) redirect($ref);
    redirect('/');
}

/** Flash message. Plain text is escaped; pass $html = true only for markup built from escaped parts. */
function flash(string $type, string $message, bool $html = false): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $html ? $message : e($message)];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function keep_old(array $data): void
{
    unset($data['password'], $data['password_confirmation'], $data['_token']);
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function csrf_token(): string
{
    return App\Core\Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function auth(): ?array
{
    return Auth::user();
}

function uid(): ?int
{
    return Auth::id();
}

function can(string $permission): bool
{
    return Gate::allows($permission);
}

function can_any(array $permissions): bool
{
    foreach ($permissions as $p) if (Gate::allows($p)) return true;
    return false;
}

function is_root(): bool
{
    return Gate::isRoot();
}

function abort(int $code, string $message = ''): never
{
    throw new HttpException($code, $message);
}

function view(string $template, array $data = [], ?string $layout = 'layouts/app'): string
{
    return View::render($template, $data, $layout);
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function setting(string $key, mixed $default = null): mixed
{
    return Settings::get($key, $default);
}

/** Convert latin digits to persian digits */
function fa(mixed $v): string
{
    return strtr((string)$v, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

/** Normalize persian/arabic digits to latin and arabic letters to persian */
function normalize_input(string $v): string
{
    $v = strtr($v, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        'ي' => 'ی', 'ك' => 'ک',
    ]);
    return trim($v);
}

/** Iranian mobile in one stored form: 09xxxxxxxxx (accepts 912…, +98912…, 0098912…, spaces, Persian digits) */
function canonical_mobile(?string $v): string
{
    $d = preg_replace('/[\s\-\(\)]/', '', normalize_input((string)$v));
    if ($d === '') return '';
    $n = ltrim($d, '+');
    if (str_starts_with($n, '0098')) $n = substr($n, 4);
    elseif (str_starts_with($n, '98') && strlen($n) === 12) $n = substr($n, 2);
    elseif (str_starts_with($n, '0')) $n = substr($n, 1);
    return preg_match('/^9\d{9}$/', $n) ? '0' . $n : $d;
}

/** Another (non-deleted) account already uses this mobile in any of its stored forms */
function mobile_taken(string $mobile, int $exceptId = 0): ?array
{
    $c = canonical_mobile($mobile);
    if ($c === '') return null;
    $v = [$c];
    if (preg_match('/^0(9\d{9})$/', $c, $m)) array_push($v, $m[1], '+98' . $m[1], '98' . $m[1], '0098' . $m[1]);
    $in = implode(',', array_fill(0, count($v), '?'));
    return \App\Core\DB::one("SELECT id, first_name, last_name, mobile FROM users WHERE deleted_at IS NULL AND id <> ? AND mobile IN ($in) LIMIT 1", array_merge([$exceptId], $v));
}

function nf(mixed $n, int $dec = 0): string
{
    return fa(number_format((float)$n, $dec));
}

/** Jalali date formatting */
function jdate(?string $datetime, string $format = 'Y/m/d'): string
{
    if (!$datetime || str_starts_with($datetime, '0000')) return '—';
    return fa(Jalali::format($format, strtotime($datetime)));
}

function jdatetime(?string $datetime): string
{
    return jdate($datetime, 'Y/m/d H:i');
}

function time_ago(?string $datetime): string
{
    if (!$datetime) return '—';
    $d = time() - strtotime($datetime);
    if ($d < 60) return 'لحظاتی پیش';
    if ($d < 3600) return fa((int)floor($d / 60)) . ' دقیقه پیش';
    if ($d < 86400) return fa((int)floor($d / 3600)) . ' ساعت پیش';
    if ($d < 86400 * 30) return fa((int)floor($d / 86400)) . ' روز پیش';
    return jdate($datetime);
}

function human_size(int|float $bytes): string
{
    $u = ['بایت', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return fa(round($bytes, 1)) . ' ' . $u[$i];
}

function icon(string $name, string $class = ''): string
{
    static $icons = null;
    if ($icons === null) $icons = require APP_PATH . '/Config/icons.php';
    $inner = $icons[$name] ?? $icons['circle-help'];
    return '<svg class="ic ' . e($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

function full_name(?array $u): string
{
    if (!$u) return '—';
    $n = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $n !== '' ? $n : ($u['mobile'] ?? ('#' . ($u['id'] ?? '')));
}

/** User display name with blue verification tick for root admin */
function user_name_html(?array $u): string
{
    $html = e(full_name($u));
    if ($u && !empty($u['is_root'])) {
        $html .= ' <span class="blue-tick" title="مدیر کل سامانه">' . icon('badge-check') . '</span>';
    }
    if ($u && setting('growth_show_badge', '1') === '1') {
        $st = array_key_exists('tg_stage', $u) ? (int)$u['tg_stage'] : (isset($u['id']) ? App\Services\TraderGrowth::cachedStage((int)$u['id']) : 0);
        if ($st > 0) $html .= ' ' . App\Services\TraderGrowth::badge($st);
    }
    return $html;
}

function currency_label(): string
{
    return (string)setting('growth_currency', 'تومان');
}

/** Name + badges for list rows that carry first_name/last_name and a user id under another key */
function person_name(array $r, string $idKey = 'user_id'): string
{
    return user_name_html(['id' => (int)($r[$idKey] ?? 0), 'first_name' => $r['first_name'] ?? '', 'last_name' => $r['last_name'] ?? '', 'is_root' => $r['is_root'] ?? 0] + (array_key_exists('tg_stage', $r) ? ['tg_stage' => $r['tg_stage']] : []));
}

function avatar_url(?array $u): string
{
    if ($u && !empty($u['avatar_path'])) {
        return url('/avatar/' . (int)$u['id'], ['v' => substr(md5((string)$u['avatar_path']), 0, 6)]);
    }
    return '';
}

function avatar_html(?array $u, string $size = 'md'): string
{
    $src = avatar_url($u);
    if ($src) return '<img class="avatar avatar-' . e($size) . '" src="' . e($src) . '" alt="" loading="lazy">';
    $name = full_name($u);
    $initial = mb_substr($name, 0, 1);
    $hue = $u ? (((int)($u['id'] ?? 0)) * 47) % 360 : 200;
    return '<span class="avatar avatar-' . e($size) . ' avatar-initial" style="--h:' . $hue . '">' . e($initial) . '</span>';
}

/** URL of a stored file by id (served through the authorized FileController). */
/** Resized (WebP) version of an uploaded image for cards and lists; width 320, 640 or 960 */
function image_url(mixed $fileId, int $width = 640): string
{
    $u = file_url($fileId);
    return $u === '' ? '' : $u . (str_contains($u, '?') ? '&' : '?') . 'w=' . $width;
}

function file_url(mixed $fileId, bool $download = false): string
{
    static $cache = [];
    $fileId = (int)$fileId;
    if ($fileId <= 0) return '';
    if (!array_key_exists($fileId, $cache)) {
        $cache[$fileId] = App\Core\DB::value('SELECT uuid FROM files WHERE id = ? AND deleted_at IS NULL', [$fileId]);
    }
    if (!$cache[$fileId]) return '';
    return url('/file/' . $cache[$fileId], $download ? ['dl' => 1] : []);
}

function str_limit(?string $s, int $len = 80): string
{
    $s = trim(strip_tags((string)$s));
    return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function uuid4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function client_ip(): string
{
    return App\Core\Request::ip();
}

/** Sanitize rich-text HTML coming from the lesson editor (allow-list). */
function clean_html(?string $html): string
{
    // Markdown from AI tools (**bold**, ## headings, * bullets) becomes real formatting
    return App\Core\HtmlSanitizer::clean(App\Core\Markdown::render((string)$html));
}

function status_badge(string $status): string
{
    $map = App\Core\Labels::STATUS;
    [$label, $tone] = $map[$status] ?? [$status, 'gray'];
    return '<span class="badge badge-' . $tone . '">' . e($label) . '</span>';
}

function label(string $group, ?string $key): string
{
    $arr = constant('App\\Core\\Labels::' . strtoupper($group));
    $v = $arr[$key ?? ''] ?? $key ?? '—';
    return is_array($v) ? $v[0] : (string)$v;
}

function progress_ring(float $pct, int $size = 64, string $tone = 'primary'): string
{
    $pct = max(0, min(100, $pct));
    $r = 15.9155;
    $dash = round($pct, 1);
    return '<div class="ring ring-' . e($tone) . '" style="width:' . $size . 'px;height:' . $size . 'px">'
        . '<svg viewBox="0 0 36 36"><circle class="ring-bg" cx="18" cy="18" r="' . $r . '"/>'
        . '<circle class="ring-fg" cx="18" cy="18" r="' . $r . '" stroke-dasharray="' . $dash . ' 100"/></svg>'
        . '<span>' . fa((int)round($pct)) . '٪</span></div>';
}

function progress_bar(float $pct, string $tone = ''): string
{
    $pct = max(0, min(100, $pct));
    if ($tone === '') $tone = $pct >= 100 ? 'success' : ($pct >= 50 ? 'primary' : ($pct > 0 ? 'warning' : 'muted'));
    return '<div class="pbar pbar-' . e($tone) . '"><i style="width:' . round($pct, 1) . '%"></i></div>';
}

function selected(mixed $a, mixed $b): string
{
    if (is_array($b)) return in_array((string)$a, array_map('strval', $b), true) ? ' selected' : '';
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(mixed $cond): string
{
    return $cond ? ' checked' : '';
}

function input(string $key, mixed $default = null): mixed
{
    return App\Core\Request::input($key, $default);
}

function paginate_links(array $p): string
{
    return View::partial('partials/pagination', ['p' => $p]);
}

function mask_secret(?string $s): string
{
    if (!$s) return '';
    return str_repeat('•', 8) . substr($s, -4);
}

/**
 * Root-password confirmation field that phones and browsers can remember:
 * a (visually hidden) username next to an autocomplete="current-password" field lets iCloud Keychain /
 * Google Password Manager save it and fill it back with Face ID / Touch ID / fingerprint.
 */
function confirm_password_field(string $label = 'رمز عبور مدیر کل'): string
{
    $u = \App\Core\Auth::user() ?? [];
    $login = (string)(($u['username'] ?? '') ?: ($u['mobile'] ?? '') ?: ($u['email'] ?? ''));
    $id = 'cp-' . bin2hex(random_bytes(3));
    return '<div class="field"><label for="' . $id . '">' . e($label) . '</label>'
        . '<input class="sr-user" type="text" name="cp_user" value="' . e($login) . '" autocomplete="username" tabindex="-1" aria-hidden="true" readonly>'
        . '<input class="ltr" id="' . $id . '" type="password" name="confirm_password" autocomplete="current-password" required>'
        . '<div class="hint">روی گوشی، رمز را یک بار ذخیره کنید تا دفعه بعد با Face ID یا اثر انگشت پر شود.</div></div>';
}
