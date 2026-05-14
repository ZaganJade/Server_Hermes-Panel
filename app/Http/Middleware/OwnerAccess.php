<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class OwnerAccess
{
    public function handle(Request $request, Closure $next)
    {
        // Get owner numbers from config
        $ownerNumbers = config('panel.owner_numbers', []);
        $bypassPassword = config('panel.bypass_password', '');
        
        // Check if request has password bypass
        $providedPassword = $request->header('X-Panel-Password') ?: $request->get('password', '');
        
        if ($bypassPassword && $providedPassword === $bypassPassword) {
            return $next($request);
        }

        // Check WhatsApp sender if available
        $senderNumber = $this->getSenderNumber($request);
        
        if ($senderNumber && in_array($this->normalizeNumber($senderNumber), $ownerNumbers)) {
            return $next($request);
        }

        // Also check from cookie/session
        $sessionPassword = $request->session()->get('panel_auth');
        if ($sessionPassword === $bypassPassword && $bypassPassword) {
            return $next($request);
        }

        // If in local development, allow
        if (App::environment('local')) {
            return $next($request);
        }

        return response()->json(['error' => 'Access denied. Owner authorization required.'], 403);
    }

    private function getSenderNumber(Request $request)
    {
        // Try WhatsApp gateway header first
        $waSender = $request->header('X-WA-Sender');
        if ($waSender) return $waSender;

        // Try from query params
        return $request->get('sender', '');
    }

    private function normalizeNumber($number)
    {
        // Remove all non-digits
        $number = preg_replace('/[^0-9]/', '', $number);
        
        // If starts with 0, replace with 62
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        }
        
        // If doesn't start with 62, add it
        if (!str_starts_with($number, '62')) {
            $number = '62' . $number;
        }
        
        return $number;
    }
}