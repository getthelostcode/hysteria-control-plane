<?php

declare(strict_types=1);

return [
    /* Signature (HMAC) settings for the agent control channel. */
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
