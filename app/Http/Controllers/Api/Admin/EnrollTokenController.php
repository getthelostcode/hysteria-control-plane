<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEnrollTokenRequest;
use App\Http\Resources\EnrollTokenResource;
use App\Models\EnrollToken;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EnrollToken::class);

        $tokens = EnrollToken::query()->orderByDesc('id')->paginate(min(200, (int) $request->integer('per_page', 25)));

        return response()->json([
            'data' => EnrollTokenResource::collection($tokens->items())->resolve(),
            'meta' => ['total' => $tokens->total(), 'current_page' => $tokens->currentPage()],
        ]);
    }

    public function store(StoreEnrollTokenRequest $request): JsonResponse
    {
        $this->authorize('create', EnrollToken::class);

        $validated = $request->validated();

        [$token, $plain] = EnrollToken::issue([
            'note' => $validated['note'] ?? null,
            'max_uses' => $validated['max_uses'] ?? 1,
            'expires_at' => now()->addDays($validated['expires_in_days'] ?? 7),
            'region_hint' => $validated['region_hint'] ?? null,
            'tags_hint' => $validated['tags_hint'] ?? null,
        ], $request->user()?->id);

        AuditLogger::log('enroll_token.created', $token, [
            'prefix' => $token->prefix,
            'max_uses' => $token->max_uses,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ], $request->user()?->name, $request->ip());

        return response()->json([
            'data' => (new EnrollTokenResource($token))->resolve(),
            'token' => $plain,
            'warning' => 'This token is shown once. It is stored only as a SHA-256 hash.',
        ], 201);
    }

    public function destroy(Request $request, EnrollToken $token): JsonResponse
    {
        $this->authorize('delete', $token);

        AuditLogger::log('enroll_token.revoked', $token, ['prefix' => $token->prefix], $request->user()?->name, $request->ip());

        $token->forceFill(['expires_at' => now()->subSecond(), 'max_uses' => $token->uses])->save();

        return response()->json(['ok' => true]);
    }
}
