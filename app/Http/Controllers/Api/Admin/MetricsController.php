<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function show(Request $request, Node $node, MetricsService $metrics): JsonResponse
    {
        $this->authorize('view', $node);

        $range = (string) $request->query('range', '1h');

        return response()->json([
            'node_id' => $node->node_id,
            'range' => $range,
            'series' => $metrics->series($node, $range, (int) $request->integer('points', 60)),
        ]);
    }

    public function fleet(Request $request, MetricsService $metrics): JsonResponse
    {
        $this->authorize('viewAny', Node::class);

        $range = (string) $request->query('range', '1h');

        return response()->json([
            'range' => $range,
            'totals' => $metrics->totals($range),
            'series' => $metrics->fleetSeries($range, (int) $request->integer('points', 60)),
        ]);
    }
}
