<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\I18n\Catalog;
use App\Core\I18n\I18n;
use App\Core\Security\Audit;
use App\Core\Settings\Settings;

/**
 * «زبان‌ها» (Super Admin, i18n.manage): which interface languages are on, the default for visitors from other
 * countries, automatic detection, and the wording of every UI string in every language.
 *
 * The strings come from the code itself (Catalog: every Persian text the member pages show), plus any string that
 * was shown in a language without a translation (translation_misses) — so a page added later appears here by itself.
 * The ready-made wording ships in app/Lang/{locale}.php; what the super admin writes here is stored in
 * `translations` and wins over the file. Persian is the source and is never edited here.
 */
final class LanguagesController extends AdminController
{
    private const PER_PAGE = 40;

    public function index(Request $request): Response
    {
        $locales = array_diff_key(I18n::LOCALES, [I18n::SOURCE => true]);
        $locale = (string) $request->query('locale', 'en');
        if (!isset($locales[$locale])) {
            $locale = 'en';
        }
        $filter = (string) $request->query('show', 'all');
        if (!in_array($filter, ['all', 'missing', 'edited'], true)) {
            $filter = 'all';
        }
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));

        $catalog = $this->catalog();
        $db = $this->c->get(Connection::class);
        $misses = [];
        foreach ($db->select('SELECT locale, source, hits, last_seen FROM translation_misses ORDER BY last_seen DESC LIMIT 2000') as $m) {
            $misses[(string) $m['locale']][(string) $m['source']] = $m;
        }
        $overrides = [];
        foreach ($db->select('SELECT locale, source, text, updated_at FROM translations') as $o) {
            $overrides[(string) $o['locale']][(string) $o['source']] = $o;
        }

        // Every string the site shows: the catalog plus strings seen without a translation in any language.
        $sources = $catalog;
        foreach ($misses as $rows) {
            foreach ($rows as $src => $_) {
                $sources[$src] ??= ['refs' => ['—'], 'js' => false];
            }
        }

        $stats = [];
        foreach ($locales as $code => $_) {
            $file = I18n::fileDict($code);
            $done = 0;
            foreach ($sources as $src => $_) {
                if (($overrides[$code][$src]['text'] ?? '') !== '' || ($file[$src] ?? '') !== '') {
                    $done++;
                }
            }
            $stats[$code] = ['total' => count($sources), 'done' => $done, 'edited' => count($overrides[$code] ?? [])];
        }

        $file = I18n::fileDict($locale);
        $rows = [];
        foreach ($sources as $src => $meta) {
            $over = $overrides[$locale][$src]['text'] ?? null;
            $shipped = $file[$src] ?? '';
            $missing = ($over ?? '') === '' && $shipped === '';
            if ($filter === 'missing' && !$missing) {
                continue;
            }
            if ($filter === 'edited' && $over === null) {
                continue;
            }
            if ($q !== '' && mb_stripos($src, $q) === false && mb_stripos((string) ($over ?? $shipped), $q) === false
                && mb_stripos(implode(' ', $meta['refs']), $q) === false) {
                continue;
            }
            $rows[] = [
                'source' => $src,
                'hash' => hash('sha256', $src),
                'refs' => $meta['refs'],
                'shipped' => $shipped,
                'override' => $over,
                'missing' => $missing,
                'seen' => $misses[$locale][$src] ?? null,
            ];
        }
        // Untranslated strings that visitors actually saw come first.
        usort($rows, static fn (array $a, array $b): int => [$b['missing'], (int) ($b['seen']['hits'] ?? 0)] <=> [$a['missing'], (int) ($a['seen']['hits'] ?? 0)]);
        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $settings = $this->c->get(Settings::class);
        return $this->view($request, 'admin/languages', [
            'title' => 'زبان‌ها',
            'locales' => $locales,
            'locale' => $locale,
            'filter' => $filter,
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'total' => $total,
            'rows' => $rows,
            'stats' => $stats,
            'enabled' => I18n::enabled(),
            'default' => (string) $settings->get('i18n.default', 'en'),
            'detect' => (bool) $settings->get('i18n.detect', true),
            'saved' => (string) $request->query('saved', ''),
        ]);
    }

    public function settings(Request $request): Response
    {
        $codes = array_keys(I18n::LOCALES);
        $enabled = array_values(array_intersect($codes, (array) $request->input('enabled', [])));
        if (!in_array(I18n::SOURCE, $enabled, true)) {
            array_unshift($enabled, I18n::SOURCE);
        }
        $default = (string) $request->input('default', 'en');
        if (!in_array($default, $enabled, true)) {
            $default = in_array('en', $enabled, true) ? 'en' : I18n::SOURCE;
        }
        $detect = (bool) $request->input('detect');
        $actor = (int) $this->user($request)['id'];
        $settings = $this->c->get(Settings::class);
        $settings->set('i18n.enabled', $enabled, $actor);
        $settings->set('i18n.default', $default, $actor);
        $settings->set('i18n.detect', $detect, $actor);
        $this->c->get(Audit::class)->log('i18n.settings', $actor, null, null, 'success', $request,
            ['enabled' => $enabled, 'default' => $default, 'detect' => $detect]);
        return $this->redirect('/admin/languages', 'تنظیمات زبان‌ها ذخیره شد.');
    }

    public function save(Request $request): Response
    {
        $locale = (string) $request->input('locale', '');
        $source = (string) $request->input('source', '');
        $back = $this->back($request, $locale);
        if (!isset(I18n::LOCALES[$locale]) || $locale === I18n::SOURCE || $source === '' || hash('sha256', $source) !== (string) $request->input('hash')) {
            return $this->redirect($back, 'این عبارت پیدا نشد. صفحه را دوباره باز کنید.', 'error');
        }
        $text = trim(str_replace("\r\n", "\n", (string) $request->input('text', '')));
        if ($text === '') {
            return $this->reset($request);
        }
        if (mb_strlen($text) > 4000) {
            return $this->redirect($back, 'ترجمه حداکثر ۴٬۰۰۰ نویسه است.', 'error');
        }
        $text = self::clean($source, $text);
        $problem = self::placeholderProblem($source, $text);
        if ($problem !== null) {
            return $this->redirect($back, $problem, 'error');
        }
        $actor = (int) $this->user($request)['id'];
        $this->c->get(Connection::class)->exec(
            'INSERT INTO translations (locale, source_hash, source, text, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW(3))
             ON DUPLICATE KEY UPDATE text = VALUES(text), updated_by = VALUES(updated_by), updated_at = NOW(3)',
            [$locale, hash('sha256', $source), $source, $text, $actor]
        );
        $this->c->get(Connection::class)->exec('DELETE FROM translation_misses WHERE locale = ? AND source_hash = ?', [$locale, hash('sha256', $source)]);
        I18n::flush();
        $this->c->get(Audit::class)->log('i18n.translate', $actor, null, null, 'success', $request,
            ['locale' => $locale, 'source' => mb_substr($source, 0, 200), 'text' => mb_substr($text, 0, 200)]);
        return $this->redirect($back, 'ترجمه ذخیره شد.');
    }

    public function reset(Request $request): Response
    {
        $locale = (string) $request->input('locale', '');
        $source = (string) $request->input('source', '');
        $back = $this->back($request, $locale);
        if (!isset(I18n::LOCALES[$locale]) || hash('sha256', $source) !== (string) $request->input('hash')) {
            return $this->redirect($back, 'این عبارت پیدا نشد. صفحه را دوباره باز کنید.', 'error');
        }
        $this->c->get(Connection::class)->exec('DELETE FROM translations WHERE locale = ? AND source_hash = ?', [$locale, hash('sha256', $source)]);
        I18n::flush();
        $this->c->get(Audit::class)->log('i18n.reset', (int) $this->user($request)['id'], null, null, 'success', $request,
            ['locale' => $locale, 'source' => mb_substr($source, 0, 200)]);
        return $this->redirect($back, 'ترجمه دستی حذف شد و ترجمه پیش‌فرض سامانه به کار می‌رود.');
    }

    /** Forget recorded untranslated strings that were not seen again in the last 30 days. */
    public function clearMisses(Request $request): Response
    {
        $n = $this->c->get(Connection::class)->exec('DELETE FROM translation_misses WHERE last_seen < NOW() - INTERVAL 30 DAY');
        return $this->redirect('/admin/languages', 'فهرست عبارت‌های قدیمی پاک شد (' . fa_int($n) . ' مورد).');
    }

    /** @return array<string, array{refs: list<string>, js: bool}> */
    private function catalog(): array
    {
        $version = trim((string) @file_get_contents(BASE_PATH . '/VERSION'));
        $db = $this->c->get(Connection::class);
        return (array) $this->c->get(Cache::class)->remember('i18n:catalog:' . $version . ':' . $this->c->get(Cache::class)->version('reference'), 3600,
            static fn (): array => Catalog::build($db));
    }

    private function back(Request $request, string $locale): string
    {
        $qs = array_filter([
            'locale' => $locale,
            'show' => (string) $request->input('show', ''),
            'q' => (string) $request->input('q', ''),
            'page' => (string) $request->input('page', ''),
        ], static fn (string $v): bool => $v !== '' && $v !== 'all' && $v !== '1');
        return '/admin/languages?' . http_build_query($qs) . '#row-' . substr(hash('sha256', (string) $request->input('source', '')), 0, 12);
    }

    /** Markup only where the Persian source has the same tags, never attributes that run script. */
    public static function clean(string $source, string $text): string
    {
        preg_match_all('~<\s*([a-z][a-z0-9]*)~i', $source, $m);
        $allowed = array_unique(array_map('strtolower', $m[1]));
        $text = strip_tags($text, $allowed === [] ? [] : $allowed);
        $text = (string) preg_replace('~\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $text);
        return (string) preg_replace('~(href|src)\s*=\s*(["\']?)\s*javascript:~i', '$1=$2#', $text);
    }

    /** The translation must keep every :placeholder, %s and {token} of the source. */
    public static function placeholderProblem(string $source, string $text): ?string
    {
        $find = static function (string $s): array {
            preg_match_all('/:[a-z_]+|%s|\{(?:paid_cap|paid|sea|air|nodes)\}/', $s, $m);
            $list = $m[0];
            sort($list);
            return $list;
        };
        $need = $find($source);
        $have = $find($text);
        // An ICU plural block may write # for :n.
        if (preg_match('/\{\w+,\s*plural/', $text)) {
            $need = array_values(array_diff($need, [':n']));
            $have = array_values(array_diff($have, [':n']));
        }
        if ($need === $have) {
            return null;
        }
        if ($need === []) {
            return 'متن اصلی نشانه جایگزین ندارد؛ ' . implode(' ', array_unique($have)) . ' را از ترجمه بردارید.';
        }
        return 'نشانه‌های جایگزین متن اصلی باید دقیقاً در ترجمه هم باشند: ' . implode(' ', array_unique($need)) . ' (سامانه جای آن‌ها نام، عدد یا عبارت مناسب می‌گذارد).';
    }
}
