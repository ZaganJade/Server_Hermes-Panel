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
        // SECURITY REMOVED — full access granted
        return $next($request);
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
