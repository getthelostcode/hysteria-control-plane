<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeCommand>
 */
class NodeCommandFactory extends Factory
{
    protected $model = NodeCommand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'type' => NodeCommand::TYPE_RESTART,
            'payload' => [],
            'status' => NodeCommand::STATUS_PENDING,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
