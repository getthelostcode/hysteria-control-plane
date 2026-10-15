<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Node;
use App\Services\NodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Async post-processing of a metric batch: keeps the ingest path thin so a
 * single instance can absorb 5000+ heartbeats and metric reports per interval.
 */
class AggregateMetrics implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $nodeRef, public float $cpu = 0.0)
    {
    }

    public function handle(NodeService $nodes): void
    {
        $node = Node::query()->with('latestMetric')->find($this->nodeRef);

        if ($node === null) {
            return;
        }

        $metric = $node->latestMetric;

        Cache::put('node:summary:' . $node->node_id, [
            'cpu' => $this->cpu,
            'mem' => $metric?->mem,
            'conn_count' => $metric?->conn_count,
            'users' => $metric?->users,
            'net_rx' => $metric?->net_rx,
            'net_tx' => $metric?->net_tx,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(10));

        if ($node->status === Node::STATUS_DISABLED) {
            return;
        }

        if ($this->cpu >= 98.0 && $node->status !== Node::STATUS_ERROR) {
            $nodes->setStatus($node, Node::STATUS_ERROR);
        }
    }
}
