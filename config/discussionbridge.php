<?php

return [
    'enabled' => env('DISCUSSIONBRIDGE_ENABLED', false),
    'forum_url' => env('DISCUSSIONBRIDGE_FORUM_URL'),
    'site_origin' => env('DISCUSSIONBRIDGE_SITE_ORIGIN'),
    'connection_id' => env('DISCUSSIONBRIDGE_CONNECTION_ID'),
    'secret_file' => env('DISCUSSIONBRIDGE_SECRET_FILE'),
    'lane' => env('DISCUSSIONBRIDGE_LANE'),
    'collections' => array_values(array_filter(array_map('trim', explode(',', (string) env('DISCUSSIONBRIDGE_COLLECTIONS', 'pages'))))),
    'adapter_id' => 'statamic-discussionbridge',
    'adapter_version' => '0.1.0-alpha.3',
    'connect_timeout_seconds' => 2,
    'response_timeout_seconds' => 5,
    'maximum_response_bytes' => 65536,
    'presentation_cache_seconds' => 60,
    'worker_batch_limit' => 25,
];
