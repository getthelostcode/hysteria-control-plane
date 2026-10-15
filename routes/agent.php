<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Agent\BroadcastingController;
use App\Http\Controllers\Api\Agent\CommandController;
use App\Http\Controllers\Api\Agent\ConfigController;
use App\Http\Controllers\Api\Agent\DiagnosticsController;
use App\Http\Controllers\Api\Agent\EnrollController;
use App\Http\Controllers\Api\Agent\HeartbeatController;
use App\Http\Controllers\Api\Agent\LogController;
use App\Http\Controllers\Api\Agent\MetricsController;
use App\Http\Controllers\Api\Agent\UpgradeController;
use Illuminate\Support\Facades\Route;

Route::get('ping', static fn () => response()->json([
    'pong' => true,
    'service' => 'hysteria-control-plane',
    'time' => now()->toIso8601String(),
]));

Route::post('enroll', EnrollController::class)
    ->middleware('throttle:agent-enroll')
    ->name('enroll');

Route::middleware(['agent.auth', 'agent.decode', 'throttle:agent'])->group(function (): void {
    Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
    Route::get('config/latest', [ConfigController::class, 'latest'])->name('config.latest');
    Route::post('config/ack', [ConfigController::class, 'ack'])->name('config.ack');
    Route::post('metrics', MetricsController::class)->name('metrics');
    Route::post('commands/poll', [CommandController::class, 'poll'])->name('commands.poll');
    Route::post('commands/{commandId}/ack', [CommandController::class, 'ack'])->name('commands.ack');
    Route::post('logs', LogController::class)->name('logs');
    Route::get('upgrade/check', UpgradeController::class)->name('upgrade.check');
    Route::post('diagnostics', DiagnosticsController::class)->name('diagnostics');
    Route::post('broadcasting/auth', BroadcastingController::class)->name('broadcasting.auth');
});
