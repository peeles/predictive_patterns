<?php

return [
    'ttl' => (int) env('MODEL_STATUS_TTL', 86400),
    'channel' => env('MODEL_STATUS_CHANNEL', 'models:status'),
];
