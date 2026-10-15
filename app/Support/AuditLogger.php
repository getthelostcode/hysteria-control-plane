<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

final class AuditLogger
{
    /** @param array<string, mixed> $meta */
    public static function log(
        string $action,
        ?Model $target = null,
        array $meta = [],
        ?string $actor = null,
        ?string $ip = null,
    ): ?AuditLog {
        try {
            // Under `php artisan test` we are "in console" but a real HTTP
            // request is being simulated, so the actor IP must still be captured.
            $request = (app()->runningInConsole() && ! app()->runningUnitTests()) ? null : request();

            return AuditLog::create([
                'actor' => $actor ?? self::currentActor(),
                'actor_type' => $actor !== null ? 'external' : 'user',
                'action' => $action,
                'target' => $target?->getKey() !== null ? (string) $target->getKey() : null,
                'target_type' => $target !== null ? class_basename($target) : null,
                'meta' => self::normalize($meta),
                'ip' => $ip ?? ($request instanceof Request ? $request->ip() : null),
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function currentActor(): string
    {
        $user = auth()->user();

        if ($user === null) {
            return 'system';
        }

        return (string) ($user->name ?? $user->email ?? $user->getKey());
    }

    /** @param array<string, mixed> $meta */
    private static function normalize(array $meta): array
    {
        return array_map(static function (mixed $value): mixed {
            if ($value instanceof Arrayable) {
                return $value->toArray();
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            return is_scalar($value) || $value === null ? $value : json_encode($value);
        }, $meta);
    }
}
