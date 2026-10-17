<?php

namespace App\Filament\Widgets;

use App\Models\Node;
use App\Models\NodeTrafficDaily;
use App\Models\Provider;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    // 首屏就要出数：Filament 的 Widget 默认懒加载，HTML 断言会拿到骨架屏
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $ttl = (int) config('hysteria.node_online_ttl', 90);

        $providers = Provider::query()->count();
        $nodes = Node::query()->count();
        $online = Node::query()
            ->where('status', Node::STATUS_ACTIVE)
            ->where('last_seen_at', '>=', now()->subSeconds($ttl))
            ->count();

        $today = (int) NodeTrafficDaily::query()
            ->where('date', now()->toDateString())
            ->sum(\DB::raw('tx_bytes + rx_bytes'));

        $week = (int) NodeTrafficDaily::query()
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->sum(\DB::raw('tx_bytes + rx_bytes'));

        return [
            Stat::make('服务商', (string) $providers)
                ->description('已登记的服务商数量')
                ->color('info'),
            Stat::make('在线节点', "{$online} / {$nodes}")
                ->description('在线判定：' . $ttl . 's 内有心跳')
                ->color($online > 0 ? 'success' : 'gray'),
            Stat::make('今日流量', NodeTrafficDaily::human($today))
                ->description('全部节点 tx + rx')
                ->color('primary'),
            Stat::make('近 7 天流量', NodeTrafficDaily::human($week))
                ->description('同期累计')
                ->color('gray'),
        ];
    }
}
