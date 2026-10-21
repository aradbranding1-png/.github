<?php

// DirectAdmin cron (every 15 minutes):
//   */15 * * * * /usr/local/bin/php /home/USER/domains/aradbranding.app/cron.php >/dev/null 2>&1
declare(strict_types=1);

$argv = ['console', 'cron'];
require __DIR__ . '/bootstrap/console.php';
