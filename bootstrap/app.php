<?php

use App\Http\Middleware\OwnerAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->alias([
            'owner.access' => OwnerAccess::class,
        ]);
        // Disable CSRF for panel/api AJAX routes
        $middleware->preventRequestForgery(['panel/api/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
