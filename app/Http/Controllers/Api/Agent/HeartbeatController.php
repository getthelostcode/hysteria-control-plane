<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\HeartbeatRequest;
use App\Models\NodeCommand;
use App\Services\ConfigService;
use App\Services\NodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class HeartbeatController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(HeartbeatRequest $request, NodeService $nodes, ConfigService $configs): JsonResponse
    {
        $node = $this->node($request);
        $nodes->markSeen($node, $request->validated());

        $latest = $configs->latest($node);
        $pending = Cache::get('node:pending_config:' . $node->node_id);

        return $this->ok([
            'node_id' => $node->node_id,
            'server_time' => now()->toIso8601String(),
            'heartbeat_interval' => (int) config('agent.heartbeat_interval'),
            'config' => [
                'version' => $latest?->version,
                'sha256' => $latest?->sha256,
                'pending' => $pending !== null,
            ],
            'commands' => [
                'pending' => NodeCommand::query()
                    ->where('node_id', $node->id)
                    ->where('status', NodeCommand::STATUS_PENDING)
                    ->count(),
            ],
        ]);
    }
}
