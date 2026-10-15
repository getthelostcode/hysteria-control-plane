<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\AgentV1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentV1EnrollRequest;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Models\User;
use App\Services\ConfigService;
use App\Services\NodeService;
use App\Support\AuditLogger;
use App\Support\Jwt;
use App\Support\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * hysteria-agent compatibility surface — `/api/v1/agent/*`.
 *
 * The agent's own DESIGN.md §5.1 fixes this path layout (and it is configurable
 * via `control.enroll_path` / `control.token_path`), while this control plane's
 * native, panel-documented surface is `/api/agent/v1/*`. Both are served by the
 * same services, so an enrolled agent shows up in the panel either way.
 *
 * Field names follow the agent's Go structs exactly (internal/enroll/*.go):
 *   request : enroll_token, node_name, hostname, os, arch, kernel, machine_id,
 *             boot_id, agent_version, hysteria_version, protocol, replace
 *   response: node_id, node_secret, ws_path, pin_sha256, jwt_issuer, jwt_audience,
 *             heartbeat_interval_seconds, telemetry_interval_seconds,
 *             config_revision (string!), message
 */
class AgentV1Controller extends Controller
{
    public const WS_PATH = '/api/v1/agent/control';

    public function __construct(private readonly NodeService $nodes, private readonly ConfigService $configs)
    {
    }

    public function enroll(AgentV1EnrollRequest $request): JsonResponse
    {
        $token = $request->token();

        if ($token === '') {
            return $this->error('invalid_token', 'enroll_token 不能为空（也接受 X-Enroll-Token 头）。', 401);
        }

        $enrollToken = \App\Models\EnrollToken::query()
            ->where('token_hash', \App\Models\EnrollToken::hashToken($token))
            ->first();

        if ($enrollToken === null || ! $enrollToken->isUsable()) {
            $diag = \App\Models\EnrollToken::diagnose($token);

            AuditLogger::log('agent_v1.enroll_rejected', null, [
                'reason' => $diag['reason'],
                'token_prefix' => $diag['prefix'],
                'hostname' => (string) $request->input('hostname', ''),
            ], 'agent', $request->ip());

            // 稳定 code=invalid_token + 可操作的 reason/message（掩码值、已用尽、已过期…）
            return $this->error('invalid_token', $diag['message'], 401, [
                'reason' => $diag['reason'],
                'token_prefix' => $diag['prefix'],
            ]);
        }

        $attributes = $request->agentAttributes();
        $existing = $this->findExisting($attributes);

        if ($existing !== null && ! $request->wantsReplace()) {
            return $this->error(
                'node_exists',
                '该节点已注册（machine_id/hostname 命中）。要重注册请在请求里带 replace: true。',
                409,
                ['node_id' => $existing->node_id, 'node_name' => $existing->name],
            );
        }

        if ($existing !== null) {
            $secret = $this->nodes->rotateSecret($existing, null);
            $this->refreshMetadata($existing, $attributes);
            $enrollToken->consume();

            AuditLogger::log('agent_v1.replaced', $existing, [
                'node_id' => $existing->node_id,
                'machine_id' => $attributes['machine_id'] ?? null,
                'ip' => $request->ip(),
            ], 'agent', $request->ip());

            return $this->payload($existing->refresh(), $secret, '节点已重新注册（replace: true），旧密钥已失效。');
        }

        $result = $this->nodes->enroll($enrollToken, [
            'name' => $attributes['node_name'] ?? 'node-' . strtolower(substr((string) $attributes['machine_id'], 0, 6)),
            'region' => $attributes['region'] ?? null,
            'tags' => $attributes['tags'] ?? null,
            // 这里**不要**再塞 $request->ip()：它是「控制面观测到的对端地址」，由第三个参数
            // 单独传入（并留档在 enrolled_ip）。把观测地址混进「agent 自报」字段，会让
            // PublicIp::resolve() 误判来源，也会把隧道的 127.0.0.1 当成公网地址写进 ip 列。
            'public_ip' => $attributes['public_ip'] ?? null,
            'version' => $attributes['hysteria_version'] ?? null,
            'agent_version' => $attributes['agent_version'] ?? null,
            'arch' => $attributes['arch'] ?? null,
            'kernel' => $attributes['kernel'] ?? null,
        ], $request->ip());

        /** @var Node $node */
        $node = $result['node'];
        $this->refreshMetadata($node, $attributes);

        return $this->payload($node->refresh(), $result['secret'], '注册成功。');
    }

