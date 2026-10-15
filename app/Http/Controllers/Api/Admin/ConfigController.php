<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublishConfigRequest;
use App\Http\Requests\Admin\RollbackConfigRequest;
use App\Http\Resources\NodeConfigResource;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use App\Support\HysteriaConfigBuilder;
use App\Support\YamlDiff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function index(Request $request, Node $node, ConfigService $configs): JsonResponse
    {
        $this->authorize('viewAny', NodeConfig::class);

        $versions = $node->configs()->orderByDesc('version')->limit(100)->get();

        return response()->json([
            'data' => NodeConfigResource::collection($versions)->resolve(),
            'active_version' => $configs->latest($node)?->version ?? $node->activeConfig?->version,
        ]);
    }

    public function store(PublishConfigRequest $request, Node $node, ConfigService $configs): JsonResponse
    {
        $this->authorize('publishConfig', $node);

        $yaml = (string) ($request->validated('content_yaml') ?? '');

        if (trim($yaml) === '') {
            $yaml = HysteriaConfigBuilder::render($node);
        }

        $overrides = (array) ($request->validated('overrides') ?? []);

        if ($overrides !== []) {
            $yaml = HysteriaConfigBuilder::renderWithOverrides($node, $overrides);
        }

        $config = $configs->publish(
            $node,
            $yaml,
            $request->user(),
            (string) ($request->validated('reason') ?? ''),
            ['channel' => 'admin-api'],
        );

        return response()->json([
            'data' => (new NodeConfigResource($config))->resolve(),
        ], 201);
    }

    public function rollback(RollbackConfigRequest $request, Node $node, int $version, ConfigService $configs): JsonResponse
    {
        $target = $node->configs()->where('version', $version)->firstOrFail();

        $this->authorize('rollback', $target);

        $config = $configs->rollback($node, $version, $request->user(), (string) ($request->validated('reason') ?? ''));

        return response()->json([
            'data' => (new NodeConfigResource($config))->resolve(),
            'rolled_back_to' => $version,
        ], 201);
    }

    public function show(Request $request, Node $node, int $version): JsonResponse
    {
        $this->authorize('viewAny', NodeConfig::class);

        $config = $node->configs()->where('version', $version)->firstOrFail();

        return response()->json([
            'data' => (new NodeConfigResource($config))->resolve(),
            'content_yaml' => $config->content_yaml,
        ]);
    }

    /** 就地修改某个版本的正文（sha256 会重算；改的是当前版本时节点下次同步即取走）。 */
    public function update(PublishConfigRequest $request, Node $node, int $version, ConfigService $configs): JsonResponse
    {
        $config = $node->configs()->where('version', $version)->firstOrFail();

        $this->authorize('publishConfig', $node);

        $yaml = (string) ($request->validated('content_yaml') ?? '');

        if (trim($yaml) === '') {
            return response()->json(['message' => 'content_yaml 不能为空'], 422);
        }

        $updated = $configs->updateContent($config, $yaml, $request->user(), (string) ($request->validated('reason') ?? ''));

        return response()->json([
            'data' => (new NodeConfigResource($updated))->resolve(),
            'content_yaml' => $updated->content_yaml,
        ]);
    }

    /** 指定「默认下发」的版本（运维说「就下这一版」）。 */
    public function activate(Request $request, Node $node, int $version, ConfigService $configs): JsonResponse
    {
        $this->authorize('publishConfig', $node);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $config = $configs->setCurrent($node, $version, $request->user(), (string) ($data['reason'] ?? ''));

        return response()->json([
            'data' => (new NodeConfigResource($config))->resolve(),
            'active_version' => $config->version,
        ]);
    }

    public function diff(Request $request, Node $node, int $version): JsonResponse
    {
        $this->authorize('viewAny', NodeConfig::class);

        $config = $node->configs()->where('version', $version)->firstOrFail();
        $previous = $config->previous();

        $stats = YamlDiff::stats($previous?->content_yaml ?? '', $config->content_yaml);

        return response()->json([
            'version' => $version,
            'against' => $previous?->version,
            'diff' => $stats['html'],
            'stats' => ['added' => $stats['added'], 'removed' => $stats['removed'], 'same' => $stats['same']],
        ]);
    }
}
