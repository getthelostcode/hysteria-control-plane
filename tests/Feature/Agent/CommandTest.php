<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Events\NodeCommandIssued;
use App\Jobs\DispatchCommand;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

class CommandTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    public function test_command_round_trip_in_under_two_seconds(): void
    {
        $node = Node::factory()->create();

        $started = microtime(true);

        $this->agent_call_issue_and_ack($node, NodeCommand::TYPE_RESTART, ['graceful' => true]);

        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_agent_polls_and_acks_a_command(): void
    {
        $node = Node::factory()->create();
        $command = app(CommandService::class)->issue($node, NodeCommand::TYPE_RESTART, ['graceful' => true]);

        $this->assertSame(NodeCommand::STATUS_PENDING, $command->status);

        $poll = $this->agentCall($node, 'POST', '/api/agent/v1/commands/poll', ['limit' => 10])
            ->assertOk()
            ->assertJsonCount(1, 'commands')
            ->assertJsonPath('commands.0.command_id', $command->command_id)
            ->assertJsonPath('commands.0.type', NodeCommand::TYPE_RESTART)
            ->assertJsonPath('commands.0.payload.graceful', true);

        // Polling marks the command as delivered (no second delivery).
        $this->assertSame(NodeCommand::STATUS_SENT, $command->refresh()->status);
        $this->assertNotNull($command->delivered_at);

        $this->agentCall($node, 'POST', '/api/agent/v1/commands/poll', [])
            ->assertOk()
            ->assertJsonCount(0, 'commands');

        $this->agentCall($node, 'POST', "/api/agent/v1/commands/{$command->command_id}/ack", [
            'status' => 'ok',
            'result' => ['exit_code' => 0],
        ])->assertOk()->assertJsonPath('status', 'acked');

        $this->assertSame(NodeCommand::STATUS_ACKED, $command->refresh()->status);
        $this->assertSame(0, $command->result['exit_code']);
        $this->assertNotNull($command->acked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'command.acknowledged']);
    }

    public function test_failed_command_is_recorded(): void
    {
        $node = Node::factory()->create();
        $command = app(CommandService::class)->issue($node, NodeCommand::TYPE_UPGRADE);

        $this->agentCall($node, 'POST', "/api/agent/v1/commands/{$command->command_id}/ack", [
            'status' => 'failed',
            'error' => 'checksum mismatch',
        ])->assertOk()->assertJsonPath('status', 'failed');

        $this->assertSame(NodeCommand::STATUS_FAILED, $command->refresh()->status);
        $this->assertSame('checksum mismatch', $command->error);
    }

    public function test_other_nodes_cannot_ack_someone_elses_command(): void
    {
        $node = Node::factory()->create();
        $other = Node::factory()->create();

        $command = app(CommandService::class)->issue($node, NodeCommand::TYPE_RESTART);

        $this->agentCall($other, 'POST', "/api/agent/v1/commands/{$command->command_id}/ack", ['status' => 'ok'])
            ->assertStatus(404);

        $this->assertSame(NodeCommand::STATUS_PENDING, $command->refresh()->status);
    }

    public function test_expired_commands_are_not_delivered(): void
    {
        $node = Node::factory()->create();
        $command = NodeCommand::factory()->create([
            'node_id' => $node->id,
            'status' => NodeCommand::STATUS_PENDING,
            'expires_at' => now()->subMinute(),
        ]);

        $this->agentCall($node, 'POST', '/api/agent/v1/commands/poll', [])
            ->assertOk()
            ->assertJsonCount(0, 'commands');

        $this->assertSame(NodeCommand::STATUS_EXPIRED, $command->refresh()->status);
    }

    public function test_issuing_dispatches_the_job_and_broadcasts_the_event(): void
    {
        Queue::fake();
        Event::fake([NodeCommandIssued::class]);

        $node = Node::factory()->create();
        app(CommandService::class)->issue($node, NodeCommand::TYPE_RELOAD);

        Queue::assertPushed(DispatchCommand::class);
    }

    public function test_bulk_issue_targets_every_node(): void
    {
        $nodes = Node::factory()->count(3)->create();

        $issued = app(CommandService::class)->issueBulk($nodes, NodeCommand::TYPE_RELOAD);

        $this->assertCount(3, $issued);
        $this->assertSame(3, NodeCommand::query()->count());
    }

    /** Full acceptance flow: issue -> poll -> ack, measuring the round trip. */
    private function agent_call_issue_and_ack(Node $node, string $type, array $payload): void
    {
        $command = app(CommandService::class)->issue($node, $type, $payload);

        $this->agentCall($node, 'POST', '/api/agent/v1/commands/poll', ['limit' => 5])
            ->assertOk()->assertJsonPath('commands.0.command_id', $command->command_id);

        $this->agentCall($node, 'POST', "/api/agent/v1/commands/{$command->command_id}/ack", ['status' => 'ok'])
            ->assertOk()->assertJsonPath('status', 'acked');
    }
}
