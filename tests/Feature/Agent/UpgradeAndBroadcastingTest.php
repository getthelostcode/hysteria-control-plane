<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Node;
use App\Models\UpgradePackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

class UpgradeAndBroadcastingTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    public function test_upgrade_check_reports_a_newer_stable_package(): void
    {
        $node = Node::factory()->create();
        UpgradePackage::query()->create([
            'version' => '1.4.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent-1.4.0',
            'sha256' => str_repeat('a', 64), 'signature' => 'sig', 'channel' => 'stable',
            'rollout_percent' => 100, 'is_active' => true, 'released_at' => now(),
        ]);

        $this->agentCall($node, 'GET', '/api/agent/v1/upgrade/check?arch=amd64&version=1.0.0')
            ->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('package.version', '1.4.0')
            ->assertJsonPath('package.sha256', str_repeat('a', 64));
    }

    public function test_upgrade_check_is_quiet_when_already_current(): void
    {
        $node = Node::factory()->create();
        UpgradePackage::query()->create([
            'version' => '1.4.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent-1.4.0',
            'sha256' => str_repeat('a', 64), 'channel' => 'stable', 'rollout_percent' => 100,
            'is_active' => true, 'released_at' => now(),
        ]);

        $this->agentCall($node, 'GET', '/api/agent/v1/upgrade/check?arch=amd64&version=1.4.0')
            ->assertOk()->assertJsonPath('update_available', false);
    }

    public function test_inactive_packages_are_ignored(): void
    {
        $node = Node::factory()->create();
        UpgradePackage::query()->create([
            'version' => '9.9.9', 'arch' => 'amd64', 'url' => 'https://example.com/agent',
            'sha256' => str_repeat('b', 64), 'channel' => 'stable', 'rollout_percent' => 100,
            'is_active' => false, 'released_at' => now(),
        ]);

        $this->agentCall($node, 'GET', '/api/agent/v1/upgrade/check?arch=amd64&version=1.0.0')
            ->assertOk()->assertJsonPath('update_available', false);
    }

    public function test_canary_rollout_only_targets_a_subset(): void
    {
        $targeted = 0;
        $packages = [];

        for ($i = 0; $i < 40; $i++) {
            $node = Node::factory()->create(['node_id' => 'nd_canary_' . $i]);
            $packages[$node->node_id] = $node;
        }

        UpgradePackage::query()->create([
            'version' => '2.0.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent-2',
            'sha256' => str_repeat('c', 64), 'channel' => 'stable', 'rollout_percent' => 25,
            'is_active' => true, 'released_at' => now(),
        ]);

        foreach ($packages as $node) {
            if ($this->agentCall($node, 'GET', '/api/agent/v1/upgrade/check?arch=amd64&version=1.0.0')->json('update_available')) {
                $targeted++;
            }
        }

        $this->assertGreaterThan(0, $targeted);
        $this->assertLessThan(40, $targeted);
    }

    public function test_agent_can_authenticate_its_own_private_channel(): void
    {
        $node = Node::factory()->create();
        $socketId = '12345.67890';

        $response = $this->agentCall($node, 'POST', '/api/agent/v1/broadcasting/auth', [
            'socket_id' => $socketId,
            'channel_name' => 'private-node.' . $node->node_id,
        ])->assertOk();

        $expectedSignature = hash_hmac(
            'sha256',
            $socketId . ':private-node.' . $node->node_id,
            (string) config('broadcasting.connections.reverb.secret'),
        );

        $this->assertSame(
            config('broadcasting.connections.reverb.key') . ':' . $expectedSignature,
            $response->json('auth'),
        );
    }

    public function test_agent_cannot_subscribe_to_another_nodes_channel(): void
    {
        $node = Node::factory()->create();
        $other = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/broadcasting/auth', [
            'socket_id' => '1.2',
            'channel_name' => 'private-node.' . $other->node_id,
        ])->assertStatus(403)->assertJsonPath('error', 'forbidden_channel');
    }

    public function test_ping_endpoint_needs_no_signature(): void
    {
        $this->getJson('/api/agent/v1/ping')->assertOk()->assertJsonPath('pong', true);
    }
}
