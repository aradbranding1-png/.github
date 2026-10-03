<?php
/**
 * Superseded in 1.0.43: existing members are charged with the «شارژ گروهی نقش‌ها» button
 * (App\Services\RoleGrants::runBulk), so this migration intentionally does nothing.
 */
return [
    'description' => 'شارژ یک‌باره دارندگان نقش «کارمند فراگیر» (جایگزین‌شده با دکمه شارژ گروهی)',
    'up' => function (PDO $db): void {},
];
