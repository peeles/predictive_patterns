<?php

return [
    'ingestion' => [
        'temp_directory' => env('CRIME_INGESTION_TEMP_PATH', storage_path('app/crime-ingestion')),
        'notifications' => [
            'mail' => array_filter(array_map('trim', explode(',', (string) env('CRIME_INGESTION_NOTIFY_MAIL', '')))),
            'slack_webhook' => env('CRIME_INGESTION_NOTIFY_SLACK_WEBHOOK'),
        ],
        'progress_interval' => (int) env('CRIME_INGESTION_PROGRESS_INTERVAL', 5000),
        'chunk_size' => (int) env('CRIME_INGESTION_CHUNK_SIZE', 500),
    ],
];
