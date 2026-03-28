<?php

return [
    'default_driver' => env('CACHEER_DEFAULT_DRIVER', env('CACHE_DRIVER', 'file')),
    'cache_dir' => env('CACHEER_CACHE_DIR', storage_path('cacheer')),
    'compress' => (bool) env('CACHEER_COMPRESS', false),
    'encryption_key' => env('CACHEER_ENCRYPTION_KEY'),
    'reporter' => [
        'enabled' => (bool) env('CACHEER_REPORTER_ENABLED', false),
        'events' => env('CACHEER_MONITOR_EVENTS', storage_path('logs/cacheer-monitor.jsonl')),
    ],
    'monitor' => [
        'host' => env('CACHEER_MONITOR_HOST', '127.0.0.1'),
        'port' => (int) env('CACHEER_MONITOR_PORT', 9966),
        'endpoint' => env('CACHEER_MONITOR_ENDPOINT', 'http://127.0.0.1:9966/api/events/clear'),
        'timeout' => (int) env('CACHEER_MONITOR_TIMEOUT', 30),
    ],
];
