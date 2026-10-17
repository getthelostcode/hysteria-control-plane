<?php

namespace App\Filament\Provider\Widgets;

use App\Models\Node;
use App\Models\NodeTrafficDaily;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProviderStatsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $provider = Filament::getTenant();
        $ttl = (int) config('hysteria.node_online_ttl', 90);

        if (! $provider) {
            return [];
        }

        $nodes = Node::query()->where('provider_id', $provider->id);
        $total = (clone $nodes)->count();
        $online = (clone $nodes)
            ->where('status', Node::STATUS_ACTIVE)
            ->where('last_seen_at', '>=', now()->subSeconds($ttl))
            ->count();

        $nodeIds = (clone $nodes)->pluck('id');

        $today = (int) NodeTrafficDaily::query()
            ->whereIn('node_id', $nodeIds)
            ->where('date', now()->toDateString())
            ->sum(\DB::raw('tx_bytes + rx_bytes'));

        $week = (int) NodeTrafficDaily::query()
            ->whereIn('node_id', $nodeIds)
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->sum(\DB::raw('tx_bytes + rx_bytes'));

        return [
            Stat::make('我的节点', (string) $total)
                ->description('识别码 ' . $provider->code)
                ->color('info'),
            Stat::make('在线节点', "{$online} / {$total}")
                ->description($ttl . 's 内有心跳算在线')
                ->color($online > 0 ? 'success' : 'gray'),
            Stat::make('今日流量', NodeTrafficDaily::human($today))->color('primary'),
            Stat::make('近 7 天流量', NodeTrafficDaily::human($week))->color('gray'),
        ];
    }
}
