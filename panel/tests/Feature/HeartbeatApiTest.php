<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeTrafficDaily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatApiTest extends TestCase
{
    use RefreshDatabase;

    private function url(Node $node): string
    {
        return "/api/v1/nodes/{$node->id}/heartbeat";
    }

    public function test_requires_valid_token(): void
    {
        $node = Node::factory()->create();

        $this->postJson($this->url($node))->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer wrong-token')
            ->postJson($this->url($node))
            ->assertStatus(401);
    }

    public function test_updates_liveness_version_and_traffic(): void
    {
        $node = Node::factory()->create(['last_seen_at' => now()->subHour(), 'version' => 'v2.12.0']);
        $token = $node->issueApiToken();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson($this->url($node), [
                'version' => 'v2.13.1',
                'online_users' => 3,
                'tx' => 1024,
                'rx' => 2048,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $node->refresh();
        $this->assertTrue($node->isOnline());
        $this->assertSame('v2.13.1', $node->version);

        $row = NodeTrafficDaily::query()->sole();
        $this->assertSame(1024, (int) $row->tx_bytes);
        $this->assertSame(2048, (int) $row->rx_bytes);
        $this->assertSame(3, (int) $row->online_users);
    }

    public function test_traffic_deltas_accumulate(): void
    {
        $node = Node::factory()->create();
        $token = $node->issueApiToken();
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->withHeaders($headers)->postJson($this->url($node), ['tx' => 100, 'rx' => 100])->assertOk();
        $this->withHeaders($headers)->postJson($this->url($node), ['tx' => 200, 'rx' => 50])->assertOk();

        $row = NodeTrafficDaily::query()->sole();
        $this->assertSame(300, (int) $row->tx_bytes);
        $this->assertSame(150, (int) $row->rx_bytes);
    }

    public function test_disabled_node_is_rejected(): void
    {
        $node = Node::factory()->disabled()->create();
        $token = $node->issueApiToken();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson($this->url($node), ['tx' => 10])
            ->assertStatus(403)
            ->assertJsonPath('error', 'node_disabled');
    }
}
