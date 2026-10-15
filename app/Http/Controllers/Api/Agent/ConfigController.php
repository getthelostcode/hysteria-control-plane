<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ConfigAckRequest;
use App\Services\ConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConfigController extends Controller
{
    use InteractsWithAgent;

    public function latest(Request $request, ConfigService $configs): JsonResponse
    {
        $node = $this->node($request);
        $config = $configs->latest($node);

        if ($config === null) {
            return $this->ok(['config' => null, 'up_to_date' => true]);
        }

        $current = $request->integer('current_version', 0);

        if ($current === $config->version) {
            return $this->ok([
                'up_to_date' => true,
                'config' => [
                    'version' => $config->version,
                    'sha256' => $config->sha256,
                ],
            ]);
        }

        return $this->ok([
            'up_to_date' => false,
            'config' => [
                'version' => $config->version,
                'sha256' => $config->sha256,
                'status' => $config->status,
                'content_yaml' => $config->content_yaml,
                'published_at' => $config->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function ack(ConfigAckRequest $request, ConfigService $configs): JsonResponse
    {
        $node = $this->node($request);
        $data = $request->validated();

        $config = $configs->acknowledge(
            $node,
            (int) $data['version'],
            (string) $data['status'],
            $data['error'] ?? null,
            ['agent_reported_at' => $data['applied_at'] ?? now()->toIso8601String()],
        );

        Cache::forget('node:pending_config:' . $node->node_id);
        $node->refresh();

        return $this->ok([
            'version' => $config->version,
            'status' => $config->status,
            'rolled_back' => $config->status === 'failed',
            'active_version' => $configs->latest($node)?->version,
        ]);
    }
}
