<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EnrollToken;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Models\NodeConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    public function test_login_issues_a_sanctum_token(): void
    {
        User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('password'),
        ])->assignRole(\Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));

        $this->postJson('/api/admin/v1/auth/login', [
            'email' => 'admin@example.com', 'password' => 'password', 'device_name' => 'phpunit',
        ])->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'roles']]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.token_issued']);
    }

    public function test_login_rejects_bad_credentials_and_records_the_attempt(): void
    {
        User::query()->create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/admin/v1/auth/login', [
            'email' => 'admin@example.com', 'password' => 'nope',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.failed']);
    }

    public function test_admin_endpoints_require_authentication(): void
    {
        $this->getJson('/api/admin/v1/nodes')->assertStatus(401);
        $this->getJson('/api/admin/v1/stats')->assertStatus(401);
        $this->postJson('/api/admin/v1/enroll-tokens', [])->assertStatus(401);
    }

    public function test_node_index_supports_filtering_search_and_pagination(): void
    {
        $this->actingAs($this->adminUser());

        Node::factory()->create(['name' => 'hk-edge-1', 'region' => 'hk', 'status' => Node::STATUS_ONLINE]);
        Node::factory()->offline()->create(['name' => 'de-edge-1', 'region' => 'de']);

        $this->getJson('/api/admin/v1/nodes')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/v1/nodes?status=online')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/v1/nodes?region=de')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/v1/nodes?search=hk-edge')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/v1/nodes?per_page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_node_detail_exposes_meta_but_never_secret_material(): void
    {
        $this->actingAs($this->adminUser());

        $node = Node::factory()->create([
            'meta' => [
                'protocol' => 'bridge/v1',
                'enroll_contract' => 'agent-v1',
                'kernel' => '6.17.2-1-pve',
                // 这几项必须被 publicMeta() 过滤掉，否则会随 API 泄露。
                'demo_secret' => 'nds_should_never_leak',
                'api_token' => 'tok_should_never_leak',
                'dashboard_password' => 'pw_should_never_leak',
            ],
        ]);

        $payload = $this->getJson("/api/admin/v1/nodes/{$node->node_id}")
            ->assertOk()
            ->assertJsonPath('data.meta.protocol', 'bridge/v1')
            ->assertJsonPath('data.meta.enroll_contract', 'agent-v1')
            ->json('data.meta');

        $this->assertArrayNotHasKey('demo_secret', $payload);
        $this->assertArrayNotHasKey('api_token', $payload);
        $this->assertArrayNotHasKey('dashboard_password', $payload);
        $this->assertStringNotContainsString('should_never_leak', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_node_detail_exposes_latest_metric_and_config(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();
        app(\App\Services\ConfigService::class)->publish($node, "listen: :443\n");

        $this->getJson("/api/admin/v1/nodes/{$node->node_id}")
            ->assertOk()
            ->assertJsonPath('data.node_id', $node->node_id)
            ->assertJsonPath('meta.configs_total', 1);
    }

    public function test_issuing_a_command_through_the_admin_api(): void
    {
        $user = $this->adminUser();
        $this->actingAs($user);
        $node = Node::factory()->create();

        $response = $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", [
            'type' => NodeCommand::TYPE_RESTART,
            'payload' => ['graceful' => true],
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $command = NodeCommand::query()->where('command_id', $response->json('data.command_id'))->firstOrFail();
        $this->assertSame($user->id, $command->issued_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.issued', 'actor' => $user->name]);
    }

    public function test_bulk_command_reaches_all_online_nodes(): void
    {
        $this->actingAs($this->adminUser());
        Node::factory()->count(2)->create();
        Node::factory()->offline()->create();

        $this->postJson('/api/admin/v1/commands/bulk', ['type' => NodeCommand::TYPE_RELOAD])
            ->assertCreated()
            ->assertJsonPath('issued', 2);

        $this->assertSame(2, NodeCommand::query()->count());
    }

    public function test_publishing_and_rolling_back_config_through_the_admin_api(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", [
            'content_yaml' => "listen: :443\n", 'reason' => 'initial',
        ])->assertCreated()->assertJsonPath('data.version', 1);

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", [
            'content_yaml' => "listen: :8443\n", 'reason' => 'change port',
        ])->assertCreated()->assertJsonPath('data.version', 2);

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs/1/rollback", ['reason' => 'revert'])
            ->assertCreated()
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('rolled_back_to', 1);

        $this->assertSame(3, NodeConfig::query()->where('node_id', $node->id)->count());

        $this->getJson("/api/admin/v1/nodes/{$node->node_id}/configs/3/diff")
            ->assertOk()
            ->assertJsonPath('against', 2);
    }

    public function test_config_is_generated_from_the_template_when_omitted(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        $response = $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", ['reason' => 'from template'])
            ->assertCreated();

        $config = NodeConfig::query()->findOrFail($response->json('data.id'));
        $parsed = \Symfony\Component\Yaml\Yaml::parse($config->content_yaml);

        $this->assertSame($node->statsSecret(), $parsed['trafficStats']['secret']);
    }

    public function test_metrics_endpoint_returns_a_bucketed_series(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        app(\App\Services\MetricsService::class)->ingest($node, [
            ['cpu' => 10, 'mem' => 20, 'net_rx' => 100, 'net_tx' => 200, 'recorded_at' => now()->subMinutes(10)->toIso8601String()],
            ['cpu' => 30, 'mem' => 40, 'net_rx' => 300, 'net_tx' => 400, 'recorded_at' => now()->toIso8601String()],
        ]);

        $response = $this->getJson("/api/admin/v1/nodes/{$node->node_id}/metrics?range=1h")->assertOk();

        $this->assertNotEmpty($response->json('series'));
        $this->assertArrayHasKey('net_rx', $response->json('series.0'));
    }

    public function test_enroll_token_lifecycle_over_the_admin_api(): void
    {
        $this->actingAs($this->adminUser());

        $response = $this->postJson('/api/admin/v1/enroll-tokens', [
            'note' => 'batch 1', 'max_uses' => 5, 'expires_in_days' => 3,
        ])->assertCreated()->assertJsonPath('data.max_uses', 5);

        $plain = $response->json('token');
        $this->assertStringStartsWith('enr_', $plain);

        $token = EnrollToken::query()->firstOrFail();
        $this->assertSame(EnrollToken::hashToken($plain), $token->token_hash);
        $this->assertStringStartsWith($token->prefix, $plain);

        $this->getJson('/api/admin/v1/enroll-tokens')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/admin/v1/enroll-tokens/{$token->id}")->assertOk();
        $this->assertFalse($token->refresh()->isUsable());
    }

    public function test_upgrade_package_release_and_update(): void
    {
        $this->actingAs($this->adminUser());

        $response = $this->postJson('/api/admin/v1/upgrade-packages', [
            'version' => '1.2.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent',
            'sha256' => str_repeat('d', 64), 'channel' => 'beta', 'rollout_percent' => 10,
        ])->assertCreated()->assertJsonPath('data.rollout_percent', 10);

        $id = $response->json('data.id');

        $this->patchJson("/api/admin/v1/upgrade-packages/{$id}", ['rollout_percent' => 100, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.rollout_percent', 100)->assertJsonPath('data.is_active', false);

        // Duplicate release is a validation error, not a 500.
        $this->postJson('/api/admin/v1/upgrade-packages', [
            'version' => '1.2.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent',
            'sha256' => str_repeat('d', 64), 'channel' => 'beta',
        ])->assertStatus(422);

        // Bad checksum is rejected.
        $this->postJson('/api/admin/v1/upgrade-packages', [
            'version' => '1.3.0', 'arch' => 'amd64', 'url' => 'https://example.com/agent', 'sha256' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors('sha256');
    }

    public function test_stats_endpoint_summarises_the_fleet(): void
    {
        $this->actingAs($this->adminUser());
        Node::factory()->count(3)->create();
        Node::factory()->offline()->create();

        $response = $this->getJson('/api/admin/v1/stats')->assertOk();

        $this->assertSame(4, $response->json('nodes.total'));
        $this->assertSame(3, $response->json('nodes.online'));
        $this->assertSame('ocean', $response->json('theme.preset'));
        $this->assertNotNull($response->json('server_time'));
    }

    public function test_secret_rotation_returns_a_one_time_secret_and_audits_it(): void
    {
        $this->actingAs($this->superAdmin());
        $node = Node::factory()->create();
        $before = $node->secret_hash;

        $response = $this->postJson("/api/admin/v1/nodes/{$node->node_id}/secret/rotate")
            ->assertOk()->assertJsonStructure(['secret', 'warning']);

        $this->assertStringStartsWith('nds_', $response->json('secret'));
        $this->assertNotSame($before, $node->refresh()->secret_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.secret_rotated']);
    }

    public function test_sensitive_operations_are_all_audited_with_actor_and_ip(): void
    {
        $user = $this->adminUser();
        $this->actingAs($user);
        $node = Node::factory()->create();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", ['type' => NodeCommand::TYPE_RESTART]);
        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", ['content_yaml' => "listen: :443\n"]);
        $this->postJson('/api/admin/v1/enroll-tokens', ['note' => 'x']);

        foreach (['command.issued', 'config.published', 'enroll_token.created'] as $action) {
            $log = AuditLog::query()->where('action', $action)->firstOrFail();
            $this->assertSame($user->name, $log->actor);
            $this->assertNotNull($log->ip);
            $this->assertNotNull($log->meta);
        }
    }
}