    /**
     * Optional self-check endpoint: verify a channel credential and describe the
     * control channel. The agent signs its JWT locally with node_secret (§3.2),
     * so an Authorization header is verified against that node's secret; the
     * `?key=` form is accepted for manual probes.
     */
    public function token(Request $request): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据（Authorization: Bearer <JWT signed with node_secret> 或 ?key=<node_secret>）。', 401);
        }

        $base = rtrim((string) config('agent.public_url'), '/');
        $latest = $this->configs->latest($node);

        return response()->json([
            'ok' => true,
            'node_id' => $node->node_id,
            'node_name' => $node->name,
            'status' => $node->status,
            'ws_path' => self::WS_PATH,
            // 与 agent 侧的 creds.DefaultIssuer/DefaultAudience 对齐：agent 用它自签
            // 通道 JWT，控制面按同一组值校验，值不一致就是 401。
            'jwt_issuer' => 'hysteria-agent',
            'jwt_audience' => 'hysteria-control',
            'token_ttl_seconds' => 3600,
            'heartbeat_interval_seconds' => (int) Setting::get('heartbeat_interval', 30),
            'telemetry_interval_seconds' => 15,
            'config_revision' => $latest === null ? null : (string) $latest->version,
            'control_channel' => [
                'transport' => 'reverb',
                'note' => 'Control/messaging channel of this plane is Reverb + HMAC REST, not the protobuf Envelope WebSocket.',
                'channel' => 'private-node.' . $node->node_id,
                'reverb_url' => (string) config('agent.websocket_url'),
                'reverb_key' => (string) config('broadcasting.connections.reverb.key'),
                'ws_auth' => $base . '/api/agent/v1/broadcasting/auth',
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
            ],
        ]);
    }

    /**
     * The agent's binary Envelope control channel.
     *
     * It is a long-lived WebSocket (server-driven Ping/Pong, protobuf Envelope
     * frames), which a php-fpm/`artisan serve` stack cannot terminate — the
     * agent's own DESIGN.md §5.1 recommends running the Go control plane for
     * exactly this path. We answer with JSON 501 + the working alternatives
     * instead of a useless HTML 404.
     */
    public function control(Request $request): JsonResponse
    {
        $node = $this->authenticateChannel($request);
        $base = rtrim((string) config('agent.public_url'), '/');

        return response()->json([
            'error' => 'control_channel_not_implemented',
            'message' => 'protobuf Envelope WebSocket 控制通道未在 Laravel 控制面实现（本面用 Reverb 长连接 + HMAC 签名 REST）。',
            'node_id' => $node?->node_id,
            'ws_path' => self::WS_PATH,
            'alternatives' => [
                'rest' => [
                    'heartbeat' => $base . '/api/agent/v1/heartbeat',
                    'config_latest' => $base . '/api/agent/v1/config/latest',
                    'commands_poll' => $base . '/api/agent/v1/commands/poll',
                    'metrics' => $base . '/api/agent/v1/metrics',
                    'auth' => 'X-Node-Id + X-Timestamp + X-Nonce + X-Node-Signature (HMAC-SHA256)',
                ],
                'reverb' => [
                    'channel' => $node === null ? 'private-node.{node_id}' : 'private-node.' . $node->node_id,
                    'url' => (string) config('agent.websocket_url'),
                    'auth_endpoint' => $base . '/api/agent/v1/broadcasting/auth',
                ],
                'go_control_plane' => [
                    'hint' => '让 Go 控制面独占 /api/v1/agent/*（含该 WS），Laravel 面板继续做 UI/审计，二者共享 MySQL。',
                ],
            ],
        ], 501);
    }

    /** @param array<string, mixed> $attributes */
    private function findExisting(array $attributes): ?Node
    {
        $machineId = $attributes['machine_id'] ?? null;

        if (is_string($machineId) && $machineId !== '') {
            $byMachine = Node::query()->where('meta->machine_id', $machineId)->first();

            if ($byMachine !== null) {
                return $byMachine;
            }
        }

        $name = $attributes['node_name'] ?? null;

        if (is_string($name) && $name !== '') {
            return Node::query()->where('name', $name)->first();
        }

        return null;
    }

    /** @param array<string, mixed> $attributes */
    private function refreshMetadata(Node $node, array $attributes): void
    {
        $meta = $node->meta ?? [];

        foreach (['machine_id', 'boot_id', 'os', 'arch', 'kernel', 'protocol'] as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== '') {
                $meta[$key] = $attributes[$key];
            }
        }

        $meta['enroll_contract'] = 'agent-v1';
        $meta['enrolled_at'] = now()->toIso8601String();

        $public = \App\Support\PublicIp::resolve(
            $attributes['public_ip'] ?? null,
            \App\Support\PublicIp::fromRequest(request()),
        );

        $fill = [
            'meta' => $meta,
            'agent_version' => $attributes['agent_version'] ?? $node->agent_version,
            'version' => $attributes['hysteria_version'] ?? $node->version,
        ];

        // 重新注册（--replace）往往正是为了修 IP：能解析出地址就刷新，解析不出来**保持原值**
        // （曾经这里无条件写入，把内网节点的 ip 冲成了 null）。
        if ($public['ip'] !== null) {
            $fill['ip'] = $public['ip'];
            $meta['ip_source'] = $public['source'];
            $fill['meta'] = $meta;
        }

        $node->forceFill($fill)->save();
    }

    private function authenticateChannel(Request $request): ?Node
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer !== '') {
            $node = Jwt::subjectNode($bearer);

            if ($node !== null && Jwt::decode($bearer, $node->signingSecrets()) !== null) {
                return $node;
            }

            return null;
        }

        $key = (string) ($request->query('key') ?? $request->input('key') ?? '');
        $nodeId = (string) ($request->header('X-Node-Id') ?? $request->query('node_id') ?? '');

        if ($key === '') {
            return null;
        }

        $candidates = $nodeId !== ''
            ? Node::query()->where('node_id', $nodeId)->get()
            : Node::query()->where('secret_hash', hash('sha256', $key))->get();

        foreach ($candidates as $candidate) {
            foreach ($candidate->signingSecrets() as $secret) {
                if (hash_equals($secret, $key)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function payload(Node $node, string $secret, string $message): JsonResponse
    {
        $latest = $this->configs->latest($node);

        return response()->json([
            'node_id' => $node->node_id,
            'node_secret' => $secret,
            'node_name' => $node->name,
            'control_url' => rtrim((string) config('agent.public_url'), '/'),
            'ws_path' => self::WS_PATH,
            'pin_sha256' => [],
            // 见上面 /token 的说明：这组值必须与 agent 侧的 creds.Default* 一致。
            'jwt_issuer' => 'hysteria-agent',
            'jwt_audience' => 'hysteria-control',
            'heartbeat_interval_seconds' => (int) Setting::get('heartbeat_interval', 30),
            'telemetry_interval_seconds' => 15,
            // Go type is string — must not be sent as an integer.
            'config_revision' => $latest === null ? '0' : (string) $latest->version,
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'error' => $code,
            'message' => $message,
        ], $extra), $status);
    }
}
