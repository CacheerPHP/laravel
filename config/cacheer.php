<?php

return [
    'default_driver' => env('CACHEER_DEFAULT_DRIVER', env('CACHE_DRIVER', 'file')),
    'cache_dir' => env('CACHEER_CACHE_DIR', storage_path('cacheer')),
    'compress' => env('CACHEER_COMPRESS', false),
    'encryption_key' => env('CACHEER_ENCRYPTION_KEY'),
    'reporter' => [
        'enabled' => env('CACHEER_REPORTER_ENABLED', false),
        'events' => env('CACHEER_MONITOR_EVENTS', storage_path('logs/cacheer-monitor.jsonl')),
    ],
];
