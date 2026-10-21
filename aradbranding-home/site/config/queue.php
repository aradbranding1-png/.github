<?php

return [
    // Highest priority first. The worker always re-checks earlier queues before later ones.
    'queues' => ['critical', 'letters', 'notifications', 'events', 'reports', 'maintenance'],
];
