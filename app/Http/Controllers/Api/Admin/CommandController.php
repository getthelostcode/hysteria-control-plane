<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IssueCommandRequest;
use App\Http\Resources\NodeCommandResource;
use App\Models\Node;
use App\Models\NodeCommand;
use App\Services\CommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', NodeCommand::class);

        $commands = NodeCommand::query()
            ->with('node')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderByDesc('id')
            ->paginate(min(200, (int) $request->integer('per_page', 25)));

        return response()->json([
            'data' => NodeCommandResource::collection($commands->items())->resolve(),
            'meta' => ['total' => $commands->total(), 'current_page' => $commands->currentPage()],
        ]);
    }

    public function store(IssueCommandRequest $request, Node $node, CommandService $commands): JsonResponse
    {
        $this->authorize('issueCommand', $node);

        $command = $commands->issue(
            $node,
            (string) $request->validated('type'),
            (array) ($request->validated('payload') ?? []),
            $request->user(),
        );

        return response()->json(['data' => (new NodeCommandResource($command))->resolve()], 201);
    }

    public function bulk(IssueCommandRequest $request, CommandService $commands): JsonResponse
    {
        $this->authorize('create', NodeCommand::class);

        $validated = $request->validated();
        $nodes = Node::query()
            ->when(! empty($validated['nodes']), fn ($query) => $query->whereIn('node_id', $validated['nodes']))
            ->when(empty($validated['nodes']), fn ($query) => $query->where('status', Node::STATUS_ONLINE))
            ->get();

        $issued = $commands->issueBulk(
            $nodes,
            (string) $validated['type'],
            (array) ($validated['payload'] ?? []),
            $request->user(),
        );

        return response()->json([
            'issued' => $issued->count(),
            'command_ids' => $issued->pluck('command_id')->all(),
        ], 201);
    }
}
