<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Support\SignatureVerifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        [$plain, $hash, $cipher] = SignatureVerifier::issueSecret();

        return [
            'name' => 'node-' . fake()->unique()->numerify('####'),
            'node_id' => Node::generateNodeId(),
            'secret_hash' => $hash,
            'secret_ciphertext' => $cipher,
            'status' => Node::STATUS_ONLINE,
            'ip' => fake()->ipv4(),
            'region' => fake()->randomElement(['hk', 'sg', 'jp', 'us', 'de', 'uk']),
            'tags' => fake()->randomElements(['premium', 'trial', 'edge'], 2),
            'version' => '2.6.0',
            'agent_version' => '1.0.0',
            'meta' => ['arch' => 'amd64'],
            'enrolled_at' => now()->subDays(2),
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn (): array => [
            'status' => Node::STATUS_OFFLINE,
            'last_seen_at' => now()->subMinutes(30),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => Node::STATUS_DISABLED]);
    }

    public function withKnownSecret(string $secret): static
    {
        return $this->state(fn (): array => [
            'secret_hash' => hash('sha256', $secret),
            'secret_ciphertext' => \Illuminate\Support\Facades\Crypt::encryptString($secret),
        ]);
    }
}
