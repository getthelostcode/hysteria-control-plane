<?php

declare(strict_types=1);

use App\Exceptions\ConfigValidationException;
use App\Http\Middleware\AgentAuth;
use App\Http\Middleware\DecodeAgentPayload;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.auth' => AgentAuth::class,
            'agent.decode' => DecodeAgentPayload::class,
        ]);

        // 没有这一段，部署在 Cloudflare Tunnel / nginx 后面时 $request->ip() 拿到的是代理自己的
        // 地址（本机 cloudflared → 127.0.0.1），节点 IP、审计日志的 IP 全都是 127.0.0.1。
        //
        // 注意这里**必须用 env() 而不是 config()**：本闭包在内核解析阶段执行，那时容器里的
        // config 还没就绪，`config()` 会抛「Target class [config] does not exist」，表现是
        // **整个站点 500**（踩过）。默认值与 config/agent.php 保持一致，改一处要改两处。
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '127.0.0.1,::1'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ConfigValidationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'invalid_config',
                    'message' => $exception->getMessage(),
                ], 422);
            }

            return null;
        });

        // API clients (agents, integrations) always get JSON, never a Blade 404
        // page — a wrong path should be a one-line diagnosis, not HTML soup.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'not_found',
                    'message' => 'No API endpoint matches ' . $request->getMethod() . ' ' . $request->getPathInfo(),
                    'documentation' => url('/docs/api.json'),
                ], 404);
            }

            return null;
        });

        $exceptions->render(function (MethodNotAllowedHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'method_not_allowed',
                    'message' => $request->getMethod() . ' is not allowed on ' . $request->getPathInfo(),
                ], 405);
            }

            return null;
        });
    })->create();
