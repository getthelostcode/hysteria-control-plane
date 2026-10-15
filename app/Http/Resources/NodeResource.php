<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->node_id,
            'name' => $this->name,
            'status' => $this->status,
            'is_online' => $this->isOnline(),
            'ip' => $this->ip,
            'region' => $this->region,
            'tags' => $this->tags ?? [],
            'version' => $this->version,
            'agent_version' => $this->agent_version,
            // 通道/契约元数据（e.g. protocol=bridge/v1 表示经 Go 控制面桥接接入）。
            // 过滤掉疑似凭据的键：meta 是 agent 上报的自由结构，历史上被 seeder 塞过 demo secret。
            'meta' => $this->publicMeta(),
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'config_version' => $this->whenLoaded('latestConfig', fn () => $this->latestConfig?->version),
            'latest_metric' => $this->whenLoaded('latestMetric', fn () => $this->latestMetric === null ? null : [
                'cpu' => $this->latestMetric->cpu,
                'mem' => $this->latestMetric->mem,
                'disk' => $this->latestMetric->disk,
                'net_rx' => $this->latestMetric->net_rx,
                'net_tx' => $this->latestMetric->net_tx,
                'conn_count' => $this->latestMetric->conn_count,
                'users' => $this->latestMetric->users,
                'recorded_at' => $this->latestMetric->recorded_at?->toIso8601String(),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
