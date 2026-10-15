<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transparently gunzips + decodes the agent body.
 *
 * The HMAC signature is always computed over the bytes as transmitted, so this
 * runs after AgentAuth and only rewrites what the controllers see via input().
 */
class DecodeAgentPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $encoding = strtolower((string) $request->header('Content-Encoding', ''));

        if (! str_contains($encoding, 'gzip')) {
            return $next($request);
        }

        $raw = (string) $request->getContent();
        $decoded = @gzdecode($raw);

        if ($decoded === false) {
            return response()->json([
                'error' => 'bad_gzip',
                'message' => 'Content-Encoding is gzip but the body could not be decoded.',
            ], 400);
        }

        $payload = json_decode($decoded, true);

        $request->replace(is_array($payload) ? $payload : []);
        $request->headers->set('Content-Type', 'application/json');

        return $next($request);
    }
}
