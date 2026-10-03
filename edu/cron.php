<?php
/**
 * Scheduled tasks — run from DirectAdmin → Advanced Features → Cronjobs, every 15 minutes:
 *   /usr/local/bin/php /home/USERNAME/domains/edu.aradbranding.me/cron.php >/dev/null 2>&1
 * This file lives outside public_html and refuses to run from a web request.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app/bootstrap.php';
if (!is_installed()) { fwrite(STDERR, "Not installed\n"); exit(1); }
$verbose = in_array('-v', $argv, true);
$res = App\Services\Cron::run($verbose);
if ($verbose) echo "done\n";
exit(0);
