<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\NodeCommand;
use App\Models\UpgradePackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    public function test_viewers_can_read_but_not_act(): void
    {
        $this->actingAs($this->viewerUser());
        $node = Node::factory()->create();

        $this->getJson('/api/admin/v1/nodes')->assertOk();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", ['type' => NodeCommand::TYPE_RESTART])
            ->assertStatus(403);

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs", ['content_yaml' => "a: 1\n"])
            ->assertStatus(403);

        $this->postJson('/api/admin/v1/enroll-tokens', ['note' => 'nope'])->assertStatus(403);

        $this->assertSame(0, NodeCommand::query()->count());
    }

    public function test_operators_can_act_but_not_release_upgrades_or_delete_nodes(): void
    {
        $this->actingAs($this->operatorUser());
        $node = Node::factory()->create();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", ['type' => NodeCommand::TYPE_RESTART])
            ->assertCreated();

        $this->postJson('/api/admin/v1/upgrade-packages', [
            'version' => '2.0.0', 'arch' => 'amd64', 'url' => 'https://example.com/a', 'sha256' => str_repeat('e', 64),
        ])->assertStatus(403);

        $this->deleteJson("/api/admin/v1/nodes/{$node->node_id}")->assertStatus(403);

        $this->assertSame(1, Node::query()->count());
    }

    public function test_admins_can_manage_upgrade_packages(): void
    {
        $this->actingAs($this->adminUser());

        $this->postJson('/api/admin/v1/upgrade-packages', [
            'version' => '2.0.0', 'arch' => 'amd64', 'url' => 'https://example.com/a', 'sha256' => str_repeat('f', 64),
        ])->assertCreated();

        $this->assertSame(1, UpgradePackage::query()->count());
    }

    public function test_soft_deleted_nodes_disappear_from_the_api(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        $this->deleteJson("/api/admin/v1/nodes/{$node->node_id}")->assertOk();

        $this->getJson('/api/admin/v1/nodes')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSoftDeleted('nodes', ['id' => $node->id]);
    }
}
