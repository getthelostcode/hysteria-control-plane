<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent\Concerns;

use App\Http\Middleware\AgentAuth;
use App\Models\Node;
use Illuminate\Http\Request;

trait InteractsWithAgent
{
    protected function node(Request $request): Node
    {
        /** @var Node $node */
        $node = $request->attributes->get(AgentAuth::ATTRIBUTE);

        return $node;
    }

    /** @param array<string, mixed> $extra */
    protected function ok(array $extra = [], int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json(array_merge(['ok' => true], $extra), $status);
    }
}
