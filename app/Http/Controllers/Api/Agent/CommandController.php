<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\CommandAckRequest;
use App\Http\Requests\Agent\CommandPollRequest;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Illuminate\Http\JsonResponse;

class CommandController extends Controller
{
    use InteractsWithAgent;

    public function poll(CommandPollRequest $request, CommandService $commands): JsonResponse
    {
        $node = $this->node($request);
        $limit = (int) ($request->validated('limit') ?? config('agent.command_poll_limit'));

        $pending = $commands->poll($node, $limit);

        return $this->ok([
            'commands' => $pending->map(static fn (NodeCommand $command): array => [
                'command_id' => $command->command_id,
                'type' => $command->type,
                'payload' => $command->payload ?? [],
                'issued_at' => $command->issued_at?->toIso8601String(),
                'expires_at' => $command->expires_at?->toIso8601String(),
            ])->values()->all(),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function ack(CommandAckRequest $request, CommandService $commands, string $commandId): JsonResponse
    {
        $node = $this->node($request);

        $command = NodeCommand::query()
            ->where('command_id', $commandId)
            ->where('node_id', $node->id)
            ->first();

        if ($command === null) {
            return response()->json([
                'error' => 'unknown_command',
                'message' => 'No such command for this node.',
            ], 404);
        }

        $commands->acknowledge(
            $command,
            (string) $request->validated('status', 'ok'),
            (array) $request->validated('result', []),
            $request->validated('error'),
        );

        return $this->ok([
            'command_id' => $command->command_id,
            'status' => $command->status,
        ]);
    }
}
