<?php

use App\Http\Controllers\Api\NodeHeartbeatController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/nodes/{node}/heartbeat', [NodeHeartbeatController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('api.nodes.heartbeat');
