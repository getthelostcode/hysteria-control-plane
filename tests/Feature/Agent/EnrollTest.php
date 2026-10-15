<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Models\EnrollToken;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_enrolls_with_a_valid_token_and_is_listed_in_the_panel(): void
    {
        [$token, $plain] = EnrollToken::issue(['note' => 'test', 'max_uses' => 1]);

        $started = microtime(true);

        $response = $this->postJson('/api/agent/v1/enroll', [
            'token' => $plain,
            'name' => 'hk-edge-1',
            'region' => 'hk',
            'tags' => ['premium'],
            'version' => '2.6.0',
            'agent_version' => '1.0.0',
            'arch' => 'amd64',
        ]);

        // Acceptance: enroll completes in under 3 seconds.
        $this->assertLessThan(3.0, microtime(true) - $started);

        $response->assertCreated()
            ->assertJsonPath('node.name', 'hk-edge-1')
            ->assertJsonPath('node.status', Node::STATUS_ONLINE)
            ->assertJsonStructure([
                'node_id', 'secret',
                'signature' => ['algorithm', 'canonical', 'ttl_seconds'],
                'endpoints' => ['heartbeat', 'config_latest', 'config_ack', 'metrics', 'commands_poll', 'ws_auth'],
                'websocket' => ['url', 'channel', 'key'],
                'heartbeat_interval',
            ]);

        $nodeId = $response->json('node_id');
        $secret = $response->json('secret');

        $this->assertStringStartsWith('nd_', $nodeId);
        $this->assertStringStartsWith('nds_', $secret);

        $node = Node::query()->where('node_id', $nodeId)->firstOrFail();

        // The plaintext secret is never stored: only its hash and an encrypted copy.
        $this->assertNotSame($secret, $node->getRawOriginal('secret_ciphertext'));
        $this->assertSame(hash('sha256', $secret), $node->secret_hash);
        $this->assertSame($secret, $node->plainSecret());
        $this->assertSame('private-node.' . $nodeId, $response->json('websocket.channel'));

        // Enrollment is audited.
        $this->assertDatabaseHas('audit_logs', ['action' => 'node.enrolled', 'target' => $node->getKey()]);

        // The token is single use.
        $this->postJson('/api/agent/v1/enroll', ['token' => $plain])->assertStatus(422);
    }

    public function test_unknown_expired_and_exhausted_tokens_are_rejected(): void
    {
        $this->postJson('/api/agent/v1/enroll', ['token' => 'enr_does_not_exist'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_token');

        [$expired, $expiredPlain] = EnrollToken::issue(['expires_at' => now()->subDay()]);
        $this->postJson('/api/agent/v1/enroll', ['token' => $expiredPlain])->assertStatus(422);

        [$exhausted, $exhaustedPlain] = EnrollToken::issue(['max_uses' => 1, 'uses' => 1]);
        $this->postJson('/api/agent/v1/enroll', ['token' => $exhaustedPlain])->assertStatus(422);

        $this->assertSame(0, Node::query()->count());
    }

    public function test_multi_use_token_accepts_several_nodes(): void
    {
        [$token, $plain] = EnrollToken::issue(['max_uses' => 3]);

        foreach (['a', 'b', 'c'] as $name) {
            $this->postJson('/api/agent/v1/enroll', ['token' => $plain, 'name' => $name])->assertCreated();
        }

        $this->assertSame(3, Node::query()->count());
        $this->assertSame(3, $token->refresh()->uses);
    }

    public function test_validation_errors_are_reported(): void
    {
        $this->postJson('/api/agent/v1/enroll', ['token' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('token');
    }
}
