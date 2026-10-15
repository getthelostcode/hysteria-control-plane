<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\DiagnosticsRequest;
use App\Models\NodeLog;
use Illuminate\Http\JsonResponse;

class DiagnosticsController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(DiagnosticsRequest $request): JsonResponse
    {
        $node = $this->node($request);
        $payload = $request->diagnostics();

        NodeLog::query()->create([
            'node_id' => $node->id,
            'level' => 'diagnostic',
            'message' => (string) ($payload['summary'] ?? 'diagnostics report'),
            'context' => $payload,
            'recorded_at' => now(),
        ]);

        $meta = $node->meta ?? [];
        $meta['diagnostics'] = [
            'at' => now()->toIso8601String(),
            'payload' => $payload,
        ];

        $node->forceFill(['meta' => $meta, 'last_seen_at' => now()])->save();

        return response()->json(['ok' => true, 'received_at' => now()->toIso8601String()], 202);
    }
}
