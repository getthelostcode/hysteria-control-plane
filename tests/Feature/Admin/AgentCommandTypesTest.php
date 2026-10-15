<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * agent-native command types (bridge contract, BRIDGE-TO-CONTROL-PLANE.md §4):
 * the panel must be able to queue them, and the dangerous ones must be
 * admin-only through every entry point (service, admin API, panel).
 */
class AgentCommandTypesTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    /** @return array<int, string> */
    private function agentTypes(): array
    {
        return [
            NodeCommand::TYPE_TAIL_LOGS,
            NodeCommand::TYPE_GET_STATE,
            NodeCommand::TYPE_CANCEL_TAIL,
            NodeCommand::TYPE_ROLLBACK_CONFIG,
            NodeCommand::TYPE_SELF_RESTART,
            NodeCommand::TYPE_CLEAR_OUTBOX,
        ];
    }

    public function test_agent_native_types_are_registered_with_labels(): void
    {
        foreach ($this->agentTypes() as $type) {
            $this->assertArrayHasKey($type, NodeCommand::TYPES, $type . ' 未注册');
            $this->assertNotSame('', NodeCommand::TYPES[$type]);
        }

        $this->assertTrue(NodeCommand::isDangerousType(NodeCommand::TYPE_CLEAR_OUTBOX));
        $this->assertTrue(NodeCommand::isDangerousType(NodeCommand::TYPE_SHELL));
        $this->assertFalse(NodeCommand::isDangerousType(NodeCommand::TYPE_TAIL_LOGS));
        $this->assertFalse(NodeCommand::isDangerousType(NodeCommand::TYPE_ROLLBACK_CONFIG));
    }

    public function test_administrator_can_queue_every_agent_type_over_the_api(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        foreach ($this->agentTypes() as $type) {
            $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", [
                'type' => $type,
                'payload' => $type === NodeCommand::TYPE_TAIL_LOGS ? ['level' => 'all', 'duration_seconds' => 60] : [],
            ])->assertCreated()->assertJsonPath('data.type', $type);
        }

        $this->assertSame(count($this->agentTypes()), NodeCommand::query()->count());
    }

    public function test_operator_may_issue_safe_agent_types_but_not_dangerous_ones(): void
    {
        $this->actingAs($this->operatorUser());
        $node = Node::factory()->create();

        foreach ([
            NodeCommand::TYPE_TAIL_LOGS,
            NodeCommand::TYPE_GET_STATE,
            NodeCommand::TYPE_ROLLBACK_CONFIG,
            NodeCommand::TYPE_SELF_RESTART,
        ] as $type) {
            $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", ['type' => $type])
                ->assertCreated();
        }

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", [
            'type' => NodeCommand::TYPE_CLEAR_OUTBOX,
        ])->assertStatus(403);

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", [
            'type' => NodeCommand::TYPE_SHELL,
            'payload' => ['cmd' => 'id'],
        ])->assertStatus(403);

        $this->assertSame(4, NodeCommand::query()->count());
    }

    public function test_bulk_endpoint_enforces_the_same_gate(): void
    {
        $this->actingAs($this->operatorUser());
        Node::factory()->count(2)->create();

        $this->postJson('/api/admin/v1/commands/bulk', ['type' => NodeCommand::TYPE_CLEAR_OUTBOX])
            ->assertStatus(403);

        $this->postJson('/api/admin/v1/commands/bulk', ['type' => NodeCommand::TYPE_GET_STATE])
            ->assertCreated()->assertJsonPath('issued', 2);
    }

    public function test_service_is_the_single_choke_point(): void
    {
        $node = Node::factory()->create();
        $commandService = app(CommandService::class);

        $this->expectException(AuthorizationException::class);
        $commandService->issue($node, NodeCommand::TYPE_CLEAR_OUTBOX, [], $this->operatorUser());
    }

    public function test_service_allows_the_same_command_for_a_manager(): void
    {
        $node = Node::factory()->create();

        $command = app(CommandService::class)
            ->issue($node, NodeCommand::TYPE_CLEAR_OUTBOX, ['confirm' => true], $this->adminUser());

        $this->assertSame(NodeCommand::STATUS_PENDING, $command->status);
        $this->assertTrue($command->payload['confirm']);
    }

    public function test_unknown_types_are_still_rejected_by_validation(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/commands", ['type' => 'rm -rf /'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, NodeCommand::query()->count());
    }

    public function test_panel_marks_dangerous_types_for_non_managers(): void
    {
        $this->actingAs($this->operatorUser());
        $options = \App\Filament\Resources\NodeResource::commandTypeOptions();

        $this->assertStringContainsString('仅管理员', $options[NodeCommand::TYPE_CLEAR_OUTBOX]);
        $this->assertStringNotContainsString('仅管理员', $options[NodeCommand::TYPE_TAIL_LOGS]);

        $this->actingAs($this->adminUser());
        $adminOptions = \App\Filament\Resources\NodeResource::commandTypeOptions();
        $this->assertStringNotContainsString('仅管理员', $adminOptions[NodeCommand::TYPE_CLEAR_OUTBOX]);
    }
}
