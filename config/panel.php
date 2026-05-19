<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel Settings
    |--------------------------------------------------------------------------
    */

    'name' => env('PANEL_NAME', 'Hermes Panel'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    | Hermes Panel is built for developers managing their own VPS. The
    | safe default is auth ENABLED — session login + header password +
    | WhatsApp number bypass are enforced. Set PANEL_AUTH_ENABLED=false
    | only on a trusted network (SSH tunnel / VPN / private LAN); the
    | panel will refuse to boot in production with auth disabled unless
    | PANEL_DEV_BYPASS=true is also set explicitly.
    */

    'auth_enabled' => filter_var(env('PANEL_AUTH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'dev_bypass' => filter_var(env('PANEL_DEV_BYPASS', false), FILTER_VALIDATE_BOOLEAN),

    'username' => env('PANEL_USERNAME', 'admin'),

    'password' => env('PANEL_PASSWORD', ''),

    'session_lifetime' => (int) env('PANEL_SESSION_LIFETIME', 15),

    /*
    |--------------------------------------------------------------------------
    | Owner Numbers (WhatsApp)
    |--------------------------------------------------------------------------
    | WhatsApp numbers (with country code, no +) that are allowed access.
    | Format: 62895341414271 (comma-separated for multiple)
    */
    'owner_numbers' => array_filter(
        explode(',', env('PANEL_OWNER_NUMBERS', '')),
        fn ($n) => ! empty(trim($n))
    ),

    /*
    |--------------------------------------------------------------------------
    | Projects Directory
    |--------------------------------------------------------------------------
    | Relative to panel root. All managed projects reside here.
    */
    'projects_dir' => env('PANEL_PROJECTS_DIR', 'Project'),

    /*
    |--------------------------------------------------------------------------
    | Default Project
    |--------------------------------------------------------------------------
    | Project folder name to auto-select when none is active in session.
    */
    'default_project' => env('PANEL_DEFAULT_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | Allowed File Extensions for Editing
    |--------------------------------------------------------------------------
    */
    'editable_extensions' => [
        'php', 'js', 'css', 'blade.php', 'html', 'txt',
        'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql',
        'gitignore', 'htaccess',
    ],

    /*
    |--------------------------------------------------------------------------
    | Max Upload Size (bytes)
    |--------------------------------------------------------------------------
    */
    'max_upload_size' => (int) env('PANEL_MAX_UPLOAD_SIZE', 10485760), // 10MB

    /*
    |--------------------------------------------------------------------------
    | Artisan Commands (for dropdown suggestions)
    |--------------------------------------------------------------------------
    */
    'suggested_artisan_commands' => [
        'cache:clear', 'config:clear', 'view:clear', 'route:clear', 'event:clear',
        'optimize:clear', 'migrate', 'migrate:fresh', 'migrate:rollback',
        'db:seed', 'queue:restart', 'queue:flush', 'queue:prune-batches',
        'make:seeder', 'make:migration', 'make:model', 'make:controller',
        'key:generate', 'route:list', 'config:cache', 'route:cache',
        'storage:link', 'vendor:publish', 'package:discover',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Discovery Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'discovery_cache_ttl' => 300, // 5 minutes
];
