<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Node;
use App\Models\NodeMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_offline_marks_silent_nodes_offline_and_fires_status_change(): void
    {
        $silent = Node::factory()->create(['last_seen_at' => now()->subMinutes(10)]);
        $fresh = Node::factory()->create(['last_seen_at' => now()]);
        $disabled = Node::factory()->disabled()->create(['last_seen_at' => now()->subHour()]);

        $this->artisan('nodes:check-offline')->assertSuccessful();

        $this->assertSame(Node::STATUS_OFFLINE, $silent->refresh()->status);
        $this->assertSame(Node::STATUS_ONLINE, $fresh->refresh()->status);
        $this->assertSame(Node::STATUS_DISABLED, $disabled->refresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.status_changed']);
    }

    public function test_check_offline_respects_the_threshold_option(): void
    {
        $node = Node::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

        $this->artisan('nodes:check-offline', ['--threshold' => 3600])->assertSuccessful();

        $this->assertSame(Node::STATUS_ONLINE, $node->refresh()->status);
    }

    public function test_metrics_prune_drops_old_samples_only(): void
    {
        $node = Node::factory()->create();

        NodeMetric::query()->create(['node_id' => $node->id, 'cpu' => 1, 'recorded_at' => now()->subDays(40)]);
        NodeMetric::query()->create(['node_id' => $node->id, 'cpu' => 1, 'recorded_at' => now()->subDay()]);

        $this->artisan('metrics:prune', ['--days' => 30])->assertSuccessful();

        $this->assertSame(1, NodeMetric::query()->count());
    }

    public function test_scheduled_commands_are_registered(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('nodes:check-offline')->assertSuccessful();
    }
}
