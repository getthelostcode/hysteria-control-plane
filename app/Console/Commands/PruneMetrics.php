<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MetricsService;
use App\Support\Setting;
use Illuminate\Console\Command;

class PruneMetrics extends Command
{
    protected $signature = 'metrics:prune {--days= : Retention window in days}';

    protected $description = 'Delete metric samples outside the retention window';

    public function handle(MetricsService $metrics): int
    {
        $days = (int) ($this->option('days') ?: Setting::get('metrics_retention_days', 30));
        $deleted = $metrics->prune($days);

        $this->info(sprintf('Pruned %d metric row(s) older than %d day(s).', $deleted, $days));

        return self::SUCCESS;
    }
}
