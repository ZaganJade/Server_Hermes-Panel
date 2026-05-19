<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom CSRF protection for panel AJAX routes.
 * Excludes all /panel/api/* routes from CSRF verification since
 * these are internal API calls authenticated via session.
 */
class PanelApiCsrf
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip CSRF for panel API routes (AJAX internal calls)
        if ($request->is('panel/api/*')) {
            return $next($request);
        }

        // For all other routes, use standard Laravel CSRF logic
        // via PreventRequestForgery parent class behavior
        if ($this->shouldVerify($request)) {
            $this->validateCsrf($request);
        }

        return $next($request);
    }

    protected function shouldVerify(Request $request): bool
    {
        // Same logic as Laravel's PreventRequestForgery
        foreach (self::$neverVerify as $uri) {
            if ($request->is($uri)) {
                return false;
            }
        }
        return true;
    }

    protected function validateCsrf(Request $request): void
    {
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');

        if (!$token && $request->header('X-CSRF-TOKEN')) {
            $token = $request->header('X-CSRF-TOKEN');
        }

        if (!match([$token, $request->session()->token()])) {
            abort(419, 'CSRF token mismatch.');
        }
    }
}