<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\CommandController;
use App\Http\Controllers\Api\Admin\ConfigController;
use App\Http\Controllers\Api\Admin\EnrollTokenController;
use App\Http\Controllers\Api\Admin\MetricsController;
use App\Http\Controllers\Api\Admin\NodeController;
use App\Http\Controllers\Api\Admin\StatsController;
use App\Http\Controllers\Api\Admin\UpgradePackageController;
use Illuminate\Support\Facades\Route;

// Public (token issuing).
Route::post('auth/login', [AuthController::class, 'login'])
    ->withoutMiddleware('auth:sanctum')
    ->middleware('throttle:admin-login')
    ->name('auth.login');

Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

Route::get('stats', StatsController::class)->name('stats');

Route::get('nodes', [NodeController::class, 'index'])->name('nodes.index');
Route::get('nodes/{node}', [NodeController::class, 'show'])->name('nodes.show');
Route::patch('nodes/{node}', [NodeController::class, 'update'])->name('nodes.update');
Route::delete('nodes/{node}', [NodeController::class, 'destroy'])->name('nodes.destroy');
Route::post('nodes/{node}/secret/rotate', [NodeController::class, 'rotate'])->name('nodes.rotate');

Route::get('nodes/{node}/metrics', [MetricsController::class, 'show'])->name('nodes.metrics');
Route::get('metrics/fleet', [MetricsController::class, 'fleet'])->name('metrics.fleet');

Route::post('nodes/{node}/commands', [CommandController::class, 'store'])->name('nodes.commands.store');
Route::post('commands/bulk', [CommandController::class, 'bulk'])->name('commands.bulk');
Route::get('commands', [CommandController::class, 'index'])->name('commands.index');

Route::get('nodes/{node}/configs', [ConfigController::class, 'index'])->name('nodes.configs.index');
Route::post('nodes/{node}/configs', [ConfigController::class, 'store'])->name('nodes.configs.store');
Route::post('nodes/{node}/configs/{version}/rollback', [ConfigController::class, 'rollback'])->name('nodes.configs.rollback');
Route::get('nodes/{node}/configs/{version}/diff', [ConfigController::class, 'diff'])->name('nodes.configs.diff');

Route::get('enroll-tokens', [EnrollTokenController::class, 'index'])->name('enroll-tokens.index');
Route::post('enroll-tokens', [EnrollTokenController::class, 'store'])->name('enroll-tokens.store');
Route::delete('enroll-tokens/{token}', [EnrollTokenController::class, 'destroy'])->name('enroll-tokens.destroy');

Route::get('upgrade-packages', [UpgradePackageController::class, 'index'])->name('upgrade-packages.index');
Route::post('upgrade-packages', [UpgradePackageController::class, 'store'])->name('upgrade-packages.store');
Route::patch('upgrade-packages/{package}', [UpgradePackageController::class, 'update'])->name('upgrade-packages.update');
Route::delete('upgrade-packages/{package}', [UpgradePackageController::class, 'destroy'])->name('upgrade-packages.destroy');
