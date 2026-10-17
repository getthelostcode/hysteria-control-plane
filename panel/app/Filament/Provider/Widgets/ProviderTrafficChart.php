<?php

namespace App\Filament\Provider\Widgets;

use App\Models\NodeTrafficDaily;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class ProviderTrafficChart extends ChartWidget
{
    protected static ?string $heading = '近 14 天流量（GB）';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '240px';

    protected function getData(): array
    {
        $provider = Filament::getTenant();

        $days = collect(range(13, 0))
            ->map(fn (int $i): string => now()->subDays($i)->toDateString());

        if (! $provider) {
            return [
                'datasets' => [['label' => '总流量', 'data' => array_fill(0, $days->count(), 0)]],
                'labels' => $days->map(fn (string $day): string => substr($day, 5))->all(),
            ];
        }

        $nodeIds = \App\Models\Node::query()->where('provider_id', $provider->id)->pluck('id');

        $rows = NodeTrafficDaily::query()
            ->whereIn('node_id', $nodeIds)
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
