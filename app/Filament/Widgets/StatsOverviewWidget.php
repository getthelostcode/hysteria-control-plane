<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use App\Services\MetricsService;
use App\Services\NodeService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '5s';

    /** Above the fold: never lazy load, the numbers must be in the first paint. */
    protected static bool $isLazy = false;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        if (! (Auth::user()?->can('viewAny', Node::class) ?? false)) {
            return [];
        }

        $stats = app(NodeService::class)->stats();
        $traffic = app(MetricsService::class)->totals('24h');

        return [
            Stat::make('节点总数', (string) $stats['total'])
                ->description('在线 ' . $stats['online'] . ' · 离线 ' . $stats['offline'])
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('primary'),
            Stat::make('在线率', $stats['total'] > 0
                ? round($stats['online'] / $stats['total'] * 100, 1) . '%'
                : '—')
                ->description('最近 90 秒有心跳')
                ->descriptionIcon('heroicon-m-signal')
                ->color($stats['online'] > 0 ? 'success' : 'danger'),
            Stat::make('24h 流量', self::humanBytes($traffic['net_rx'] + $traffic['net_tx']))
                ->description('↑ ' . self::humanBytes($traffic['net_tx']) . '  ↓ ' . self::humanBytes($traffic['net_rx']))
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),
            Stat::make('活跃用户', (string) $traffic['users'])
                ->description('并发连接 ' . number_format($traffic['conn_count']))
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),
        ];
    }

    public static function humanBytes(float|int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, $value >= 100 ? 0 : 1) . ' ' . $units[$index];
    }
}
