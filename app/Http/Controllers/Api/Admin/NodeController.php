<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\Node;
use App\Services\NodeService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Node::class);

        $perPage = min(200, max(1, (int) $request->integer('per_page', 25)));

        $nodes = Node::query()
            ->with(['latestMetric', 'latestConfig'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('region'), fn ($query) => $query->where('region', $request->string('region')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%' . $request->string('search') . '%';
                $query->where(static function ($sub) use ($term): void {
                    $sub->where('name', 'like', $term)
                        ->orWhere('node_id', 'like', $term)
                        ->orWhere('ip', 'like', $term);
                });
            })
            ->orderByDesc('last_seen_at')
            ->paginate($perPage);

        return response()->json([
            'data' => NodeResource::collection($nodes->items())->resolve(),
            'meta' => [
                'current_page' => $nodes->currentPage(),
                'per_page' => $nodes->perPage(),
                'total' => $nodes->total(),
                'last_page' => $nodes->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, Node $node): JsonResponse
    {
        $this->authorize('view', $node);

        $node->load(['latestMetric', 'latestConfig']);

        return response()->json([
            'data' => (new NodeResource($node))->resolve(),
            'meta' => [
                'commands_total' => $node->commands()->count(),
                'commands_pending' => $node->commands()->where('status', 'pending')->count(),
                'configs_total' => $node->configs()->count(),
            ],
        ]);
    }

    public function update(Request $request, Node $node): JsonResponse
    {
        $this->authorize('update', $node);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'region' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tags' => ['sometimes', 'array', 'max:16'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', Node::STATUSES)],
            // 运维手工修正公网 IP 的逃生口（节点在无法被控制面观测到的网络里时）
            'ip' => ['sometimes', 'nullable', 'ip'],
        ]);

        $node->fill($data)->save();

        AuditLogger::log('node.updated', $node, ['changes' => $data], $request->user()?->name, $request->ip());

        return response()->json(['data' => (new NodeResource($node->refresh()))->resolve()]);
    }

    public function destroy(Request $request, Node $node): JsonResponse
    {
        $this->authorize('delete', $node);

        AuditLogger::log('node.deleted', $node, ['node_id' => $node->node_id], $request->user()?->name, $request->ip());

        $node->delete();

        return response()->json(['ok' => true]);
    }

    public function rotate(Request $request, Node $node, NodeService $nodes): JsonResponse
    {
        $this->authorize('rotateSecret', $node);

        $secret = $nodes->rotateSecret($node, $request->user());

        return response()->json([
            'node_id' => $node->node_id,
            'secret' => $secret,
            'warning' => 'Store the secret now: it is not retrievable again.',
        ]);
    }
}
