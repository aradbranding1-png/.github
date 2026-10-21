<?php

use App\Core\Env;

return [
    'session_lifetime' => (int) Env::get('SESSION_LIFETIME', 1_209_600), // 14 days
    // name => [max requests, window seconds]
    'rate_limits' => [
        'default' => [120, 60],
        'login' => [10, 300],
        'register' => [5, 3600],
        'api' => [300, 60],
        'search' => [30, 60],
        'proposal' => [60, 3600],
        'letter' => [120, 3600],
        'payment' => [10, 600],
        'notification' => [120, 60],
        'install' => [10, 600],
    ],
    // Payment gateway hosts allowed as form targets in CSP (added in phase 2).
    // Payment gateway hosts: browsers apply form-action to the redirect after the "buy" form.
    'form_action_hosts' => ['https://payment.zarinpal.com', 'https://sandbox.zarinpal.com', 'https://www.zarinpal.com'],
];
