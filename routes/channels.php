<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Channel auth callbacks for the v3.1 terminal and v3.2 monitoring
| sub-projects. Both follow the same gate logic:
|
|   - Trusted-network bypass mode (auth_enabled=false AND dev_bypass=true)
|     → reject. Clients fall back to HTTP poll (/api/.../snapshot for
|     monitoring, /api/.../execute-sync for terminal).
|   - Otherwise → require an active `panel_auth` session.
|
| The single-user panel doesn't have per-user channels, so any
| authenticated session can subscribe. We return presence-style data
| so the client knows it's authorised.
|
*/

Broadcast::channel('terminal.{project}', function ($user, $project) {
    if (! config('panel.auth_enabled', true) && config('panel.dev_bypass', false)) {
        return false;
    }

    if (! session('panel_auth')) {
        return false;
    }

    return ['user' => 'panel', 'project' => $project];
});

Broadcast::channel('monitoring.host', function ($user) {
    if (! config('panel.auth_enabled', true) && config('panel.dev_bypass', false)) {
        return false;
    }

    if (! session('panel_auth')) {
        return false;
    }

    return ['user' => 'panel'];
});
