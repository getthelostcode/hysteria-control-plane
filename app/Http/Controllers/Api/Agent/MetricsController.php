<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MetricsReportRequest;
use App\Services\MetricsService;
use App\Services\NodeService;
use Illuminate\Http\JsonResponse;

class MetricsController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(MetricsReportRequest $request, MetricsService $metrics): JsonResponse
    {
        $node = $this->node($request);
        $samples = $request->samples();

        $stored = $metrics->ingest($node, $samples);

        // A metric batch is evidence of life: refresh liveness (gated write).
        app(NodeService::class)->forceLiveness($node);

        return response()->json([
            'ok' => true,
            'stored' => $stored,
            'received_at' => now()->toIso8601String(),
        ], 202);
    }
}
