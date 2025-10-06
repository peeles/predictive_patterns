<?php

$defaultOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:5173',
];

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', $defaultOrigins)))
)));

$resolvedOrigins = $allowedOrigins !== [] ? $allowedOrigins : $defaultOrigins;

return [
    'paths' => ['*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $resolvedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['*'],
    'max_age' => 0,
    'supports_credentials' => true,
];
