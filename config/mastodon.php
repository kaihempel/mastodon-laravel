<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth application
    |--------------------------------------------------------------------------
    |
    | redirect_uri: Callback URL registered at every instance. When null and the
    | package routes are enabled, the named route "mastodon.callback" is used.
    |
    */

    'redirect_uri' => env('MASTODON_REDIRECT_URI'),
    'client_name' => env('MASTODON_CLIENT_NAME', env('APP_NAME', 'Laravel')),
    'website' => env('MASTODON_WEBSITE'),

    // Scopes for Mastodon >= 4.3 (supports the "profile" scope).
    'scopes' => ['profile', 'write:statuses', 'write:media', 'read:statuses'],

    // Scopes for servers without the "profile" scope (< 4.3).
    'fallback_scopes' => ['read:accounts', 'write:statuses', 'write:media', 'read:statuses'],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | retries: additional attempts on 429, 5xx and connection errors.
    | max_retry_wait_s: longest time a synchronous request sleeps for a retry
    | (e.g. until X-RateLimit-Reset). Longer waits raise a RateLimitException,
    | which queued jobs turn into a release().
    |
    */

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'retries' => 2,
        'backoff_ms' => 500,
        'max_retry_wait_s' => 30,
        'max_redirects' => 3,
        'user_agent' => env('MASTODON_USER_AGENT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | allowed_disks: filesystem disks MediaFile::fromDisk() may read from.
    | allowed_paths: directory roots MediaFile::fromPath() may read from.
    |                An empty array disables plain path uploads.
    |
    */

    'media' => [
        'poll_interval_ms' => 1500,
        'poll_timeout_s' => 180,
        'allowed_disks' => ['local', 'public'],
        'allowed_paths' => [storage_path('app')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Local throttling (per account, in addition to the server limits)
    |--------------------------------------------------------------------------
    */

    'throttle' => [
        'enabled' => true,
        'media_uploads' => ['max' => 30, 'per_seconds' => 1800],
        'deletes' => ['max' => 30, 'per_seconds' => 1800],
    ],

    'instance_cache_ttl' => 3600,

    // Only for local development and tests: allows http:// and private addresses.
    'allow_http_instances' => env('MASTODON_ALLOW_HTTP_INSTANCES', false),

    'tables' => [
        'apps' => 'mastodon_apps',
        'accounts' => 'mastodon_accounts',
        'posts' => 'mastodon_posts',
    ],

    'routes' => [
        'enabled' => true,
        'prefix' => 'mastodon',
        'middleware' => ['web', 'auth'],
        'redirect_after' => '/',
    ],

    'queue' => [
        'connection' => null,
        'queue' => null,
    ],
];
