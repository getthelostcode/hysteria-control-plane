<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Jobs\AggregateMetrics;
use App\Models\Node;
use App\Models\NodeLog;
use App\Models\NodeMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

class MetricsAndLogsTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    public function test_plain_json_batch_is_stored(): void
    {
        Queue::fake();
        $node = Node::factory()->create();

        $response = $this->agentCall($node, 'POST', '/api/agent/v1/metrics', [
            'samples' => [
                ['cpu' => 12.5, 'mem' => 40, 'disk' => 55, 'net_rx' => 1000, 'net_tx' => 2000, 'conn_count' => 10, 'users' => 3],
                ['cpu' => 30.0, 'mem' => 44, 'disk' => 55, 'net_rx' => 2000, 'net_tx' => 3000, 'conn_count' => 12, 'users' => 4],
            ],
        ]);

        $response->assertStatus(202)->assertJsonPath('stored', 2);
        $this->assertSame(2, NodeMetric::query()->count());
        $this->assertSame(12.5, (float) NodeMetric::query()->first()->cpu);

        // Ingest stays thin: aggregation happens on the queue.
        Queue::assertPushed(AggregateMetrics::class);
    }

    public function test_gzip_batch_is_decoded(): void
    {
        Queue::fake();
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/metrics', [
            'samples' => [['cpu' => 5, 'mem' => 10, 'net_rx' => 10, 'net_tx' => 20]],
        ], [], gzip: true)->assertStatus(202)->assertJsonPath('stored', 1);

        $this->assertSame(1, NodeMetric::query()->count());
    }

    public function test_single_flat_sample_and_alias_key_are_accepted(): void
    {
        Queue::fake();
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/metrics', [
            'metrics' => [['cpu' => 1, 'mem' => 2]],
        ])->assertStatus(202)->assertJsonPath('stored', 1);

        $this->agentCall($node, 'POST', '/api/agent/v1/metrics', ['cpu' => 3, 'mem' => 4])
            ->assertStatus(202)->assertJsonPath('stored', 1);
    }

    public function test_metrics_ingest_refreshes_liveness(): void
    {
        Queue::fake();
        $node = Node::factory()->offline()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/metrics', ['samples' => [['cpu' => 1]]])->assertStatus(202);

        $this->assertTrue($node->refresh()->isOnline());
    }

    public function test_batch_size_is_capped(): void
    {
        Queue::fake();
        $node = Node::factory()->create();
        $samples = array_fill(0, 600, ['cpu' => 1]);

        $this->agentCall($node, 'POST', '/api/agent/v1/metrics', ['samples' => $samples])->assertStatus(422);
    }

    public function test_log_batch_is_stored_with_levels(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/logs', [
            'entries' => [
                ['level' => 'warning', 'message' => 'quic handshake slow', 'context' => ['ms' => 900]],
                ['level' => 'nonsense', 'message' => 'fallback level'],
            ],
        ])->assertStatus(202)->assertJsonPath('stored', 2);

        $this->assertSame(2, NodeLog::query()->count());
        $this->assertSame('warning', NodeLog::query()->first()->level);
        $this->assertSame('info', NodeLog::query()->orderByDesc('id')->first()->level);
        $this->assertSame(900, NodeLog::query()->first()->context['ms']);
    }

    public function test_diagnostics_report_is_stored_and_mirrored_into_meta(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/diagnostics', [
            'payload' => ['summary' => 'all good', 'dns' => 'ok', 'kernel' => '6.17.2'],
        ])->assertStatus(202);

        $this->assertDatabaseHas('node_logs', ['node_id' => $node->id, 'level' => 'diagnostic', 'message' => 'all good']);
        $this->assertSame('ok', $node->refresh()->meta['diagnostics']['payload']['dns']);
    }

    public function test_metrics_are_rejected_without_a_valid_signature(): void
    {
        $node = Node::factory()->create();

        $this->agentJson($node, 'POST', '/api/agent/v1/metrics', ['samples' => [['cpu' => 1]]])
            ->assertStatus(401);

        $this->assertSame(0, NodeMetric::query()->count());
    }
}
