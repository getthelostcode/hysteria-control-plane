<?php

namespace App\Filament\Widgets;

use App\Models\NodeTrafficDaily;
use Filament\Widgets\ChartWidget;

class TrafficTrendChart extends ChartWidget
{
    protected static ?string $heading = '近 14 天流量（GB）';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '240px';

    protected function getData(): array
    {
        $days = collect(range(13, 0))
            ->map(fn (int $i): string => now()->subDays($i)->toDateString());

        $rows = NodeTrafficDaily::query()
            ->where('date', '>=', $days->first())
            ->selectRaw('date, SUM(tx_bytes + rx_bytes) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $values = $days->map(fn (string $day): float => round(((int) ($rows[$day] ?? 0)) / 1024 / 1024 / 1024, 3));

        return [
            'datasets' => [
                [
                    'label' => '总流量',
                    'data' => $values->all(),
                    'fill' => 'start',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $days->map(fn (string $day): string => substr($day, 5))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
