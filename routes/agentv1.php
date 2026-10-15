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

/*
| 控制通道不可用时的 REST 兜底心跳。
|
| 本控制面（PHP）终结不了 /control 那个二进制 protobuf WS（返回 501），agent 侧因此会
| 降级到这个端点 —— 否则节点除注册外与控制面再无任何往来，面板「最后心跳」永远停在注册时刻。
| 鉴权与 /control 相同：Authorization: Bearer <node_secret 自签的 JWT> 或 ?key=<node_secret>。
*/
Route::post('heartbeat', [AgentV1Controller::class, 'heartbeat'])
    ->middleware('throttle:agent-v1')
    ->name('heartbeat');

Route::match(['get', 'post'], 'control', [AgentV1Controller::class, 'control'])
    ->middleware('throttle:agent-v1')
    ->name('control');
