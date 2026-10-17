<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Node>
 */
class NodeFactory extends Factory
{
    protected $model = Node::class;

    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'name' => 'node-' . fake()->numerify('###'),
            'ip' => fake()->unique()->ipv4(),
            'hostname' => fake()->domainName(),
            'obfs_password' => 'testcode01',
            'traffic_port' => fake()->numberBetween(10000, 19000),
            'traffic_secret' => bin2hex(random_bytes(24)),
            'version' => 'v2.13.0',
            'status' => Node::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ];
    }

    public function offline(): static
    {
        return $this->state(fn (): array => ['last_seen_at' => now()->subHour()]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => Node::STATUS_DISABLED]);
    }
}
