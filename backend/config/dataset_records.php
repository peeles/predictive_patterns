<?php

return [
    'ingestion' => [
        'temp_directory' => env('DATASET_RECORD_INGESTION_TEMP_PATH', storage_path('app/dataset-record-ingestion')),
        'notifications' => [
            'mail' => array_filter(array_map('trim', explode(',', (string) env('DATASET_RECORD_INGESTION_NOTIFY_MAIL', '')))),
            'slack_webhook' => env('DATASET_RECORD_INGESTION_NOTIFY_SLACK_WEBHOOK'),
        ],
        'progress_interval' => (int) env('DATASET_RECORD_INGESTION_PROGRESS_INTERVAL', 5000),
        'chunk_size' => (int) env('DATASET_RECORD_INGESTION_CHUNK_SIZE', 500),
    ],
];
