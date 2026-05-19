<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Owner-only access guard for the panel.
 *
 * Hermes Panel is built for a single developer managing their own VPS.
 * The safe default is auth ENABLED. Set `PANEL_AUTH_ENABLED=false` only
 * on a trusted network (SSH tunnel / VPN / private LAN) AND only together
 * with `PANEL_DEV_BYPASS=true`. AppServiceProvider refuses to boot in
 * production with auth disabled unless dev-bypass is also set.
 *
 * When auth is enforced, the priority chain is:
 *
 *   1. Active session (`panel_auth`)
 *   2. Header password (`X-Panel-Password`) — header-only, never query
 *   3. WhatsApp sender (`X-WA-Sender` matching `PANEL_OWNER_NUMBERS`)
 *   4. Otherwise — redirect to login (web) or 401 JSON (API)
 */
class OwnerAccess
{
    public function handle(Request $request, Closure $next)
    {
        // Trusted-network mode. Only allowed when explicitly opted in via
        // BOTH PANEL_AUTH_ENABLED=false and PANEL_DEV_BYPASS=true. The
        // production guard in AppServiceProvider stops the panel from
        // booting if auth is off without the dev-bypass flag, so reaching
        // this point in production means the operator made an explicit
        // choice. We still require dev_bypass here as a second gate to
        // avoid silent passthrough on misconfigured envs.
        if (! config('panel.auth_enabled', true) && config('panel.dev_bypass', false)) {
            return $next($request);
        }

        // Refresh expired sessions before evaluating credentials.
        $this->refreshSessionTimeout($request);

        if ($this->hasValidSession($request)) {
            return $next($request);
        }

        if ($this->hasValidHeaderPassword($request)) {
            return $next($request);
        }

        if ($this->hasValidWhatsAppSender($request)) {
            return $next($request);
        }

        return $this->denyResponse($request);
    }

    protected function hasValidSession(Request $request): bool
    {
        return (bool) $request->session()->get('panel_auth', false);
    }

    protected function hasValidHeaderPassword(Request $request): bool
    {
        $expected = (string) config('panel.password', '');

        if ($expected === '') {
            return false;
        }

        // Header-only — we deliberately do NOT accept ?password= from the
        // query string because it leaks into nginx access logs, browser
        // history, the Referer header, and upstream proxy logs.
        $provided = (string) $request->header('X-Panel-Password', '');

        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    protected function hasValidWhatsAppSender(Request $request): bool
    {
        $sender = $this->getSenderNumber($request);

        if ($sender === '') {
            return false;
        }

        $allowed = array_map(
            fn ($n) => $this->normalizeNumber((string) $n),
            (array) config('panel.owner_numbers', [])
        );

        return in_array($this->normalizeNumber($sender), $allowed, true);
    }

    protected function denyResponse(Request $request)
    {
        if ($request->is('panel/api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'Unauthorized',
            ], 401);
        }

        return redirect()->guest(route('panel.login'));
    }

    protected function refreshSessionTimeout(Request $request): void
    {
        $lifetime = (int) config('panel.session_lifetime', 120);
        $authTime = (int) $request->session()->get('panel_auth_time', 0);

        if ($authTime === 0) {
            return;
        }

        if (now()->timestamp - $authTime > ($lifetime * 60)) {
            $request->session()->forget(['panel_auth', 'panel_auth_time', 'active_project']);

            return;
        }

        // Slide the window forward on each authenticated request.
        $request->session()->put('panel_auth_time', now()->timestamp);
    }

    protected function getSenderNumber(Request $request): string
    {
        return (string) ($request->header('X-WA-Sender') ?: $request->input('sender', ''));
    }

    protected function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number) ?? '';

        if ($number === '') {
            return '';
        }

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        if (! str_starts_with($number, '62')) {
            $number = '62'.$number;
        }

        return $number;
    }
}
