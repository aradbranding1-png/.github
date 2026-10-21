<?php
/** «مرا به خاطر بسپار» — one row per remembered device, valid 30 days after the last visit */
return [
    'description' => 'ورود ماندگار «مرا به خاطر بسپار» (۳۰ روز از آخرین بازدید)',
    'up' => function (PDO $db): void {
        $db->exec(\App\Core\RememberMe::ddl());
    },
];
