<?php
/**
 * Scheduled tasks — run from DirectAdmin → Advanced Features → Cronjobs, every 15 minutes:
 *   /usr/local/php82/bin/php /home/USERNAME/domains/edu.aradbranding.me/cron.php >/dev/null 2>&1
 * (the PHP binary must be 8.1+; the health page shows the exact path of the site's PHP)
 * This file lives outside public_html and refuses to run from a web request.
 *
 * Every attempt is written to storage/logs/cron-attempt.json (PHP version, binary, result, last error),
 * so the health page can tell why cron does not work even when its output goes to /dev/null.
 * The top of this file stays PHP 5 compatible on purpose: an old CLI PHP must still be able to report itself.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['__cron_attempt_file'] = __DIR__ . '/storage/logs/cron-attempt.json';
$GLOBALS['__cron_attempt'] = array(
    'at' => date('c'),
    'php' => PHP_VERSION,
    'binary' => defined('PHP_BINARY') ? PHP_BINARY : '',
    'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (($p = posix_getpwuid(posix_geteuid())) ? $p['name'] : '') : get_current_user(),
    'status' => 'started',
    'error' => '',
);
function __cron_attempt_save($status, $error = '')
{
    $GLOBALS['__cron_attempt']['status'] = $status;
    if ($error !== '') $GLOBALS['__cron_attempt']['error'] = substr($error, 0, 1000);
    $GLOBALS['__cron_attempt']['finished'] = date('c');
    @file_put_contents($GLOBALS['__cron_attempt_file'], json_encode($GLOBALS['__cron_attempt']));
}
register_shutdown_function(function () {
    if ($GLOBALS['__cron_attempt']['status'] !== 'started') return;
    $e = error_get_last();
    __cron_attempt_save('error', $e ? $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'] : 'stopped unexpectedly');
});
__cron_attempt_save('started');

if (PHP_VERSION_ID < 80100) {
    __cron_attempt_save('old_php', 'PHP ' . PHP_VERSION . ' — 8.1+ required');
    fwrite(STDERR, 'PHP 8.1 or newer is required (this is ' . PHP_VERSION . ")\n");
    exit(1);
}

require __DIR__ . '/app/bootstrap.php';
if (!is_installed()) { __cron_attempt_save('not_installed'); fwrite(STDERR, "Not installed\n"); exit(1); }
$verbose = in_array('-v', $argv, true);
$res = App\Services\Cron::run($verbose);
__cron_attempt_save(isset($res['skipped']) ? 'skipped' : 'ok', isset($res['skipped']) ? (string)$res['skipped'] : '');
if ($verbose) echo "done\n";
exit(0);
