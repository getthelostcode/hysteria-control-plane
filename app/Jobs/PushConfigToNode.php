<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\ConfigPublished;
use App\Models\NodeConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued delivery of a freshly published config.
 *
 * The control plane never opens a connection to the node: delivery is a push on
 * the agent's private Reverb channel (fast path, ~instant) plus a "pending
 * version" marker that /config/latest serves on the REST fallback path.
 */
class PushConfigToNode implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(public int $configId)
    {
    }

    public function handle(): void
    {
        $config = NodeConfig::query()->with('node')->find($this->configId);

        if ($config === null || $config->node === null) {
            return;
        }

        Cache::put(
            'node:pending_config:' . $config->node_id,
            ['version' => $config->version, 'sha256' => $config->sha256],
            now()->addHours(6)
        );

        try {
            ConfigPublished::dispatch($config->node->node_id, [
                'version' => $config->version,
                'sha256' => $config->sha256,
                'status' => $config->status,
            ]);
        } catch (Throwable $exception) {
            Log::warning('config push failed on broadcast', [
                'node_id' => $config->node->node_id,
                'version' => $config->version,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
