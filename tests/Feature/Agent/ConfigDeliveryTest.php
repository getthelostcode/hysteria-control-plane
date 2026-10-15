<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Jobs\PushConfigToNode;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use App\Support\HysteriaConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesPanelUsers;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

class ConfigDeliveryTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;
    use SignedAgentRequests;

    private const YAML = "listen: :443\ntrafficStats:\n  listen: 127.0.0.1:9999\n";

    public function test_publish_then_agent_pulls_and_acks(): void
    {
        $node = Node::factory()->create();
        $configs = app(ConfigService::class);

        $config = $configs->publish($node, self::YAML, null, 'initial rollout');

        $this->assertSame(1, $config->version);
        $this->assertSame(hash('sha256', self::YAML), $config->sha256);
        $this->assertSame(NodeConfig::STATUS_PUBLISHED, $config->status);
        $this->assertDatabaseHas('node_config_history', [
            'node_id' => $node->id, 'to_version' => 1, 'action' => 'publish',
        ]);

        $this->agentCall($node, 'GET', '/api/agent/v1/config/latest')
            ->assertOk()
            ->assertJsonPath('up_to_date', false)
            ->assertJsonPath('config.version', 1)
            ->assertJsonPath('config.sha256', hash('sha256', self::YAML))
            ->assertJsonPath('config.content_yaml', self::YAML);

        $this->agentCall($node, 'POST', '/api/agent/v1/config/ack', ['version' => 1, 'status' => 'applied'])
            ->assertOk()
            ->assertJsonPath('status', 'applied');

        $this->assertSame(NodeConfig::STATUS_APPLIED, $config->refresh()->status);
        $this->assertNotNull($config->applied_at);
    }

    public function test_agent_reports_up_to_date_for_the_active_version(): void
    {
        $node = Node::factory()->create();
        app(ConfigService::class)->publish($node, self::YAML);

        $this->agentCall($node, 'GET', '/api/agent/v1/config/latest?current_version=1')
            ->assertOk()
            ->assertJsonPath('up_to_date', true);
    }

    public function test_failed_apply_triggers_automatic_rollback(): void
    {
        $node = Node::factory()->create();
        $configs = app(ConfigService::class);

        $v1 = $configs->publish($node, self::YAML, null, 'good');
        $configs->acknowledge($node, 1, 'applied');

        $v2 = $configs->publish($node, self::YAML . "bandwidth:\n  up: broken\n", null, 'broken');

        $this->agentCall($node, 'POST', '/api/agent/v1/config/ack', [
            'version' => 2, 'status' => 'failed', 'error' => 'yaml parse error',
        ])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('active_version', 3);

        $this->assertSame(NodeConfig::STATUS_FAILED, $v2->refresh()->status);
        $this->assertNotNull($v2->error);

        // A new version (3) was created carrying the content of v1.
        $rollback = NodeConfig::query()->where('node_id', $node->id)->where('version', 3)->firstOrFail();
        $this->assertSame($v1->content_yaml, $rollback->content_yaml);
        $this->assertDatabaseHas('node_config_history', [
            'node_id' => $node->id, 'to_version' => 3, 'action' => 'rollback',
        ]);

        // The node is flagged for the operator and the failure was audited.
        $this->assertSame(Node::STATUS_ERROR, $node->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'config.rolled_back']);
    }

    public function test_manual_rollback_creates_a_new_version(): void
    {
        $node = Node::factory()->create();
        $configs = app(ConfigService::class);

        $configs->publish($node, self::YAML);
        $configs->publish($node, self::YAML . "# v2\n");

        $rolled = $configs->rollback($node, 1, null, 'bad release');

        $this->assertSame(3, $rolled->version);
        $this->assertSame(self::YAML, $rolled->content_yaml);
        $this->assertDatabaseHas('node_config_history', ['to_version' => 3, 'action' => 'rollback']);
    }

    public function test_invalid_yaml_is_refused_with_a_422(): void
    {
        $node = Node::factory()->create();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", [
            'content_yaml' => "listen: :443\n\tbroken: [",
        ])->assertStatus(401); // unauthenticated first

        $this->actingAs($this->adminUser());

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", [
            'content_yaml' => "listen: :443\n  broken: [unclosed",
        ])->assertStatus(422)->assertJsonPath('error', 'invalid_config');

        $this->assertSame(0, NodeConfig::query()->count());
    }

    public function test_publishing_dispatches_the_push_job(): void
    {
        Queue::fake();
        $node = Node::factory()->create();

        app(ConfigService::class)->publish($node, self::YAML);

        Queue::assertPushed(PushConfigToNode::class);
    }

    public function test_generated_config_is_valid_yaml_with_node_placeholders(): void
    {
        $node = Node::factory()->create(['region' => 'hk']);

        $yaml = HysteriaConfigBuilder::render($node);
        $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);

        $this->assertSame(':443', $parsed['listen']);
        $this->assertSame($node->statsSecret(), $parsed['trafficStats']['secret']);
        $this->assertSame($node->obfsPassword(), $parsed['obfs']['salamander']['password']);
    }

    public function test_ack_for_unknown_version_is_a_404(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/config/ack', ['version' => 42, 'status' => 'applied'])
            ->assertStatus(404);
    }
}
