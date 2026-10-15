<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\EnrollToken;
use App\Models\Node;
use App\Support\Jwt;
use App\Support\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

/**
 * Contract of the real hysteria-agent client (DESIGN.md §5.1 + its Go structs):
 *   POST /api/v1/agent/enroll  {enroll_token, hostname, os, arch, machine_id, ...}
 *   GET  /api/v1/agent/token   (self-check; may 404)
 *   GET  /api/v1/agent/control (binary Envelope WebSocket)
 */
class AgentV1CompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    /** @return array<string, mixed> */
    private function agentPayload(string $token, array $overrides = []): array
    {
        return array_merge([
            'enroll_token' => $token,
            'hostname' => 'hk-edge-1',
            'os' => 'linux',
            'arch' => 'amd64',
            'kernel' => '6.17.2-1-pve',
            'machine_id' => 'a1b2c3d4e5f6',
            'boot_id' => '9f8e7d6c',
            'agent_version' => '1.0.0',
            'hysteria_version' => '2.6.0',
            'protocol' => '1',
        ], $overrides);
    }

    public function test_agent_enroll_contract_succeeds_and_returns_the_expected_fields(): void
    {
        [, $plain] = EnrollToken::issue(['note' => 'agent-v1', 'max_uses' => 1]);

        $response = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain))
            ->assertOk()
            ->assertJsonStructure([
                'node_id', 'node_secret', 'node_name', 'ws_path', 'pin_sha256',
                'jwt_issuer', 'jwt_audience', 'heartbeat_interval_seconds',
                'telemetry_interval_seconds', 'config_revision', 'message',
            ]);

        $this->assertStringStartsWith('nd_', $response->json('node_id'));
        $this->assertStringStartsWith('nds_', $response->json('node_secret'));
        // config_revision is a Go string, not an int.
        $this->assertIsString($response->json('config_revision'));
        $this->assertSame('/api/v1/agent/control', $response->json('ws_path'));

        $node = Node::query()->where('node_id', $response->json('node_id'))->firstOrFail();
        $this->assertSame('hk-edge-1', $node->name);
        $this->assertSame('a1b2c3d4e5f6', $node->meta['machine_id']);
        $this->assertSame('2.6.0', $node->version);
        $this->assertSame('1.0.0', $node->agent_version);
        $this->assertSame('agent-v1', $node->meta['enroll_contract']);

        // The issued secret is immediately usable on the native signed API.
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '2.6.0'])->assertOk();

        // ...and the one-shot token is spent.
        $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain))->assertStatus(401);
    }

    public function test_token_may_come_from_the_x_enroll_token_header(): void
    {
        [, $plain] = EnrollToken::issue();

        $this->postJson('/api/v1/agent/enroll', ['hostname' => 'sg-edge-1', 'arch' => 'arm64'], [
            'X-Enroll-Token' => $plain,
        ])->assertOk()->assertJsonPath('node_name', 'sg-edge-1');
    }

    public function test_invalid_expired_and_exhausted_tokens_are_401(): void
    {
        $this->postJson('/api/v1/agent/enroll', ['hostname' => 'x'])->assertStatus(401)
            ->assertJsonPath('error', 'invalid_token');

        $this->postJson('/api/v1/agent/enroll', $this->agentPayload('enr_nope'))->assertStatus(401);

        [, $expired] = EnrollToken::issue(['expires_at' => now()->subDay()]);
        $this->postJson('/api/v1/agent/enroll', $this->agentPayload($expired))->assertStatus(401);

        [, $used] = EnrollToken::issue(['max_uses' => 1, 'uses' => 1]);
        $this->postJson('/api/v1/agent/enroll', $this->agentPayload($used))->assertStatus(401);

        $this->assertSame(0, Node::query()->count());
    }

    public function test_duplicate_machine_id_is_409_and_replace_true_reissues_credentials(): void
    {
        [, $first] = EnrollToken::issue();
        $created = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($first))->assertOk();
        $nodeId = $created->json('node_id');
        $oldSecret = $created->json('node_secret');

        [, $second] = EnrollToken::issue();

        // Second enroll for the same machine: refused with the existing node id.
        $this->postJson('/api/v1/agent/enroll', $this->agentPayload($second))
            ->assertStatus(409)
            ->assertJsonPath('error', 'node_exists')
            ->assertJsonPath('node_id', $nodeId);

        $this->assertSame(1, Node::query()->count());

        // replace: true reissues the secret and invalidates the old one.
        $replaced = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($second, ['replace' => true]))
            ->assertOk()
            ->assertJsonPath('node_id', $nodeId);

        $newSecret = $replaced->json('node_secret');
        $this->assertNotSame($oldSecret, $newSecret);

        $node = Node::query()->where('node_id', $nodeId)->firstOrFail();
        $this->assertSame($newSecret, $node->plainSecret());
        $this->assertFalse(SignatureVerifier::verify(
            \Illuminate\Http\Request::create('/api/agent/v1/heartbeat', 'POST'),
            $node,
            '',
            'deadbeef',
        ));
    }

    public function test_token_self_check_verifies_a_locally_signed_jwt(): void
    {
        [, $plain] = EnrollToken::issue();
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain))->assertOk();

        $nodeId = $enrolled->json('node_id');
        $secret = $enrolled->json('node_secret');

        // The agent signs locally: HMAC key = node_secret, subject = node_id.
        $jwt = Jwt::encode([
            'iss' => 'hysteria-agent',
            'aud' => 'hysteria-control-plane',
            'sub' => $nodeId,
            'iat' => now()->getTimestamp(),
            'exp' => now()->addHour()->getTimestamp(),
        ], $secret);

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->getJson('/api/v1/agent/token')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('node_id', $nodeId)
            ->assertJsonPath('ws_path', '/api/v1/agent/control')
            ->assertJsonStructure(['control_channel' => ['channel', 'reverb_url', 'ws_auth'], 'endpoints' => ['heartbeat']]);

        // Wrong signing key -> 401.
        $forged = Jwt::encode(['sub' => $nodeId, 'exp' => now()->addHour()->getTimestamp()], 'nds_forged');
        $this->withHeaders(['Authorization' => 'Bearer ' . $forged])
            ->getJson('/api/v1/agent/token')
            ->assertStatus(401);

        // Expired token -> 401.
        $stale = Jwt::encode(['sub' => $nodeId, 'exp' => now()->subMinute()->getTimestamp()], $secret);
        $this->withHeaders(['Authorization' => 'Bearer ' . $stale])
            ->getJson('/api/v1/agent/token')
            ->assertStatus(401);
    }

    public function test_token_self_check_accepts_the_raw_secret_for_manual_probes(): void
    {
        [, $plain] = EnrollToken::issue();
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain))->assertOk();

        $this->getJson('/api/v1/agent/token?key=' . $enrolled->json('node_secret'))
            ->assertOk()
            ->assertJsonPath('node_id', $enrolled->json('node_id'));
    }

    public function test_control_channel_reports_json_501_with_working_alternatives(): void
    {
        [, $plain] = EnrollToken::issue();
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain))->assertOk();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . Jwt::encode(['sub' => $enrolled->json('node_id')], $enrolled->json('node_secret')),
            'Upgrade' => 'websocket',
        ])->getJson('/api/v1/agent/control');

        $response->assertStatus(501)
            ->assertJsonPath('error', 'control_channel_not_implemented')
            ->assertJsonStructure(['alternatives' => ['rest', 'reverb', 'go_control_plane']]);
    }

    public function test_unknown_api_paths_answer_json_not_html(): void
    {
        $response = $this->get('/api/v1/agent/does-not-exist');

        $response->assertStatus(404);
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('error', 'not_found');

        // A wrong method on a real endpoint is JSON too.
        $this->postJson('/api/agent/v1/ping')->assertStatus(405)->assertJsonPath('error', 'method_not_allowed');
    }

    public function test_rest_fallback_heartbeat_records_liveness_and_versions(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);

        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'rest-fallback-1',
            'machine_id' => 'machine-rest-fallback',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();

        // 模拟「节点注册完就再没联系过」：存活时间停在过去，状态离线
        $node->forceFill([
            'status' => \App\Models\Node::STATUS_OFFLINE,
            'last_seen_at' => now()->subHour(),
        ])->save();

        $jwt = Jwt::encode(['sub' => $node->node_id, 'exp' => now()->addHour()->getTimestamp()], $node->plainSecret());

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->postJson('/api/v1/agent/heartbeat', [
                'agent_version' => '0.1.0',
                'hysteria_version' => '2.6.0',
                'hysteria_running' => true,
                'ready' => true,
                'degraded' => true,
                'public_ip' => '203.0.113.9',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('node_id', $node->node_id)
            ->assertJsonStructure(['heartbeat_interval_seconds', 'config' => ['version'], 'commands' => ['pending']]);

        $node->refresh();

        // 这就是用户看到的问题：面板「最后心跳」不动。REST 兜底心跳必须把它推到现在。
        $this->assertTrue($node->last_seen_at->greaterThan(now()->subMinute()), 'last_seen_at 应刷新到现在');
        $this->assertSame(\App\Models\Node::STATUS_ONLINE, $node->status);
        $this->assertSame('203.0.113.9', $node->ip);
        $this->assertSame('2.6.0', $node->version);
        $this->assertSame('0.1.0', $node->agent_version);
    }

    public function test_rest_fallback_heartbeat_rejects_bad_credentials(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'rest-fallback-2',
            'machine_id' => 'machine-rest-fallback-2',
        ]))->assertOk();

        $this->postJson('/api/v1/agent/heartbeat', ['agent_version' => '0.1.0'])
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthorized');

        $forged = Jwt::encode([
            'sub' => $enrolled->json('node_id'),
            'exp' => now()->addHour()->getTimestamp(),
        ], 'nds_wrong_secret');

        $this->withHeaders(['Authorization' => 'Bearer ' . $forged])
            ->postJson('/api/v1/agent/heartbeat', ['agent_version' => '0.1.0'])
            ->assertStatus(401);
    }

    public function test_replace_re_enroll_refreshes_liveness(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);

        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'replace-liveness',
            'machine_id' => 'machine-replace-liveness',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();
        $node->forceFill(['status' => \App\Models\Node::STATUS_OFFLINE, 'last_seen_at' => now()->subDay()])->save();

        [$token2, $plain2] = \App\Models\EnrollToken::issue([]);
        $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain2, [
            'hostname' => 'replace-liveness',
            'machine_id' => 'machine-replace-liveness',
            'replace' => true,
        ]))->assertOk();

        $node->refresh();

        // 重注册是「节点活着」的证据，必须刷新存活（真实现场：12:23 重注册，列表仍显示 12:10）
        $this->assertSame(\App\Models\Node::STATUS_ONLINE, $node->status);
        $this->assertTrue($node->last_seen_at->greaterThan(now()->subMinute()));
    }

    /** 面板「发布配置」必须能被直接连控制面的节点取走（原来没有这条路径，配置永远是 published）。 */
    public function test_rest_config_delivery_and_ack(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);

        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'rest-config-1',
            'machine_id' => 'machine-rest-config-1',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();
        $jwt = Jwt::encode(['sub' => $node->node_id, 'exp' => now()->addHour()->getTimestamp()], $node->plainSecret());

        // 还没发布过配置：明确回 up_to_date，节点不该去应用空配置
        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->getJson('/api/v1/agent/config/latest?current_version=0')
            ->assertOk()
            ->assertJsonPath('up_to_date', true)
            ->assertJsonPath('config', null);

        $published = app(\App\Services\ConfigService::class)->publish($node, "listen: :443\n");

        // 发布后：节点能拿到正文与 sha256
        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->getJson('/api/v1/agent/config/latest?current_version=0')
            ->assertOk()
            ->assertJsonPath('up_to_date', false)
            ->assertJsonPath('config.version', $published->version)
            ->assertJsonPath('config.sha256', $published->sha256)
            ->assertJsonPath('config.content_yaml', "listen: :443\n");

        // 已经是最新版本：不再重复下发
        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->getJson('/api/v1/agent/config/latest?current_version=' . $published->version)
            ->assertOk()
            ->assertJsonPath('up_to_date', true);

        // 回执 applied → 面板状态跟着变，并刷新存活
        $node->forceFill(['last_seen_at' => now()->subHour(), 'status' => \App\Models\Node::STATUS_OFFLINE])->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->postJson('/api/v1/agent/config/ack', [
                'version' => $published->version,
                'status' => 'applied',
                'applied_at' => now()->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('rolled_back', false)
            ->assertJsonPath('active_version', $published->version);

        $node->refresh();
        $this->assertSame('applied', $published->refresh()->status);
        $this->assertNotNull($published->applied_at);
        $this->assertSame(\App\Models\Node::STATUS_ONLINE, $node->status);
        $this->assertTrue($node->last_seen_at->greaterThan(now()->subMinute()));
    }

    public function test_rest_config_ack_failure_rolls_back(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'rest-config-2', 'machine_id' => 'machine-rest-config-2',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();
        $jwt = Jwt::encode(['sub' => $node->node_id, 'exp' => now()->addHour()->getTimestamp()], $node->plainSecret());

        $configs = app(\App\Services\ConfigService::class);
        $v1 = $configs->publish($node, "listen: :443\n");
        $v2 = $configs->publish($node, "listen: :8443\n");

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->postJson('/api/v1/agent/config/ack', [
                'version' => $v2->version, 'status' => 'failed', 'error' => '端口被占用',
            ])
            ->assertOk()
            ->assertJsonPath('rolled_back', true);

        $this->assertSame('failed', $v2->refresh()->status);
        $this->assertSame('端口被占用', $v2->error);
    }

    public function test_rest_config_endpoints_require_credentials(): void
    {
        $this->getJson('/api/v1/agent/config/latest')->assertStatus(401)->assertJsonPath('error', 'unauthorized');
        $this->postJson('/api/v1/agent/config/ack', ['version' => 1, 'status' => 'applied'])
            ->assertStatus(401)->assertJsonPath('error', 'unauthorized');
    }

    /** 节点上报的现状（证书路径 / trafficStats）必须被收养：面板写死错值会让 agent 整份拒收配置。 */
    public function test_heartbeat_adopts_reported_hysteria_shape(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'shape-1', 'machine_id' => 'machine-shape-1',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();
        $jwt = Jwt::encode(['sub' => $node->node_id, 'exp' => now()->addHour()->getTimestamp()], $node->plainSecret());

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->postJson('/api/v1/agent/heartbeat', [
                'agent_version' => '0.1.0',
                'cert_path' => '/etc/hysteria/cert.pem',
                'key_path' => '/etc/hysteria/key.pem',
                'traffic_stats_listen' => '127.0.0.1:9999',
                'traffic_stats_secret' => 'node-side-secret',
            ])
            ->assertOk();

        $node->refresh();
        $this->assertSame('/etc/hysteria/cert.pem', $node->certPath());
        $this->assertSame('/etc/hysteria/key.pem', $node->keyPath());
        $this->assertSame('127.0.0.1:9999', $node->statsListen());
        $this->assertSame('node-side-secret', $node->statsSecret());

        // 面板渲染配置时要照抄这些值（而不是写死一个节点上不存在的路径）
        $rendered = \App\Support\HysteriaConfigBuilder::render($node);
        $this->assertStringContainsString('cert: /etc/hysteria/cert.pem', $rendered);
        // Yaml::dump 会给含冒号的纯量加引号（listen: '127.0.0.1:9999'），只断言值本身
        $this->assertStringContainsString('127.0.0.1:9999', $rendered);
        $this->assertStringContainsString('node-side-secret', $rendered);
    }

    /** 没上报时不该凭空塞 meta（每次心跳都写库会打破 15s 门控与 5000 节点规模验收）。 */
    public function test_heartbeat_without_shape_fields_does_not_touch_meta(): void
    {
        [$token, $plain] = \App\Models\EnrollToken::issue([]);
        $enrolled = $this->postJson('/api/v1/agent/enroll', $this->agentPayload($plain, [
            'hostname' => 'shape-2', 'machine_id' => 'machine-shape-2',
        ]))->assertOk();

        $node = \App\Models\Node::query()->where('node_id', $enrolled->json('node_id'))->firstOrFail();
        $jwt = Jwt::encode(['sub' => $node->node_id, 'exp' => now()->addHour()->getTimestamp()], $node->plainSecret());

        $this->withHeaders(['Authorization' => 'Bearer ' . $jwt])
            ->postJson('/api/v1/agent/heartbeat', ['agent_version' => '0.1.0'])
            ->assertOk();

        $meta = $node->refresh()->meta ?? [];
        $this->assertArrayNotHasKey('stats_secret', $meta);
        $this->assertArrayNotHasKey('cert_path', $meta);
    }
}
