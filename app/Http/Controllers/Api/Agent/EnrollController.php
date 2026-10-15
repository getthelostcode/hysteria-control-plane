<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollRequest;
use App\Models\EnrollToken;
use App\Services\NodeService;
use Illuminate\Http\JsonResponse;

class EnrollController extends Controller
{
    public function __invoke(EnrollRequest $request, NodeService $nodes): JsonResponse
    {
        $plain = (string) $request->string('token');

        $token = EnrollToken::query()
            ->where('token_hash', EnrollToken::hashToken($plain))
            ->first();

        if ($token === null || ! $token->isUsable()) {
            $diag = EnrollToken::diagnose($plain);

            // error 保持稳定（agent/契约按它判永久失败），reason 给运维看具体原因。
            return response()->json([
                'error' => 'invalid_token',
                'reason' => $diag['reason'],
                'message' => $diag['message'],
                'token_prefix' => $diag['prefix'],
            ], 422);
        }

        $result = $nodes->enroll($token, $request->validated(), $request->ip());
        /** @var \App\Models\Node $node */
        $node = $result['node'];

        $base = rtrim((string) config('agent.public_url'), '/');

        return response()->json([
            'node_id' => $node->node_id,
            'secret' => $result['secret'],
            'node' => [
                'name' => $node->name,
                'region' => $node->region,
                'tags' => $node->tags ?? [],
                'status' => $node->status,
            ],
            'signature' => [
                'algorithm' => 'HMAC-SHA256',
                'headers' => [
                    'node' => 'X-Node-Id',
                    'timestamp' => 'X-Timestamp',
                    'nonce' => 'X-Nonce',
                    'signature' => 'X-Node-Signature',
                ],
                'canonical' => 'METHOD\\nPATH\\nsha256(rawBody)\\nX-Timestamp\\nX-Nonce',
                'ttl_seconds' => (int) config('agent.signature_ttl'),
            ],
            'endpoints' => [
                'heartbeat' => $base . '/api/agent/v1/heartbeat',
                'config_latest' => $base . '/api/agent/v1/config/latest',
                'config_ack' => $base . '/api/agent/v1/config/ack',
                'metrics' => $base . '/api/agent/v1/metrics',
                'commands_poll' => $base . '/api/agent/v1/commands/poll',
                'command_ack' => $base . '/api/agent/v1/commands/{command_id}/ack',
                'logs' => $base . '/api/agent/v1/logs',
                'upgrade_check' => $base . '/api/agent/v1/upgrade/check',
                'diagnostics' => $base . '/api/agent/v1/diagnostics',
                'ws_auth' => $base . '/api/agent/v1/broadcasting/auth',
            ],
            'websocket' => [
                'url' => (string) config('agent.websocket_url'),
                'channel' => 'private-node.' . $node->node_id,
                'key' => (string) config('broadcasting.connections.reverb.key'),
            ],
            'heartbeat_interval' => (int) config('agent.heartbeat_interval'),
        ], 201);
    }
}
