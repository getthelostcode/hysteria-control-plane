<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Node;

/**
 * Minimal HS256 JWT helper.
 *
 * hysteria-agent signs its channel JWT **locally** with node_secret as the HMAC
 * key (DESIGN.md §3.2), so the control plane only ever has to verify, never to
 * mint one. No third-party dependency needed for that.
 */
final class Jwt
{
    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, string $secret): string
    {
        $header = self::b64u((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64u((string) json_encode($claims));
        $signature = self::b64u(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Verify a token against one or more candidate secrets.
     *
     * @param  array<int, string>  $secrets
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, array $secrets): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode((string) self::unb64u($header), true);

        if (! is_array($decodedHeader) || ($decodedHeader['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', $header . '.' . $payload, '', true);
        $matched = false;

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }

            $expected = hash_hmac('sha256', $header . '.' . $payload, $secret, true);

            if (hash_equals($expected, (string) self::unb64u($signature))) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            return null;
        }

        $claims = json_decode((string) self::unb64u($payload), true);

        if (! is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && is_numeric($claims['exp']) && (int) $claims['exp'] < now()->getTimestamp()) {
            return null;
        }

        if (isset($claims['nbf']) && is_numeric($claims['nbf']) && (int) $claims['nbf'] > now()->getTimestamp() + 60) {
            return null;
        }

        return $claims;
    }

    /** Read the claims without verifying (used to look up the signing node). */
    public static function unverifiedClaims(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode((string) self::unb64u($parts[1]), true);

        return is_array($claims) ? $claims : null;
    }

    /** Resolve the node a JWT claims to belong to ("sub" = node_id). */
    public static function subjectNode(string $token): ?Node
    {
        $claims = self::unverifiedClaims($token);
        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        return Node::query()->where('node_id', $subject)->first();
    }

    private static function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64u(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
    }
}
