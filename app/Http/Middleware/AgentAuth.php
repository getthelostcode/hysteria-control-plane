<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Node;
use App\Services\NodeService;
use App\Support\PublicIp;
use App\Support\SignatureVerifier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HMAC authentication for /api/agent/v1/* (X-Node-Id + X-Node-Signature).
 *
 * Scaling note: the node write (last_seen_at) is throttled to one UPDATE per
 * node per 15s so 5000 nodes heartbeating every 30s stay well within budget.
 */
class AgentAuth
{
    public const ATTRIBUTE = 'agent_node';

    public function handle(Request $request, Closure $next): Response
    {
        $nodeId = (string) $request->header(SignatureVerifier::HEADER_NODE_ID);

        if ($nodeId === '') {
            return $this->deny('missing_node_id', 'X-Node-Id header is required.');
        }

        $node = Node::query()->where('node_id', $nodeId)->first();

        if ($node === null) {
            return $this->deny('unknown_node', 'Unknown node.', 404);
        }

        if ($node->status === Node::STATUS_DISABLED) {
            return $this->deny('node_disabled', 'Node is disabled.', 403);
        }

        $raw = (string) $request->getContent();

        if (strlen($raw) > (int) config('agent.max_payload_bytes', 4194304)) {
            return $this->deny('payload_too_large', 'Payload exceeds the configured limit.', 413);
        }

        if (! SignatureVerifier::verify($request, $node, $raw)) {
            return $this->deny('bad_signature', 'Invalid signature or stale timestamp.', 401);
        }

        $nonce = (string) $request->header(SignatureVerifier::HEADER_NONCE);

        if (! SignatureVerifier::registerNonce($node->node_id, $nonce)) {
            return $this->deny('replayed_nonce', 'Nonce already used.', 409);
        }

        $this->touch($node, $request);

        $request->attributes->set(self::ATTRIBUTE, $node);
        $request->setUserResolver(static fn () => $node);

        return $next($request);
    }

    /**
     * Liveness is recorded here so *every* signed call refreshes last_seen_at,
     * not just /heartbeat. NodeService::markSeen throttles the actual UPDATE to
     * one per node per 15s - that is what keeps 5000 nodes affordable.
     */
    private function touch(Node $node, ?Request $request = null): void
    {
        // 顺带刷新公网地址：节点换 IP（换机房/重拨）后，面板不必等下一次注册。
        // 只有真的解析出公网地址才传，否则 markSeen 会保留原值（心跳里 agent 自报的优先）。
        app(NodeService::class)->markSeen($node, array_filter([
            'ip' => $request === null ? null : PublicIp::fromRequest($request),
        ], static fn ($value) => $value !== null));
    }

    private function deny(string $code, string $message, int $status = 401): JsonResponse
    {
        return response()->json([
            'error' => $code,
            'message' => $message,
        ], $status);
    }
}
