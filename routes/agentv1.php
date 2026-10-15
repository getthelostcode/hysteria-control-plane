<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AgentV1\AgentV1Controller;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| hysteria-agent compatibility surface (/api/v1/agent/*)
|--------------------------------------------------------------------------
| Path layout fixed by the agent's DESIGN.md §5.1 / deploy/config.yaml
| (control.enroll_path, control.token_path, control.ws_path). The native
| surface stays /api/agent/v1/*; both share the same services and tables, so
| the paths can be re-pointed freely from the agent config.
*/

Route::post('enroll', [AgentV1Controller::class, 'enroll'])
    ->middleware('throttle:agent-enroll')
    ->name('enroll');

Route::get('token', [AgentV1Controller::class, 'token'])
    ->middleware('throttle:agent-v1')
    ->name('token');

Route::match(['get', 'post'], 'control', [AgentV1Controller::class, 'control'])
    ->middleware('throttle:agent-v1')
    ->name('control');
