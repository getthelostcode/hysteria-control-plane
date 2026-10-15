<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Node;
use App\Support\SignatureVerifier;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait SignedAgentRequests
{
    /**
     * Perform an HMAC signed agent request.
     *
     * NB: the signature covers the exact bytes sent, so this helper uses
     * TestCase::call() with a raw string body instead of json() (which would
     * re-encode the payload and invalidate the signature).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders
     */
    protected function agentCall(
        Node $node,
        string $method,
        string $path,
        array $payload = [],
        array $extraHeaders = [],
        bool $gzip = false,
        ?string $secret = null,
        array $overrides = [],
    ): TestResponse {
        $raw = $payload === [] ? '' : (string) json_encode($payload);
        $headers = [];

        if ($gzip) {
            $raw = (string) gzencode($raw);
            $headers['CONTENT_ENCODING'] = 'gzip';
        }

        $timestamp = $overrides['timestamp'] ?? (string) now()->getTimestamp();
        $nonce = $overrides['nonce'] ?? (string) Str::uuid();
        // The canonical string covers the URL path only: query strings are not signed.
        $canonicalPath = (string) (parse_url($path, PHP_URL_PATH) ?: $path);
        $signature = $overrides['signature'] ?? SignatureVerifier::sign(
            $secret ?? $node->plainSecret(),
            $method,
            $canonicalPath,
            $raw,
            $timestamp,
            $nonce,
        );

        // NB: TestCase::call() takes *server variables*, so header names must be
        // in HTTP_ form (a bare "X-Node-Id" key is silently dropped by ServerBag).
        $server = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_' . strtoupper(str_replace('-', '_', SignatureVerifier::HEADER_NODE_ID)) => $node->node_id,
            'HTTP_' . strtoupper(str_replace('-', '_', SignatureVerifier::HEADER_TIMESTAMP)) => $timestamp,
            'HTTP_' . strtoupper(str_replace('-', '_', SignatureVerifier::HEADER_NONCE)) => $nonce,
            'HTTP_' . strtoupper(str_replace('-', '_', SignatureVerifier::HEADER_SIGNATURE)) => $signature,
        ], $this->serverVars($headers), $this->serverVars($extraHeaders));

        return $this->call($method, $path, [], [], [], $server, $raw);
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function serverVars(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $out[str_starts_with($key, 'HTTP_') ? $key : 'HTTP_' . $key] = $value;
        }

        return $out;
    }

    protected function agentJson(Node $node, string $method, string $path, array $payload = []): TestResponse
    {
        return $this->withHeaders([
            'Accept' => 'application/json',
            SignatureVerifier::HEADER_NODE_ID => $node->node_id,
            SignatureVerifier::HEADER_TIMESTAMP => (string) now()->getTimestamp(),
            SignatureVerifier::HEADER_NONCE => (string) Str::uuid(),
            SignatureVerifier::HEADER_SIGNATURE => 'invalid',
        ])->json($method, $path, $payload);
        // withHeaders() already maps names to HTTP_* server vars.
    }
}
