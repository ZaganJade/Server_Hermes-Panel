<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login()
    {
        return view('panel.login');
    }

    public function authenticate(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $throttleKey = strtolower($request->input('username')) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'username' => [sprintf('Too many login attempts. Please try again in %d seconds.', $seconds)],
            ]);
        }

        $panelUsername = config('panel.username');
        $panelPassword = config('panel.password');

        if (
            $request->input('username') === $panelUsername &&
            $request->input('password') === $panelPassword
        ) {
            RateLimiter::clear($throttleKey);

            $request->session()->put('panel_auth', true);
            $request->session()->put('panel_auth_time', now()->timestamp);

            return redirect()->intended(route('panel.dashboard'));
        }

        RateLimiter::hit($throttleKey, 60);

        throw ValidationException::withMessages([
            'username' => ['Invalid credentials.'],
        ]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget(['panel_auth', 'panel_auth_time', 'active_project', 'query_history']);

        return redirect()->route('panel.login');
    }
}
