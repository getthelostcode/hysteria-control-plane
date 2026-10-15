<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\NodeHealthTable;
use App\Filament\Widgets\NodeMapWidget;
use App\Filament\Widgets\RecentCommandsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\TrafficChartWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = '总览';

    protected static ?string $title = '运营总览';

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            TrafficChartWidget::class,
            NodeMapWidget::class,
            RecentCommandsWidget::class,
            NodeHealthTable::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
