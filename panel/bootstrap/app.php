<?php

use App\Http\Controllers\Api\NodeRegistrationController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // register-hysteria 的 install.sh 固定往 https://<host>/new 上报
            Route::middleware('api')
                ->post('/new', [NodeRegistrationController::class, 'store'])
                ->middleware('throttle:60,1')
                ->name('api.nodes.register');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 面板/接口常部署在 Cloudflare Tunnel 或 nginx 后面，需按可信代理推导真实 IP
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1,::1'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
