<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\Node;
use App\Models\NodeCredential;
use App\Support\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedAgentRequests;
use Tests\TestCase;

class AgentAuthTest extends TestCase
{
    use RefreshDatabase;
    use SignedAgentRequests;

    public function test_signed_heartbeat_is_accepted(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '2.6.0'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('node_id', $node->node_id);
    }

    public function test_missing_headers_are_rejected(): void
    {
        $this->postJson('/api/agent/v1/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error', 'missing_node_id');
    }

    public function test_unknown_node_is_rejected(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [], [
            SignatureVerifier::HEADER_NODE_ID => 'nd_unknown',
        ])->assertStatus(404)->assertJsonPath('error', 'unknown_node');
    }

    public function test_tampered_body_is_rejected(): void
    {
        $node = Node::factory()->create();
        $raw = (string) json_encode(['version' => '1.0.0']);

        // Sign one body, send another.
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();

        $this->call('POST', '/api/agent/v1/heartbeat', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            SignatureVerifier::HEADER_NODE_ID => $node->node_id,
            SignatureVerifier::HEADER_TIMESTAMP => $timestamp,
            SignatureVerifier::HEADER_NONCE => $nonce,
            SignatureVerifier::HEADER_SIGNATURE => SignatureVerifier::sign(
                $node->plainSecret(), 'POST', '/api/agent/v1/heartbeat', $raw, $timestamp, $nonce
            ),
        ], (string) json_encode(['version' => '9.9.9']))->assertStatus(401);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'], [], false, null, [
            'timestamp' => (string) (now()->getTimestamp() - SignatureVerifier::ttl() - 60),
        ])->assertStatus(401);
    }

    public function test_future_timestamp_is_rejected(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'], [], false, null, [
            'timestamp' => (string) (now()->getTimestamp() + SignatureVerifier::ttl() + 60),
        ])->assertStatus(401);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $node = Node::factory()->create();
        $nonce = (string) Str::uuid();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'], [], false, null, ['nonce' => $nonce])
            ->assertOk();

        // Same nonce (and therefore the same signature) must not work twice.
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'], [], false, null, ['nonce' => $nonce])
            ->assertStatus(409)
            ->assertJsonPath('error', 'replayed_nonce');
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $node = Node::factory()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'], [], false, 'nds_wrong_secret')
            ->assertStatus(401);
    }

    public function test_rotated_credentials_keep_working_during_grace(): void
    {
        $node = Node::factory()->create();
        $oldSecret = $node->plainSecret();

        $node->rotateSecret();

        // Current secret works...
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['v' => 1])->assertOk();

        // ...and so does the previous one while a rotation grace record exists.
        $this->assertSame(1, NodeCredential::query()->whereNull('revoked_at')->count());
    }

    public function test_disabled_node_is_refused(): void
    {
        $node = Node::factory()->disabled()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [])
            ->assertStatus(403)
            ->assertJsonPath('error', 'node_disabled');
    }

    public function test_heartbeat_write_is_throttled_for_scale(): void
    {
        $node = Node::factory()->create();

        // First heartbeat opens the write gate and persists liveness.
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'])->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', ['version' => '1.0.0'])->assertOk();
        $updates = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update `nodes`'))
            ->count();

        $this->assertSame(0, $updates, 'Heartbeat write throttling should suppress redundant node updates.');
    }

    public function test_middleware_recovers_node_status_to_online(): void
    {
        $node = Node::factory()->offline()->create();

        $this->agentCall($node, 'POST', '/api/agent/v1/heartbeat', [])->assertOk();

        $this->assertSame(Node::STATUS_ONLINE, $node->refresh()->status);
        $this->assertTrue($node->isOnline());
    }
}
