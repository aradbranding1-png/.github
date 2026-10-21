<?php

use App\Core\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', ''),
    'username' => Env::get('DB_USERNAME', ''),
    'password' => Env::get('DB_PASSWORD', ''),
    'persistent' => (bool) Env::get('DB_PERSISTENT', false),
    'connect_timeout' => 3,
    'lock_wait_timeout' => 5,
    // Metadata-lock wait (ALTER TABLE, partitions) and per-statement limit for web requests.
    // CLI (cron, worker, migrations) waits longer and has no statement limit; see Connection::applyTimeouts().
    'metadata_lock_timeout' => 8,
    'statement_timeout_ms' => 20000,
    'slow_query_ms' => (int) Env::get('SLOW_QUERY_MS', 300),
    // Phase 2: set DB_READ_HOST to route replica-safe reads to a replica.
    'read' => [
        'host' => Env::get('DB_READ_HOST', ''),
    ],
    // Monthly RANGE partitions, maintained by PartitionManager. Changed only by migration.
    'partitioned' => ['activity_events', 'audit_logs', 'login_logs', 'proposal_views', 'letter_messages', 'notifications'],
    'partition_months_ahead' => 3,
];
