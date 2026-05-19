<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Real-time terminal channel auth (story v3.1-05).
|
| Terminal real-time output streams via the private channel
| `terminal.{project}`. The channel callback enforces:
|
|   - Trusted-network bypass mode → reject (no session = no real-time
|     terminal; clients fall back to /panel/api/terminal/execute-sync).
|   - Otherwise → require an active `panel_auth` session.
|
| The single-user panel doesn't have per-user channels, so any
| authenticated session can subscribe to any project's stream. We still
| return presence-style data so the client knows it's authorised.
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
