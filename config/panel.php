<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Panel Settings
    |--------------------------------------------------------------------------
    */
    'name' => env('PANEL_NAME', 'Hermes Panel'),
    'env' => env('PANEL_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Owner Numbers
    |--------------------------------------------------------------------------
    | WhatsApp numbers (with country code, no +) that are allowed access.
    | Format: 62895341414271
    */
    'owner_numbers' => explode(',', env('PANEL_OWNER_NUMBERS', '62895341414271')),

    /*
    |--------------------------------------------------------------------------
    | Bypass Password
    |--------------------------------------------------------------------------
    | If set, this password can be used to bypass owner number check.
    | Useful when WhatsApp gateway is not configured.
    */
    'bypass_password' => env('PANEL_BYPASS_PASSWORD', 'hermes-panel-2024'),

    /*
    |--------------------------------------------------------------------------
    | Projects
    |--------------------------------------------------------------------------
    | List of Laravel projects accessible via this panel.
    | Key = project name (used in URL), Value = absolute path to project.
    */
    'projects' => [
        'desakta' => env('PANEL_PROJECT_DESAKTA', '/home/ZaganJade1/Project/E-Archive_DESAKTA'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Project
    |--------------------------------------------------------------------------
    */
    'default_project' => env('PANEL_DEFAULT_PROJECT', 'desakta'),

    /*
    |--------------------------------------------------------------------------
    | Allowed File Extensions for Editing
    |--------------------------------------------------------------------------
    */
    'editable_extensions' => ['php', 'js', 'css', 'blade.php', 'html', 'txt', 'json', 'env', 'md', 'yaml', 'yml', 'xml', 'sql', 'gitignore', 'htaccess'],

    /*
    |--------------------------------------------------------------------------
    | Max Upload Size (bytes)
    |--------------------------------------------------------------------------
    */
    'max_upload_size' => env('PANEL_MAX_UPLOAD_SIZE', 10485760), // 10MB

    /*
    |--------------------------------------------------------------------------
    | Artisan Commands Whitelist
    |--------------------------------------------------------------------------
    */
    'allowed_artisan_commands' => [
        'cache:clear', 'config:clear', 'view:clear', 'route:clear', 'event:clear',
        'optimize:clear', 'migrate', 'migrate:fresh', 'migrate:rollback',
        'db:seed', 'queue:restart', 'queue:flush', 'queue:prune-batches',
        'make:seeder', 'make:migration', 'make:model', 'make:controller',
        'key:generate', 'route:list', 'config:cache', 'route:cache',
        'storage:link', 'vendor:publish', 'package:discover',
        'inspire', 'list', 'help', 'env', 'tinker',
    ],
];