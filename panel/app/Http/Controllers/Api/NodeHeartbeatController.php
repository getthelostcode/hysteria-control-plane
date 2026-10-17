<?php

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\NodeTrafficDaily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 节点心跳：POST /api/v1/nodes/{node}/heartbeat
 *
 * Authorization: Bearer <node_token>（注册时下发）
 * body: {"version":"v2.13.0","online_users":12,"tx":1024,"rx":2048}
 *   tx/rx 是「本次上报的增量字节」，服务端累加到当天 node_traffic_daily。
 */
class NodeHeartbeatController
{
    public function store(Request $request, Node $node): JsonResponse
    {
        $token = (string) $request->bearerToken();
        $hash = (string) $node->api_token_hash;

        if ($token === '' || $hash === '' || ! hash_equals($hash, hash('sha256', $token))) {
            return response()->json(['ok' => false, 'error' => 'invalid_token'], 401);
        }

        if ($node->isDisabled()) {
            return response()->json(['ok' => false, 'error' => 'node_disabled'], 403);
        }

        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:32'],
            'online_users' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'tx' => ['nullable', 'integer', 'min:0'],
            'rx' => ['nullable', 'integer', 'min:0'],
        ]);

        $tx = (int) ($data['tx'] ?? 0);
        $rx = (int) ($data['rx'] ?? 0);
        $version = $data['version'] ?? null;

        $gateSeconds = (int) config('hysteria.heartbeat_write_gate', 15);
        $ttl = (int) config('hysteria.node_online_ttl', 90);

        // 存活写库节流：窗口内只落一次 last_seen_at；版本变化时立即写
        $gateOpen = Cache::add("node:hb_gate:{$node->id}", 1, $gateSeconds);
        $versionChanged = $version !== null && $version !== $node->version;

        if ($gateOpen || $versionChanged) {
            $node->last_seen_at = now();
            if ($versionChanged) {
                $node->version = $version;
            }
            $node->save();
        }

        // 在线标记副本（给外部消费方：agent / 面板以外的服务）
        Cache::put("node:online:{$node->id}", 1, $ttl);

        if ($tx > 0 || $rx > 0 || isset($data['online_users'])) {
            $row = NodeTrafficDaily::query()->firstOrCreate(
                ['node_id' => $node->id, 'date' => now()->toDateString()],
                ['tx_bytes' => 0, 'rx_bytes' => 0, 'online_users' => 0],
            );

            $changes = [];
            if ($tx > 0) {
                $changes['tx_bytes'] = $tx;
            }
            if ($rx > 0) {
                $changes['rx_bytes'] = $rx;
            }
            if (isset($data['online_users'])) {
                $changes['online_users'] = (int) $data['online_users'];
            }

            if ($changes !== []) {
                $row->incrementEach($changes);
            }
        }

        return response()->json([
            'ok' => true,
            'node_id' => $node->id,
            'server_time' => now()->toIso8601String(),
            'online' => true,
        ]);
    }
}
