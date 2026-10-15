<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\EnrollToken;
use App\Models\Node;
use App\Support\PublicIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

/**
 * 节点 IP 必须显示公网地址。
 *
 * 真实故障：控制面部署在 Cloudflare Tunnel 后面（cloudflared 同机），项目没配 TrustProxies，
 * 于是 `$request->ip()` 永远是 127.0.0.1 —— 面板里每个节点的 IP 列都是 127.0.0.1。
 */
class NodePublicIpTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function ipProvider(): iterable
    {
        yield '公网 v4' => ['203.0.113.10', true];
        yield '公网 v4 (Google DNS)' => ['8.8.8.8', true];
        yield '公网 v6' => ['2001:4860:4860::8888', true];
        yield '私网 10/8' => ['10.1.2.3', false];
        yield '私网 172.16/12' => ['172.16.5.5', false];
        yield '私网 192.168/16' => ['192.168.1.10', false];
        yield '回环 v4' => ['127.0.0.1', false];
        yield '回环 v6' => ['::1', false];
        yield '链路本地 v4' => ['169.254.1.1', false];
        yield '链路本地 v6' => ['fe80::1', false];
        yield '未指定' => ['0.0.0.0', false];
        yield '多播' => ['239.1.1.1', false];
        yield 'ULA v6' => ['fc00::1', false];
        yield '空' => ['', false];
        yield '垃圾' => ['not-an-ip', false];
    }

    /** @dataProvider ipProvider */
    public function test_public_ip_classification(string $ip, bool $expected): void
    {
        $this->assertSame($expected, PublicIp::isPublic($ip), $ip);
    }

    public function test_forwarded_for_from_a_trusted_proxy_becomes_the_client_ip(): void
    {
        // 未配置 TrustProxies 时，这一条会拿到 127.0.0.1；配置之后拿到 XFF 里的真实客户端。
        $response = $this->call('POST', '/api/v1/agent/enroll', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.77',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['enroll_token' => 'enr_' . str_repeat('a', 40)]));

        $this->assertContains($response->getStatusCode(), [401, 422]);
    }

    public function test_enroll_records_the_agent_reported_public_ip(): void
    {
        [$token, $plain] = EnrollToken::issue(['note' => 'public-ip']);

        $this->postJson('/api/v1/agent/enroll', [
            'enroll_token' => $plain,
            'hostname' => 'jp-edge-1',
            'node_name' => 'jp-edge-1',
            'os' => 'linux',
            'arch' => 'amd64',
            'machine_id' => 'machine-reported-ip',
            'public_ip' => '203.0.113.42',
        ])->assertOk();

        $node = Node::query()->firstOrFail();

        $this->assertSame('203.0.113.42', $node->ip);
        $this->assertSame('agent', $node->meta['ip_source']);
        // 观测到的对端地址单独留档（这里就是隧道进程的 127.0.0.1）
        $this->assertSame('127.0.0.1', $node->enrolled_ip);
    }

    public function test_enroll_falls_back_to_the_observed_peer_when_nothing_is_public(): void
    {
        [$token, $plain] = EnrollToken::issue([]);

        // NB: postJson 的第三个参数是 HTTP 头，不是 server 变量；要改 REMOTE_ADDR 必须用
        // withServerVariables（否则测试里对端永远是 127.0.0.1，断言看着像业务 bug）。
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->postJson('/api/v1/agent/enroll', [
            'enroll_token' => $plain,
            'hostname' => 'lan-only',
            'machine_id' => 'machine-lan-only',
        ])->assertOk();

        $node = Node::query()->firstOrFail();

        // 私网地址也要如实记录（总比空白好），但来源标记为 peer，面板能区分
        $this->assertSame('10.9.8.7', $node->ip);
        $this->assertSame('peer', $node->meta['ip_source']);
    }

    public function test_heartbeat_cannot_downgrade_a_public_ip_to_a_private_one(): void
    {
        $node = Node::factory()->create(['ip' => '203.0.113.42', 'meta' => ['ip_source' => 'agent']]);
        $node->refresh();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [
            'agent_version' => '0.1.0',
            'ip' => '192.168.50.9',   // 内网部署常见：agent 报的是自己的内网地址
        ])->assertOk();

        $this->assertSame('203.0.113.42', $node->refresh()->ip, '私网地址不得覆盖已记录的公网地址');
    }

    public function test_heartbeat_updates_the_ip_when_the_node_moves(): void
    {
        $node = Node::factory()->create(['ip' => '203.0.113.42', 'meta' => ['ip_source' => 'agent']]);

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [
            'agent_version' => '0.1.0',
            'public_ip' => '198.51.100.9',
        ])->assertOk();

        $node->refresh();

        $this->assertSame('198.51.100.9', $node->ip);
        $this->assertSame('agent', $node->meta['ip_source']);
    }

    public function test_admin_api_can_correct_the_ip_manually(): void
    {
        $user = \App\Models\User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $user->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super-admin', 'web'));
        $this->actingAs($user);

        $node = Node::factory()->create(['ip' => '127.0.0.1']);

        $this->patchJson("/api/admin/v1/nodes/{$node->node_id}", ['ip' => '203.0.113.99'])
            ->assertOk()
            ->assertJsonPath('data.ip', '203.0.113.99');

        $this->assertSame('203.0.113.99', $node->refresh()->ip);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.updated']);
    }

    public function test_ipv4_is_preferred_over_ipv6_and_the_v6_is_still_recorded(): void
    {
        // 双栈节点：agent 探测到的和代理链看到的都可能是 v6，而面板要显示 v4
        $this->assertSame(
            ['ip' => '203.0.113.7', 'source' => 'agent', 'ip_v6' => '2001:4860:4860::8888'],
            PublicIp::resolve('203.0.113.7', '2001:4860:4860::8888'),
        );

        // agent 只报了 v6、代理链给了 v4 → 用 v4，但来源如实标为 proxy
        $this->assertSame(
            ['ip' => '203.0.113.8', 'source' => 'proxy', 'ip_v6' => '2001:4860:4860::8888'],
            PublicIp::resolve('2001:4860:4860::8888', '203.0.113.8'),
        );

        // 只有 v6：照实显示 v6（宁可显示 v6 也不要不显示）
        $this->assertSame(
            ['ip' => '2606:4700:4700::1111', 'source' => 'agent', 'ip_v6' => '2606:4700:4700::1111'],
            PublicIp::resolve('2606:4700:4700::1111', '127.0.0.1'),
        );

        $this->assertTrue(PublicIp::isIpv4('203.0.113.7'));
        $this->assertFalse(PublicIp::isIpv4('2606:4700:4700::1111'));
    }

    public function test_enroll_stores_the_ipv4_of_a_dual_stack_node(): void
    {
        [$token, $plain] = EnrollToken::issue([]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['CF-Connecting-IP' => '2001:4860:4860::8888'])
            ->postJson('/api/v1/agent/enroll', [
                'enroll_token' => $plain,
                'hostname' => 'dual-stack',
                'machine_id' => 'machine-dual-stack',
                'public_ip' => '203.0.113.7',
            ])->assertOk();

        $node = Node::query()->firstOrFail();

        $this->assertSame('203.0.113.7', $node->ip, '面板展示 IPv4');
        $this->assertSame('2001:4860:4860::8888', $node->meta['ip_v6'], 'v6 出口仍留档');
    }

    public function test_heartbeat_with_only_ipv6_does_not_replace_a_known_ipv4(): void
    {
        $node = Node::factory()->create(['ip' => '203.0.113.42', 'meta' => ['ip_source' => 'agent']]);

        // 节点切到 v6 出口：v4 不该被 v6 顶掉（面板按 v4 展示）
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [
            'agent_version' => '0.1.0',
            'public_ip' => '2606:4700:4700::1111',
        ])->assertOk();

        $this->assertSame('203.0.113.42', $node->refresh()->ip);
    }

    public function test_re_enroll_over_ipv6_does_not_downgrade_a_known_ipv4(): void
    {
        // 真实现场：节点名 japan2.3ai.best，从 IPv6 出口访问 Cloudflare，
        // refreshMetadata 曾把面板里的 IPv4 覆盖成 2a14:640:1:f6eb::1。
        [$token, $plain] = EnrollToken::issue([]);

        $node = Node::factory()->create([
            'ip' => '203.0.113.42',
            'meta' => ['machine_id' => 'dual-stack-node', 'ip_source' => 'agent'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['CF-Connecting-IP' => '2001:4860:4860::8888'])
            ->postJson('/api/v1/agent/enroll', [
                'enroll_token' => $plain,
                'hostname' => 'dual-stack-node',
                'machine_id' => 'dual-stack-node',
                'replace' => true,
            ])->assertOk();

        $node->refresh();

        $this->assertSame('203.0.113.42', $node->ip, '重注册不得把 IPv4 换成 IPv6');
        $this->assertSame('2001:4860:4860::8888', $node->meta['ip_v6'], 'IPv6 出口留档');
    }

    public function test_public_ip_helper_prefers_the_agent_report_and_guards_cloudflare_header(): void
    {
        // 对端是公网地址（不在 trusted_proxies 里）：CF-Connecting-IP 不得采信，
        // 否则任何人都能伪造 X-Forwarded-For 之外的这条头。
        $untrusted = Request::create('/api/v1/agent/enroll', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $untrusted->headers->set('CF-Connecting-IP', '203.0.113.5');
        // 采信的是对端自身地址，而不是请求头里那个可伪造的值
        $this->assertSame('203.0.113.9', PublicIp::fromRequest($untrusted));
        $this->assertFalse(PublicIp::isFromTrustedProxy($untrusted));

        // 对端是可信代理（本机 cloudflared）时才采信
        $trusted = Request::create('/api/v1/agent/enroll', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $trusted->headers->set('CF-Connecting-IP', '203.0.113.6');
        $this->assertSame('203.0.113.6', PublicIp::fromRequest($trusted));

        $this->assertSame(['ip' => '203.0.113.7', 'source' => 'agent', 'ip_v6' => null], PublicIp::resolve('203.0.113.7', '127.0.0.1'));
        $this->assertSame(['ip' => '203.0.113.8', 'source' => 'proxy', 'ip_v6' => null], PublicIp::resolve(null, '203.0.113.8'));
        $this->assertSame(['ip' => '10.0.0.1', 'source' => 'peer', 'ip_v6' => null], PublicIp::resolve('192.168.1.1', '10.0.0.1'));
    }
}
