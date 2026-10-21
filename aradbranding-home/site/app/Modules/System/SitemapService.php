<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Db\Connection;
use App\Core\Env;

/**
 * Background sitemap generation (doc §118): public page teasers only, 45,000 URLs per file,
 * a sitemap index at /sitemap.xml and robots.txt pointing at it. Runs from cron, never per request.
 */
final class SitemapService
{
    private const PER_FILE = 45000;

    public function __construct(private Connection $db)
    {
    }

    public function generate(): int
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');
        $dir = BASE_PATH . '/public_html/sitemaps';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach (glob($dir . '/pages-*.xml') ?: [] as $old) {
            @unlink($old);
        }

        $files = [];
        $total = 0;
        $last = 0;
        $fileNo = 0;
        $fh = null;
        $inFile = 0;
        $open = function () use (&$fh, &$fileNo, &$inFile, &$files, $dir): void {
            $fileNo++;
            $name = 'pages-' . $fileNo . '.xml';
            $files[] = $name;
            $fh = fopen($dir . '/' . $name . '.tmp', 'wb');
            fwrite($fh, '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
            $inFile = 0;
        };
        $close = function () use (&$fh, &$fileNo, $dir): void {
            if ($fh) {
                fwrite($fh, "</urlset>\n");
                fclose($fh);
                rename($dir . '/pages-' . $fileNo . '.xml.tmp', $dir . '/pages-' . $fileNo . '.xml');
                $fh = null;
            }
        };

        $open();
        fwrite($fh, '<url><loc>' . htmlspecialchars($base . '/', ENT_XML1) . "</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>\n");
        while (true) {
            $rows = $this->db->select(
                'SELECT p.id, p.updated_at, u.handle, l.code
                 FROM pages p JOIN users u ON u.id = p.user_id JOIN languages l ON l.id = p.language_id
                 WHERE p.id > ? AND p.status = 2 AND p.deleted_at IS NULL AND u.status IN (1, 2) AND u.handle IS NOT NULL
                 ORDER BY p.id LIMIT 5000',
                [$last]
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                if ($inFile >= self::PER_FILE) {
                    $close();
                    $open();
                }
                $loc = htmlspecialchars($base . '/p/' . $r['handle'] . '/' . $r['code'], ENT_XML1);
                fwrite($fh, "<url><loc>{$loc}</loc><lastmod>" . substr((string) $r['updated_at'], 0, 10) . "</lastmod></url>\n");
                $inFile++;
                $total++;
                $last = (int) $r['id'];
            }
        }
        $close();

        $index = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($files as $f) {
            $index .= '<sitemap><loc>' . htmlspecialchars($base . '/sitemaps/' . $f, ENT_XML1) . '</loc><lastmod>' . gmdate('Y-m-d') . "</lastmod></sitemap>\n";
        }
        file_put_contents(BASE_PATH . '/public_html/sitemap.xml', $index . "</sitemapindex>\n", LOCK_EX);

        file_put_contents(BASE_PATH . '/public_html/robots.txt', "User-agent: *\nAllow: /\nAllow: /p/\nDisallow: /install\nDisallow: /health\nDisallow: /ops/\nDisallow: /api/\nDisallow: /admin\n"
            . "Disallow: /dashboard\nDisallow: /account\nDisallow: /wallet\nDisallow: /letters\nDisallow: /payments\nDisallow: /proposals\nDisallow: /search\nDisallow: /discover\nDisallow: /__bypass/\n\nSitemap: {$base}/sitemap.xml\n", LOCK_EX);
        return $total;
    }
}
