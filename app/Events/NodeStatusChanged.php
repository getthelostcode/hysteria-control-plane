<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Node;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NodeStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Node $node, public string $from, public string $to)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('node.' . $this->node->node_id),
            new PrivateChannel('fleet'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'node_id' => $this->node->node_id,
            'name' => $this->node->name,
            'from' => $this->from,
            'to' => $this->to,
            'last_seen_at' => $this->node->last_seen_at?->toIso8601String(),
        ];
    }
}
