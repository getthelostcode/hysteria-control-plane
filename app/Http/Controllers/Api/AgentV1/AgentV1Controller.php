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
use App\Services\ConfigFileService;
use App\Support\PublicIp;
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
    /**
     * 把节点自报的 trafficStats 值记进 meta（面板渲染配置时用节点真实值）。
     *
     * 只有真的变了才写库：每次心跳都 save 会打破「存活写库按节点 15s 门控」的取舍，
     * 5000 节点规模下就是每 30 秒一次全表写。
     *
     * @param  array<string, string>  $reported
     */
    private function adoptReportedStats(\App\Models\Node $node, array $reported): void
    {
        $meta = $node->meta ?? [];
        $changed = false;

        foreach ($reported as $key => $value) {
            if (($meta[$key] ?? null) !== $value) {
                $meta[$key] = $value;
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $meta['stats_reported_at'] = now()->toIso8601String();
        $node->forceFill(['meta' => $meta])->saveQuietly();
    }

    /**
     * 配置下发（REST 兜底通道）。
     *
     * 本控制面终结不了二进制 WS 控制通道（/control 返回 501），所以直接连控制面的节点
     * 只能靠 REST 拿配置 —— 没有这两个端点时，面板「发布配置」写进库就再无下文
     * （真实现场：所有配置都是 published / applied_at 为空）。
     *
     * 鉴权与 /heartbeat、/token 相同：node_secret 自签的 JWT 或 ?key=<node_secret>。
     */
    public function configLatest(Request $request, ConfigService $configs): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据（Authorization: Bearer <node_secret 自签 JWT> 或 ?key=<node_secret>）。', 401);
        }

        // 拿配置就是「节点活着」的证据：顺带刷新存活（面板的「最后心跳」不应因为走 REST 就停住）
        $this->nodes->markSeen($node, ['observed_ip' => PublicIp::fromRequest($request)]);

        $config = $configs->latest($node);
        $current = $request->integer('current_version', 0);
        // 就地把某个版本的正文改掉时版本号不变 —— 只比版本号会让节点永远拿不到新内容。
        // agent 会带上当前生效配置的 sha256，这里一并比对。
        $currentSha = trim((string) $request->input('current_sha256', ''));

        if ($config === null) {
            return response()->json(['ok' => true, 'up_to_date' => true, 'config' => null]);
        }

        $shaMatches = $currentSha === '' || hash_equals((string) $config->sha256, $currentSha);

        if ($current === (int) $config->version && $shaMatches) {
            return response()->json([
                'ok' => true,
                'up_to_date' => true,
                'config' => ['version' => (int) $config->version, 'sha256' => $config->sha256],
            ]);
        }

        return response()->json([
            'ok' => true,
            'up_to_date' => false,
            'config' => [
                'version' => (int) $config->version,
                'sha256' => $config->sha256,
                'status' => $config->status,
                'revision' => (string) $config->version,
                'content_yaml' => $config->content_yaml,
                'published_at' => $config->published_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * 取「配置版本文件库」的默认版本（与节点无关的那份配置正文）。
     *
     * 与 configLatest 的区别：configLatest 给的是**这台节点**发布的第几版（node_configs），
     * 这里给的是运维在「配置管理 → 版本管理」里指定的**默认版本文件**（config_files.is_default）。
     * agent 的 `config sync` 默认走这条（`control.config_source: default`）。
     */
    public function configDefault(Request $request, ConfigFileService $files): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据（Authorization: Bearer <node_secret 自签 JWT> 或 ?key=<node_secret>）。', 401);
        }

        $this->nodes->markSeen($node, ['observed_ip' => PublicIp::fromRequest($request)]);

        $file = $files->default();

        if ($file === null) {
            return response()->json([
                'ok' => true,
                'up_to_date' => true,
                'config' => null,
                'note' => '配置版本文件库里还没有默认版本。',
            ]);
        }

        $current = $request->integer('current_version', 0);
        $currentSha = trim((string) $request->input('current_sha256', ''));
        $shaMatches = $currentSha === '' || hash_equals((string) $file->sha256, $currentSha);

        if ($current === (int) $file->version && $shaMatches) {
            return response()->json([
                'ok' => true,
                'up_to_date' => true,
                'config' => ['version' => (int) $file->version, 'sha256' => $file->sha256],
            ]);
        }

        return response()->json([
            'ok' => true,
            'up_to_date' => false,
            'config' => [
                'version' => (int) $file->version,
                'sha256' => $file->sha256,
                'revision' => (string) $file->version,
                'content_yaml' => $file->content_yaml,
                'source' => 'config-file',
                'note' => $file->note,
            ],
        ]);
    }

    /** 配置回执（REST 兜底通道）：applied / failed → 与原生通道共用 ConfigService 的判定与回滚。 */
    public function configAck(Request $request, ConfigService $configs): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据。', 401);
        }

        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'max:16'],
            'error' => ['nullable', 'string', 'max:2000'],
            'applied_at' => ['nullable', 'string', 'max:64'],
        ]);

        $config = $configs->acknowledge(
            $node,
            (int) $data['version'],
            (string) $data['status'],
            $data['error'] ?? null,
            ['agent_reported_at' => $data['applied_at'] ?? now()->toIso8601String(), 'channel' => 'rest-fallback'],
        );

        $this->nodes->markSeen($node, ['observed_ip' => PublicIp::fromRequest($request)]);

        return response()->json([
            'ok' => true,
            'version' => (int) $config->version,
            'status' => $config->status,
            'rolled_back' => $config->status === NodeConfig::STATUS_FAILED,
            'active_version' => $configs->latest($node->refresh()) === null ? null : (int) $configs->latest($node)?->version,
        ]);
    }

    /**
     * REST 兜底心跳：控制通道端点不可用（PHP 终结不了二进制 WS）时 agent 会打到这里。
     *
     * 目的只有一个 —— 让面板的存活时间与状态反映真实情况。返回字段与 /token 同构，
     * 便于 agent 在降级模式下继续拿到心跳间隔与待执行指令数。
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据（Authorization: Bearer <node_secret 自签 JWT> 或 ?key=<node_secret>）。', 401);
        }

        $this->nodes->markSeen($node, array_filter([
            // agent 自报的公网地址优先（控制面在隧道后只看到代理地址）
            'public_ip' => $request->input('public_ip'),
            'observed_ip' => PublicIp::fromRequest($request),
            'agent_version' => $request->input('agent_version'),
            'version' => $request->input('hysteria_version'),
        ], static fn ($value) => $value !== null && $value !== ''));

        // 收养节点自报的 trafficStats 值。
        //
        // 为什么必须收养：agent 会校验「下发的 trafficStats.secret == 本机 hysteria.api_secret」，
        // 不一致就**整份拒绝**（否则读不到流量、踢不了人）。面板若写死/写错，配置永远发布不成功
        // —— jp8 上连发四个版本全 failed 就是这么来的。用节点自己的值渲染就没这问题。
        $reported = array_filter([
            'stats_secret' => trim((string) $request->input('traffic_stats_secret', '')),
            'stats_listen' => trim((string) $request->input('traffic_stats_listen', '')),
            // 证书路径也照抄：面板写死的路径在节点上不存在时 agent 会整份拒收（jp8 现场）
            'cert_path' => trim((string) $request->input('cert_path', '')),
            'key_path' => trim((string) $request->input('key_path', '')),
            // 节点**实盘** config.yaml 的身份：版本号 + sha256 + 路径。
            // 节点列表的「配置版本」以它为准 —— 面板的期望版本（node_configs/config_files）只作为参照，
            // 手工改过 / 没同步上的节点必须能一眼看出来。
            'config_version' => trim((string) $request->input('config_version', '')),
            'config_sha256' => trim((string) $request->input('config_sha256', '')),
            'config_path' => trim((string) $request->input('config_path', '')),
        ], static fn ($value) => $value !== '');

        if ($reported !== []) {
            $this->adoptReportedStats($node, $reported);
        }

        $latest = $this->configs->latest($node);

        return response()->json([
            'ok' => true,
            'node_id' => $node->node_id,
            'server_time' => now()->toIso8601String(),
            'heartbeat_interval_seconds' => (int) Setting::get('heartbeat_interval', 30),
            'telemetry_interval_seconds' => 15,
            'config' => [
                'version' => $latest?->version,
                'sha256' => $latest?->sha256,
                'revision' => $latest === null ? null : (string) $latest->version,
            ],
            'commands' => [
                'pending' => \App\Models\NodeCommand::query()
                    ->where('node_id', $node->id)
                    ->where('status', \App\Models\NodeCommand::STATUS_PENDING)
                    ->count(),
            ],
            'note' => 'REST 兜底心跳：本控制面不提供二进制 WS 控制通道。',
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $node = $this->authenticateChannel($request);

        if ($node === null) {
            return $this->error('unauthorized', '缺少或无效的凭据（Authorization: Bearer <JWT signed with node_secret> 或 ?key=<node_secret>）。', 401);
        }

        // 拿到 JWT 本身就是「节点活着且持有密钥」的证据：enroll 后 agent 会立刻做一次
        // JWT 自检，这一步也该刷新存活，否则面板「最后心跳」永远停在注册那一刻。
        $this->nodes->markSeen($node, [
            'ip' => PublicIp::fromRequest($request),
        ]);

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
            $meta['ip_v6'] = $public['ip_v6'];
        }

        // 已有 IPv4 不被新的 IPv6 顶掉（与 NodeService::markSeen 同一策略）：
        // 双栈节点从 v6 出口访问控制面时，观测到的客户端地址是 v6，但面板按 IPv4 展示。
        if (! \App\Support\PublicIp::isIpv4($fill['ip'] ?? $node->ip) && \App\Support\PublicIp::isIpv4($node->ip)) {
            $fill['ip'] = $node->ip;
            $meta['ip_v6'] = $meta['ip_v6'] ?? ($public['ip'] ?? null);
        }

        $fill['meta'] = $meta;

        // 一次成功的重注册（--replace）本身就是「节点活着且持有密钥」的证据：
        // 不刷新存活时间的话，面板「最后心跳」会永远停在最初注册那一刻（真实现场：
        // 节点 12:23 完成重注册，列表仍显示 12:10）。
        $fill['last_seen_at'] = now();
        $fill['status'] = Node::STATUS_ONLINE;

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
