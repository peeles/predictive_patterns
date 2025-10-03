<?php

use App\Enums\Role;

return [
    'rate_limits' => [
        Role::Admin->value => (int) env('API_RATE_LIMIT_ADMIN', 240),
        Role::Analyst->value => (int) env('API_RATE_LIMIT_ANALYST', 120),
        Role::Viewer->value => (int) env('API_RATE_LIMIT_VIEWER', 60),
    ],
    'map_rate_limits' => [
        Role::Admin->value => (int) env('API_MAP_RATE_LIMIT_ADMIN', 1200),
        Role::Analyst->value => (int) env('API_MAP_RATE_LIMIT_ANALYST', 900),
        Role::Viewer->value => (int) env('API_MAP_RATE_LIMIT_VIEWER', 600),
    ],
    'auth_rate_limits' => [
        'login' => (int) env('API_RATE_LIMIT_AUTH_LOGIN', 10),
        'refresh' => (int) env('API_RATE_LIMIT_AUTH_REFRESH', 60),
    ],
    'idempotency_ttl' => (int) env('API_IDEMPOTENCY_TTL', 300),
    'payload_limits' => [
        'ingest' => (int) env('API_PAYLOAD_MAX_KB', 204_800),
        'predict' => (int) env('API_PREDICT_MAX_KB', 10_240),
    ],
    'allowed_ingest_mimes' => array_values(array_unique(array_filter(array_map(
        static fn (string $mime): string => trim($mime),
        explode(',', (string) env(
            'API_ALLOWED_INGEST_MIMES',
            'text/csv,text/plain,application/vnd.ms-excel,application/json,application/geo+json'
        ))
    )))),
];
