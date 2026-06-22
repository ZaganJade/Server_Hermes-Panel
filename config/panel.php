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
    | WhatsApp Gateway Bypass
    |--------------------------------------------------------------------------
    | The X-WA-Sender header is user-controllable, so on a public domain we
    | only honour it from a known gateway IP and (optionally) when it carries
    | a valid HMAC signature. Defaults to loopback-only.
    |
    | PANEL_GATEWAY_IPS:    comma-separated allowlist of IPs (defaults to
    |                       127.0.0.1 / ::1 when empty)
    | PANEL_GATEWAY_SECRET: HMAC-SHA256 secret. When set, gateways must send
    |                       X-WA-Signature: hex(hmac_sha256(secret, sender))
    */
    'gateway_ips' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PANEL_GATEWAY_IPS', ''))),
        fn ($ip) => $ip !== '',
    )),

    'gateway_secret' => env('PANEL_GATEWAY_SECRET', ''),

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
    | Terminal Command Timeout (seconds)
    |--------------------------------------------------------------------------
    | Wall-clock limit for a single synchronous terminal command before it is
    | killed. Bump this for slow builds/migrations; long-running interactive
    | work should still go through SSH.
    */
    'terminal_timeout' => (int) env('PANEL_TERMINAL_TIMEOUT', 60),

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

    /*
    |--------------------------------------------------------------------------
    | Monitoring (v3.2)
    |--------------------------------------------------------------------------
    | Threshold + service-discovery configuration for the VPS monitoring
    | sub-project. ServiceReader uses these patterns (fnmatch globs) to
    | filter systemctl list-units output and as bases for the pgrep
    | fallback when systemctl is unavailable.
    */
    'monitoring' => [
        'service_patterns' => [
            'nginx*', 'apache2*', 'caddy*', 'traefik*',
            'mysql*', 'mariadb*', 'postgres*', 'postgresql*',
            'redis*', 'memcached*',
            'php-fpm*', 'php*-fpm*',
            'docker', 'containerd', 'podman',
        ],

        'thresholds' => [
            [
                'id' => 'cpu_load',
                'metric' => 'cpu.loadavg.1m',
                'warning_factor_per_core' => 1.5,
                'critical_factor_per_core' => 2.0,
                'sustained_seconds' => 60,
            ],
            [
                'id' => 'mem_used',
                'metric' => 'memory.used_kb',
                'warning_pct' => 90,
                'critical_pct' => 95,
            ],
            [
                'id' => 'disk_used',
                'metric' => 'disk.*.used_pct',
                'warning' => 90,
                'critical' => 95,
            ],
            [
                'id' => 'service_down',
                'metric' => 'services',
                'critical_when' => 'expected_active_but_not',
            ],
        ],
    ],
];
