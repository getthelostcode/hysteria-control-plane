<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class ConfigPublished implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** @param array<string, mixed> $config */
    public function __construct(public string $nodeId, public array $config)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('node.' . $this->nodeId),
            new PrivateChannel('fleet'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'config.published';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return array_merge(['node_id' => $this->nodeId], $this->config);
    }
}
