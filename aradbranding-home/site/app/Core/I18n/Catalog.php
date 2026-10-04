<?php

declare(strict_types=1);

namespace App\Core\I18n;

use App\Core\Db\Connection;
use Throwable;

/**
 * Every Persian UI string of the member-facing site, found by reading the source: PHP string literals of the
 * member pages, modules and core messages, the strings the browser scripts pass to T(), the 3D globe's labels, and
 * the reference names stored in the database (countries, languages, categories).
 * The staff area (admin pages and modules) is not part of it: it is always Persian.
 */
final class Catalog
{
    /** Directories and files that hold member-facing text. */
    private const PHP_PATHS = [
        'app/Views', 'app/Modules', 'app/Core/Validation', 'app/Core/Errors', 'app/Core/Storage', 'app/Core/Security',
        'app/Core/Http/Middleware/MaintenanceMode.php', 'app/Core/helpers.php',
    ];

    /** Staff-only or machine-facing code: never shown in another language. */
    private const SKIP = [
        'app/Views/admin/', 'app/Views/public/_trade_sprite.php', 'app/Modules/Admin/', 'app/Modules/Ops/', 'app/Modules/System/DemoSeeder.php',
        'app/Modules/System/BackupService.php', 'app/Modules/Integrations/AradContactController.php',
        'app/Modules/Integrations/ApiController.php', 'app/Modules/Integrations/PublicApiController.php',
        'app/Modules/Integrations/ApiClients.php', 'app/Modules/Pages/PageLabels.php',
    ];

    private const JS = ['public_html/assets/app.js', 'public_html/assets/panel.js', 'public_html/assets/auth.js',
        'public_html/assets/trade-home.js'];

    private const GLOBE = 'public_html/assets/trade-globe.js';

    /**
     * @return array<string, array{refs: list<string>, js: bool}> Persian source → where it is used
     */
    public static function build(?Connection $db = null): array
    {
        $out = [];
        $add = static function (string $src, string $ref, bool $js = false) use (&$out): void {
            if ($src === '' || !preg_match('/\p{Arabic}/u', $src)) {
                return;
            }
            if (!isset($out[$src])) {
                $out[$src] = ['refs' => [], 'js' => false];
            }
            if (count($out[$src]['refs']) < 6 && !in_array($ref, $out[$src]['refs'], true)) {
                $out[$src]['refs'][] = $ref;
            }
            $out[$src]['js'] = $out[$src]['js'] || $js;
        };
        foreach (self::phpFiles() as $rel) {
            foreach (self::phpStrings(BASE_PATH . '/' . $rel) as [$s, $line]) {
                $add($s, $rel . ':' . $line);
            }
        }
        foreach (self::JS as $rel) {
            $file = BASE_PATH . '/' . $rel;
            if (!is_file($file)) {
                continue;
            }
            $code = (string) file_get_contents($file);
            if (preg_match_all("/\\bT\\('((?:[^'\\\\]|\\\\.)*)'/u", $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as [$s, $off]) {
                    $add(stripcslashes($s), $rel . ':' . (substr_count($code, "\n", 0, $off) + 1), true);
                }
            }
        }
        $globe = BASE_PATH . '/' . self::GLOBE;
        if (is_file($globe)) {
            $code = (string) file_get_contents($globe);
            // esbuild writes non-Latin text as \uXXXX / \xXX escapes: walk out from each Persian escape to its quotes
            $seen = [];
            $pos = 0;
            while (($pos = stripos($code, '\\u06', $pos)) !== false) {
                $a = $pos;
                while ($a > 0 && !(in_array($code[$a - 1], ['"', "'", '`'], true) && ($a < 2 || $code[$a - 2] !== '\\'))) {
                    $a--;
                }
                $q = $code[$a - 1] ?? '"';
                $b = $pos;
                $len = strlen($code);
                while ($b < $len && !($code[$b] === $q && $code[$b - 1] !== '\\')) {
                    $b++;
                }
                $pos = $b + 1;
                if (isset($seen[$a]) || $b - $a > 400) {
                    continue;
                }
                $seen[$a] = true;
                $raw = (string) preg_replace('/\\\\x([0-9A-Fa-f]{2})/', '\\\\u00$1', substr($code, $a, $b - $a));
                $s = json_decode('"' . str_replace('"', '\\"', str_replace('\\"', '"', $raw)) . '"');
                if (is_string($s) && !str_contains($s, '${') && mb_strlen($s) > 1 && !preg_match('/^[۰-۹]+$/u', $s)) {
                    $add($s, 'trade-globe.js', true);
                }
            }
        }
        if ($db !== null) {
            try {
                foreach ($db->select('SELECT code, name_fa FROM countries') as $r) {
                    $add((string) $r['name_fa'], 'db:countries:' . $r['code']);
                }
                foreach ($db->select('SELECT code, name_fa FROM languages') as $r) {
                    $add((string) $r['name_fa'], 'db:languages:' . $r['code']);
                }
                foreach ($db->select('SELECT id, name_fa FROM categories') as $r) {
                    $add((string) $r['name_fa'], 'db:categories:' . $r['id']);
                }
            } catch (Throwable) {
            }
        }
        return $out;
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $files = [];
        foreach (self::PHP_PATHS as $p) {
            $abs = BASE_PATH . '/' . $p;
            if (is_file($abs)) {
                $files[] = $p;
                continue;
            }
            if (!is_dir($abs)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $files[] = substr($f->getPathname(), strlen(BASE_PATH) + 1);
                }
            }
        }
        $files = array_values(array_filter($files, static function (string $f): bool {
            foreach (self::SKIP as $s) {
                if (str_starts_with($f, $s)) {
                    return false;
                }
            }
            return true;
        }));
        sort($files);
        return $files;
    }

    /** @return list<array{0: string, 1: int}> Persian string literals of a PHP file (regex patterns and single letters left out) */
    private static function phpStrings(string $file): array
    {
        $out = [];
        $isGuard = str_ends_with($file, 'ContactGuard.php') || str_ends_with($file, 'Support/Str.php');
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (!is_array($t) || !preg_match('/\p{Arabic}/u', $t[1])) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $q = $t[1][0];
                $s = substr($t[1], 1, -1);
                $s = $q === "'" ? strtr($s, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($s);
                if (str_starts_with($s, '/') || str_starts_with($s, '~') || preg_match('/^\p{Arabic}$/u', $s) || preg_match('/\\\\s|\(\?:|\|.*\|/', $s)
                    || ($isGuard && mb_strlen($s) < 120) || preg_match('/^[۰-۹٠-٩]$/u', $s)) {
                    continue;
                }
                $out[] = [$s, $t[2]];
            } elseif ($t[0] === T_INLINE_HTML) {
                // Persian left directly in HTML would not be translated: list it so it shows up as untranslatable.
                foreach (preg_split('/<[^>]*>/', $t[1]) ?: [] as $chunk) {
                    $chunk = trim($chunk);
                    if ($chunk !== '' && preg_match('/\p{Arabic}/u', $chunk)) {
                        $out[] = [$chunk, $t[2]];
                    }
                }
            }
        }
        return $out;
    }
}
