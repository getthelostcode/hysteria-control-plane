<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\NodeCommand;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NodeCommandIssued implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public string $nodeId;

    public function __construct(public NodeCommand $command)
    {
        $command->loadMissing('node');
        $this->nodeId = (string) ($command->node->node_id ?? $command->node_id);
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('node.' . $this->nodeId)];
    }

    public function broadcastAs(): string
    {
        return 'command.issued';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'command_id' => $this->command->command_id,
            'type' => $this->command->type,
            'payload' => $this->command->payload ?? [],
            'issued_at' => $this->command->issued_at?->toIso8601String(),
            'expires_at' => $this->command->expires_at?->toIso8601String(),
        ];
    }
}
