<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NodeCommand;
use App\Services\CommandService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCommand implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $commandId)
    {
    }

    public function handle(CommandService $commands): void
    {
        $command = NodeCommand::query()->with('node')->find($this->commandId);

        if ($command === null || $command->isFinished()) {
            return;
        }

        $commands->broadcast($command);
    }
}
