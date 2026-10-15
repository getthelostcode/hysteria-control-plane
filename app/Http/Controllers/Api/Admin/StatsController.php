<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\MetricsService;
use App\Services\NodeService;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __invoke(NodeService $nodes, MetricsService $metrics): JsonResponse
    {
        $this->authorize('viewAny', Node::class);

        return response()->json([
            'nodes' => $nodes->stats(),
            'traffic' => $metrics->totals('24h'),
            'commands' => [
                'pending' => NodeCommand::query()->where('status', NodeCommand::STATUS_PENDING)->count(),
                'sent' => NodeCommand::query()->where('status', NodeCommand::STATUS_SENT)->count(),
                'failed' => NodeCommand::query()->where('status', NodeCommand::STATUS_FAILED)->count(),
            ],
            'theme' => [
                'preset' => Setting::get('theme_preset', 'ocean'),
                'primary' => Setting::primaryColor(),
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
