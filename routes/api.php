<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
| Two surfaces:
|   /api/agent/v1/*  HMAC signed, used by hysteria-agent nodes
|   /api/admin/v1/*  Sanctum tokens, used by the admin UI / integrations
*/

Route::prefix('agent/v1')
    ->name('agent.')
    ->group(base_path('routes/agent.php'));

Route::prefix('admin/v1')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'throttle:admin-api'])
    ->group(base_path('routes/admin.php'));
