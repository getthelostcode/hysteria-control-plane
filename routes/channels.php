<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Panel side channel authorization (web session or Sanctum token).
| Agents authenticate against /api/agent/v1/broadcasting/auth with their HMAC
| signature instead, so they never need a user account.
*/

Broadcast::channel('node.{nodeId}', static function (User $user, string $nodeId): bool {
    return $user->can('viewAny', Node::class);
});

Broadcast::channel('fleet', static function (User $user): bool {
    return $user->can('viewAny', Node::class);
});
