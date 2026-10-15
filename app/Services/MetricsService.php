<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AggregateMetrics;
use App\Models\Node;
use App\Models\NodeMetric;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MetricsService
{
    /**
     * Persist a batch of samples for a node. Returns the number of rows stored.
     *
     * @param  array<int, array<string, mixed>>  $samples
     */
    public function ingest(Node $node, array $samples): int
    {
        $samples = array_slice($samples, 0, 500);

        if ($samples === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($samples as $sample) {
            $rows[] = [
                'node_id' => $node->id,
                'cpu' => (float) ($sample['cpu'] ?? 0),
                'mem' => (float) ($sample['mem'] ?? 0),
                'disk' => (float) ($sample['disk'] ?? 0),
                'net_rx' => (int) ($sample['net_rx'] ?? 0),
                'net_tx' => (int) ($sample['net_tx'] ?? 0),
                'conn_count' => (int) ($sample['conn_count'] ?? 0),
                'users' => (int) ($sample['users'] ?? 0),
                'extra' => isset($sample['extra']) && is_array($sample['extra'])
                    ? json_encode($sample['extra'])
                    : null,
                'recorded_at' => isset($sample['recorded_at'])
                    ? Carbon::parse((string) $sample['recorded_at'])
                    : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        NodeMetric::query()->insert($rows);

        AggregateMetrics::dispatch($node->id, $rows[array_key_last($rows)]['cpu'] ?? 0);

        return count($rows);
    }

    /**
     * Bucketed time series for chart widgets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function series(Node $node, string $range = '1h', int $points = 60): array
    {
        [$from, $seconds] = $this->window($range);
        $bucket = max(1, (int) floor($seconds / max(1, $points)));

        $rows = DB::table('node_metrics')
            ->where('node_id', $node->id)
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get(['cpu', 'mem', 'net_rx', 'net_tx', 'conn_count', 'users', 'recorded_at']);

        $grouped = [];

        foreach ($rows as $row) {
            $ts = Carbon::parse((string) $row->recorded_at)->getTimestamp();
            $slot = (int) (floor($ts / $bucket) * $bucket);

            $grouped[$slot] ??= [
                'timestamp' => $slot, 'cpu' => [], 'mem' => [], 'net_rx' => 0, 'net_tx' => 0,
                'conn_count' => 0, 'users' => 0, 'samples' => 0,
            ];

            $grouped[$slot]['cpu'][] = (float) $row->cpu;
            $grouped[$slot]['mem'][] = (float) $row->mem;
            $grouped[$slot]['net_rx'] = max($grouped[$slot]['net_rx'], (int) $row->net_rx);
            $grouped[$slot]['net_tx'] = max($grouped[$slot]['net_tx'], (int) $row->net_tx);
            $grouped[$slot]['conn_count'] = max($grouped[$slot]['conn_count'], (int) $row->conn_count);
            $grouped[$slot]['users'] = max($grouped[$slot]['users'], (int) $row->users);
            $grouped[$slot]['samples']++;
        }

        ksort($grouped);

        return array_values(array_map(static fn (array $slot): array => [
            'timestamp' => $slot['timestamp'],
            'label' => Carbon::createFromTimestamp($slot['timestamp'])->format('H:i'),
            'cpu' => round(array_sum($slot['cpu']) / max(1, count($slot['cpu'])), 2),
            'mem' => round(array_sum($slot['mem']) / max(1, count($slot['mem'])), 2),
            'net_rx' => $slot['net_rx'],
            'net_tx' => $slot['net_tx'],
            'conn_count' => $slot['conn_count'],
            'users' => $slot['users'],
        ], $grouped));
    }

    /** @return array{net_rx: int, net_tx: int, users: int, conn_count: int, samples: int} */
    public function totals(string $range = '24h'): array
    {
        [$from] = $this->window($range);

        $row = NodeMetric::query()
            ->where('recorded_at', '>=', $from)
            ->selectRaw('COALESCE(SUM(net_rx),0) as net_rx, COALESCE(SUM(net_tx),0) as net_tx, COALESCE(MAX(users),0) as users, COALESCE(MAX(conn_count),0) as conn_count, COUNT(*) as samples')
            ->first();

        return [
            'net_rx' => (int) ($row->net_rx ?? 0),
            'net_tx' => (int) ($row->net_tx ?? 0),
            'users' => (int) ($row->users ?? 0),
            'conn_count' => (int) ($row->conn_count ?? 0),
            'samples' => (int) ($row->samples ?? 0),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function fleetSeries(string $range = '1h', int $points = 60): array
    {
        [$from, $seconds] = $this->window($range);
        $bucket = max(1, (int) floor($seconds / max(1, $points)));

        $rows = DB::table('node_metrics')
            ->where('recorded_at', '>=', $from)
            ->orderBy('recorded_at')
            ->get(['net_rx', 'net_tx', 'users', 'conn_count', 'recorded_at']);

        $grouped = [];

        foreach ($rows as $row) {
            $ts = Carbon::parse((string) $row->recorded_at)->getTimestamp();
            $slot = (int) (floor($ts / $bucket) * $bucket);
            $grouped[$slot] ??= ['net_rx' => 0, 'net_tx' => 0, 'users' => 0, 'conn_count' => 0];
            $grouped[$slot]['net_rx'] += (int) $row->net_rx;
            $grouped[$slot]['net_tx'] += (int) $row->net_tx;
            $grouped[$slot]['users'] = max($grouped[$slot]['users'], (int) $row->users);
            $grouped[$slot]['conn_count'] += (int) $row->conn_count;
        }

        ksort($grouped);

        return array_values(array_map(static fn (int $ts, array $slot): array => [
            'timestamp' => $ts,
            'label' => Carbon::createFromTimestamp($ts)->format('H:i'),
            'net_rx' => $slot['net_rx'],
            'net_tx' => $slot['net_tx'],
            'users' => $slot['users'],
            'conn_count' => $slot['conn_count'],
        ], array_keys($grouped), $grouped));
    }

    public function prune(int $days): int
    {
        return NodeMetric::query()->where('recorded_at', '<', now()->subDays($days))->delete();
    }

    /** @return array{0: Carbon, 1: int} */
    private function window(string $range): array
    {
        $seconds = match ($range) {
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '6h' => 21600,
            '12h' => 43200,
            '7d' => 604800,
            '30d' => 2592000,
            default => 86400,
        };

        return [now()->subSeconds($seconds), $seconds];
    }
}
