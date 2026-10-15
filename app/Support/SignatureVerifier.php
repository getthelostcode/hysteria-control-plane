<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * HMAC-SHA256 request signing for the agent control channel.
 *
 * Canonical string (exactly what the agent must sign):
 *   METHOD + "\n" + PATH + "\n" + sha256(rawBody) + "\n" + timestamp + "\n" + nonce
 *
 * Headers: X-Node-Id, X-Timestamp (unix seconds), X-Nonce (uuid/ulid), X-Node-Signature.
 * The signature is computed over the raw body bytes *as transmitted*
 * (i.e. still gzip-compressed when Content-Encoding: gzip is used).
 */
final class SignatureVerifier
{
    public const HEADER_NODE_ID = 'X-Node-Id';

    public const HEADER_TIMESTAMP = 'X-Timestamp';

    public const HEADER_NONCE = 'X-Nonce';

    public const HEADER_SIGNATURE = 'X-Node-Signature';

    public const DEFAULT_TTL = 300;

    public static function ttl(): int
    {
        return (int) config('agent.signature_ttl', self::DEFAULT_TTL);
    }

    public static function canonical(Request $request, string $rawBody): string
    {
        return implode("\n", [
            strtoupper($request->getMethod()),
            $request->getPathInfo(),
            hash('sha256', $rawBody),
            (string) $request->header(self::HEADER_TIMESTAMP),
            (string) $request->header(self::HEADER_NONCE),
        ]);
    }

    public static function sign(
        string $secret,
        string $method,
        string $path,
        string $rawBody,
        string|int $timestamp,
        string $nonce,
    ): string {
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            hash('sha256', $rawBody),
            (string) $timestamp,
            $nonce,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    public static function timestampFresh(string|int|null $timestamp): bool
    {
        if ($timestamp === null || ! is_numeric($timestamp)) {
            return false;
        }

        return abs(Date::now()->getTimestamp() - (int) $timestamp) <= self::ttl();
    }

    /** Replay protection: a nonce may only ever be used once per node inside the TTL window. */
    public static function registerNonce(string $nodeId, string $nonce): bool
    {
        return RateLimiter::attempt(
            'agent-nonce:' . $nodeId . ':' . $nonce,
            1,
            static fn () => true,
            self::ttl(),
        ) ?? false;
    }

    /**
     * Verify a signed request against every non-revoked credential of the node.
     */
    public static function verify(Request $request, Node $node, string $rawBody, ?string $signature = null): bool
    {
        $signature ??= (string) $request->header(self::HEADER_SIGNATURE);

        if ($signature === '' || ! self::timestampFresh($request->header(self::HEADER_TIMESTAMP))) {
            return false;
        }

        $canonical = self::canonical($request, $rawBody);

        foreach ($node->signingSecrets() as $secret) {
            $expected = hash_hmac('sha256', $canonical, $secret);

            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /** Issue a fresh node secret; returns [plain, sha256hash, ciphertext]. */
    public static function issueSecret(): array
    {
        $plain = 'nds_' . Str::random(48);

        return [$plain, hash('sha256', $plain), Crypt::encryptString($plain)];
    }

    /** Convenience for tests and the seeded demo agents. */
    public static function signedHeaders(Node $node, string $method, string $path, string $body = '', ?string $secret = null): array
    {
        $secret ??= $node->plainSecret();
        $timestamp = Date::now()->getTimestamp();
        $nonce = (string) Str::uuid();

        return [
            self::HEADER_NODE_ID => $node->node_id,
            self::HEADER_TIMESTAMP => (string) $timestamp,
            self::HEADER_NONCE => $nonce,
            self::HEADER_SIGNATURE => self::sign($secret, $method, $path, $body, $timestamp, $nonce),
        ];
    }
}
