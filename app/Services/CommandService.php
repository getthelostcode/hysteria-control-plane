<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NodeCommandIssued;
use App\Jobs\DispatchCommand;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Collection;

final class CommandService
{
    /** @param array<string, mixed> $payload */
    public function issue(Node $node, string $type, array $payload = [], ?User $actor = null): NodeCommand
    {
        $command = NodeCommand::create([
            'node_id' => $node->id,
            'type' => $type,
            'payload' => $payload,
            'status' => NodeCommand::STATUS_PENDING,
            'issued_by' => $actor?->id,
            'issued_at' => now(),
        ]);

        AuditLogger::log('command.issued', $command, [
            'node_id' => $node->node_id,
            'command_id' => $command->command_id,
            'type' => $type,
            'payload' => $payload,
        ], $actor?->name);

        DispatchCommand::dispatch($command->id);

        return $command;
    }

    /**
     * @param  iterable<Node>  $nodes
     * @param  array<string, mixed>  $payload
     * @return Collection<int, NodeCommand>
     */
    public function issueBulk(iterable $nodes, string $type, array $payload = [], ?User $actor = null): Collection
    {
        $commands = new Collection();

        foreach ($nodes as $node) {
            $commands->push($this->issue($node, $type, $payload, $actor));
        }

        return $commands;
    }

    /** @return Collection<int, NodeCommand> */
    public function poll(Node $node, int $limit = 10): Collection
    {
        $commands = NodeCommand::query()
            ->where('node_id', $node->id)
            ->where('status', NodeCommand::STATUS_PENDING)
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($commands->isNotEmpty()) {
            NodeCommand::query()->whereIn('id', $commands->pluck('id')->all())->update([
                'status' => NodeCommand::STATUS_SENT,
                'delivered_at' => now(),
            ]);

            $commands->each(static fn (NodeCommand $command) => $command->status = NodeCommand::STATUS_SENT);
        }

        NodeCommand::query()
            ->where('node_id', $node->id)
            ->where('status', NodeCommand::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => NodeCommand::STATUS_EXPIRED]);

        return $commands;
    }

    /** @param array<string, mixed> $result */
    public function acknowledge(NodeCommand $command, string $status, array $result = [], ?string $error = null): NodeCommand
    {
        $command->status = $status === 'ok' || $status === 'acked'
            ? NodeCommand::STATUS_ACKED
            : NodeCommand::STATUS_FAILED;
        $command->result = $result;
        $command->error = $error;
        $command->acked_at = now();
        $command->save();

        AuditLogger::log('command.acknowledged', $command, [
            'command_id' => $command->command_id,
            'node_id' => $command->node_id,
            'status' => $command->status,
            'error' => $error,
        ], 'agent');

        return $command;
    }

    /** Broadcast the command to the node's private channel (WS fast path). */
    public function broadcast(NodeCommand $command): void
    {
        $command->loadMissing('node');

        if ($command->node === null) {
            return;
        }

        NodeCommandIssued::dispatch($command);

        if ($command->delivered_at === null && $command->status === NodeCommand::STATUS_PENDING) {
            $command->forceFill(['delivered_at' => now()])->save();
        }
    }
}
