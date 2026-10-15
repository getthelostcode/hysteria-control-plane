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
