<?php

namespace App\Http\Controllers\Api;

use App\Models\Node;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 节点注册入口：POST /new
 *
 * 由 register-hysteria/install.sh 在安装成功后调用：
 *   {"ip":"1.2.3.4","obfs_password":"<运营商识别码>","traffic_port":12345,"traffic_secret":"<hex>"}
 *
 * 语义：obfs_password 就是服务商的「运营商识别码」，用它定位 provider；
 * 同一 provider 下按 ip upsert，重复注册只会刷新端口/secret 并重新签发 token。
 */
class NodeRegistrationController
{
    public function store(Request $request): JsonResponse
    {
        $shared = (string) config('hysteria.register_shared_secret');
        if ($shared !== '') {
            $given = (string) $request->header('X-Register-Secret', '');
            if (! hash_equals($shared, $given)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'unauthorized',
                    'message' => '缺少或错误的 X-Register-Secret 头',
                ], 401);
            }
        }

        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'obfs_password' => ['required', 'string', 'min:4', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'traffic_port' => ['required', 'integer', 'between:1,65535'],
            'traffic_secret' => ['required', 'string', 'min:8', 'max:128'],
            'hostname' => ['nullable', 'string', 'max:190'],
            'version' => ['nullable', 'string', 'max:32'],
        ]);

        $provider = Provider::query()->where('code', $data['obfs_password'])->first();
        if (! $provider) {
            return response()->json([
                'ok' => false,
                'error' => 'unknown_code',
                'message' => '运营商识别码未登记，请先在平台后台创建服务商',
            ], 422);
        }

        if (! $provider->isActive()) {
            return response()->json([
                'ok' => false,
                'error' => 'provider_suspended',
                'message' => '该服务商已被停用',
            ], 403);
        }

        $node = Node::query()->firstOrNew([
            'provider_id' => $provider->id,
            'ip' => $data['ip'],
        ]);

        $isNew = ! $node->exists;

        $node->fill([
            'ip' => $data['ip'],
            'obfs_password' => $data['obfs_password'],
            'traffic_port' => $data['traffic_port'],
            'traffic_secret' => $data['traffic_secret'],
            'hostname' => $data['hostname'] ?? $node->hostname,
            'version' => $data['version'] ?? $node->version,
            'last_seen_at' => now(),
        ]);

        if ($isNew) {
            $node->name = $data['hostname'] ?: $data['ip'];
            $node->status = Node::STATUS_ACTIVE;
        }

        $node->save();

        // 每次注册重新签发：旧 token 立即失效；明文只在此响应里出现一次
        $token = $node->issueApiToken();

        return response()->json([
            'ok' => true,
            'node_id' => $node->id,
            'provider' => $provider->code,
            'status' => $node->status,
            'node_token' => $token,
            'heartbeat_url' => url("/api/v1/nodes/{$node->id}/heartbeat"),
        ], $isNew ? 201 : 200);
    }
}
