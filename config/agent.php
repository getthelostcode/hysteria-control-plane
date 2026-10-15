<?php

declare(strict_types=1);

return [
    /* Signature (HMAC) settings for the agent control channel. */
        /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    | 部署在反向代理/隧道（Cloudflare Tunnel、nginx）后面时必须配置，否则 Laravel 拿不到
    | X-Forwarded-For，`$request->ip()` 会返回代理自己的地址（本机部署的 cloudflared → 127.0.0.1），
    | 节点 IP 列就会全是 127.0.0.1。
    |
    | 默认只信任回环地址：隧道/代理与本应用同机时这就够了。多跳代理请填上游网段列表。
    | 只有在应用**无法被直连**时才应该写 '*'（X-Forwarded-For 可伪造）。
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

'signature_ttl' => (int) env('AGENT_SIGNATURE_TTL', 300),

    'nonce_prefix' => env('AGENT_NONCE_PREFIX', 'agent:nonce'),

    /* Heartbeat / liveness. */
    'heartbeat_interval' => (int) env('AGENT_DEFAULT_HEARTBEAT', 30),

    'offline_threshold' => (int) env('AGENT_OFFLINE_THRESHOLD', 90),

    /* Ingest limits (protects the control plane from noisy agents). */
    'max_metric_samples' => (int) env('AGENT_MAX_METRIC_SAMPLES', 500),

    'max_log_entries' => (int) env('AGENT_MAX_LOG_ENTRIES', 500),

    'command_poll_limit' => (int) env('AGENT_COMMAND_POLL_LIMIT', 25),

    'command_ttl_minutes' => (int) env('AGENT_COMMAND_TTL_MINUTES', 30),

    /* Public endpoints handed back to agents on enroll. */
    'public_url' => env('AGENT_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    'websocket_url' => env('AGENT_WEBSOCKET_URL', env('REVERB_HOST', 'localhost')),

    'node_domain' => env('HYSTERIA_NODE_DOMAIN', 'example.com'),

    /* Only accept gzip payloads up to this size (bytes). */
    'max_payload_bytes' => (int) env('AGENT_MAX_PAYLOAD_BYTES', 4194304),
];
