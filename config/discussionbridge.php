<?php

return [
    'enabled' => env('DISCUSSIONBRIDGE_ENABLED', false),
    'forum_url' => env('DISCUSSIONBRIDGE_FORUM_URL'),
    'forum_name' => \Illuminate\Support\Env::getRepository()->get('DISCUSSIONBRIDGE_FORUM_NAME'),
    'site_origin' => env('DISCUSSIONBRIDGE_SITE_ORIGIN'),
    'connection_id' => env('DISCUSSIONBRIDGE_CONNECTION_ID'),
    'secret_file' => env('DISCUSSIONBRIDGE_SECRET_FILE'),
    'lane' => env('DISCUSSIONBRIDGE_LANE'),
    'collections' => array_values(array_filter(array_map('trim', explode(',', (string) env('DISCUSSIONBRIDGE_COLLECTIONS', 'pages'))))),
    'source_author_name' => env('DISCUSSIONBRIDGE_SOURCE_AUTHOR_NAME', 'Statamic'),
    'source_author_profile_url' => env('DISCUSSIONBRIDGE_SOURCE_AUTHOR_PROFILE_URL'),
    'rich_content' => env('DISCUSSIONBRIDGE_RICH_CONTENT'),
    'adapter_id' => 'statamic-discussion-bridge',
    'adapter_version' => \CodeWorksLabs\DiscussionBridgeStatamic\Version::VALUE,
    'connect_timeout_seconds' => 2,
    'response_timeout_seconds' => 5,
    'maximum_response_bytes' => 65536,
    'presentation_cache_seconds' => 60,
    'worker_batch_limit' => 25,
    // Flat/DB/SSG are native storage/publication profiles of this one addon.
    'cms_profile' => env('DISCUSSIONBRIDGE_CMS_PROFILE'),
    // Exact installed Antlers source templates whose whole authoring article
    // uses discussionbridge:article. No production template is guessed.
    'cms_source_templates' => [],
    'cms_source_static_public_directory' => null,
    'cms_state_directory' => env('DISCUSSIONBRIDGE_CMS_STATE_DIRECTORY'),
    'cms_static_hooks' => ['deploy' => null, 'reconcile' => null, 'restore' => null],
];
