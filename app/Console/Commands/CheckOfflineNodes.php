<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\NodeService;
use App\Support\Setting;
use Illuminate\Console\Command;

class CheckOfflineNodes extends Command
{
    protected $signature = 'nodes:check-offline {--threshold= : Seconds without a heartbeat before a node is marked offline}';

    protected $description = 'Mark nodes whose last heartbeat is older than the threshold as offline';

    public function handle(NodeService $nodes): int
    {
        $threshold = (int) ($this->option('threshold') ?: Setting::get('offline_threshold', 90));
        $cutoff = now()->subSeconds($threshold);

        $stale = Node::query()
            ->whereIn('status', [Node::STATUS_ONLINE, Node::STATUS_ERROR])
            ->where(static function ($query) use ($cutoff): void {
                $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff);
            })
            ->get();

        foreach ($stale as $node) {
            $nodes->markOffline($node);
        }

        $this->info(sprintf('Marked %d node(s) offline (threshold %ds).', $stale->count(), $threshold));

        return self::SUCCESS;
    }
}
