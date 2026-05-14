<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;

class OwnerAccess
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Check session
        if ($request->session()->has('panel_auth')) {
            $this->refreshSessionTimeout($request);

            return $next($request);
        }

        // 2. Check bypass password (header, query param, or legacy)
        $providedPassword = $request->header('X-Panel-Password')
            ?: $request->get('password', '');

        $bypassPassword = config('panel.bypass_password', '');
        $panelPassword = config('panel.password', '');

        if (($panelPassword && $providedPassword === $panelPassword)
            || ($bypassPassword && $providedPassword === $bypassPassword)
        ) {
            return $next($request);
        }

        // 3. Check WhatsApp sender number
        $senderNumber = $this->getSenderNumber($request);
        $ownerNumbers = config('panel.owner_numbers', []);

        if ($senderNumber && in_array($this->normalizeNumber($senderNumber), $ownerNumbers)) {
            return $next($request);
        }

        // 4. Local environment bypass
        if (App::environment('local')) {
            return $next($request);
        }

        // 5. Redirect to login (web) or return 403 (API)
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['error' => 'Access denied. Authentication required.'], 401);
        }

        return redirect()->route('panel.login');
    }

    private function refreshSessionTimeout(Request $request): void
    {
        $lifetime = config('panel.session_lifetime', 120);
        $authTime = $request->session()->get('panel_auth_time', 0);

        if (now()->timestamp - $authTime > ($lifetime * 60)) {
            $request->session()->forget(['panel_auth', 'panel_auth_time', 'active_project']);
        }
    }

    private function getSenderNumber(Request $request)
    {
        return $request->header('X-WA-Sender') ?: $request->get('sender', '');
    }

    private function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        }

        if (!str_starts_with($number, '62')) {
            $number = '62' . $number;
        }

        return $number;
    }
}
