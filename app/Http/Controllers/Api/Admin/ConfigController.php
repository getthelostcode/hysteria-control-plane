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
    public function index(Request $request, Node $node): JsonResponse
    {
        $this->authorize('viewAny', NodeConfig::class);

        $configs = $node->configs()->orderByDesc('version')->limit(100)->get();

        return response()->json([
            'data' => NodeConfigResource::collection($configs)->resolve(),
            'active_version' => $node->activeConfig?->version,
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
