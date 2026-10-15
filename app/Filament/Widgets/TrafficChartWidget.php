<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use App\Services\MetricsService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

class TrafficChartWidget extends ChartWidget
{
    protected static ?string $heading = '全网流量（5 秒刷新）';

    protected static ?string $pollingInterval = '5s';

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = '1h';

    /** @return array<string, int|float> */
    public function getData(): array
    {
        if (! (Auth::user()?->can('viewAny', Node::class) ?? false)) {
            return ['datasets' => [], 'labels' => []];
        }

        $series = app(MetricsService::class)->fleetSeries((string) $this->filter, 60);

        return [
            'datasets' => [
                [
                    'label' => '下行 (MB)',
                    'data' => array_map(static fn (array $row): float => round($row['net_rx'] / 1048576, 2), $series),
                    'borderColor' => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.25)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => '上行 (MB)',
                    'data' => array_map(static fn (array $row): float => round($row['net_tx'] / 1048576, 2), $series),
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'fill' => true,
                    'tension' => 0.35,
                ],
                [
                    'label' => '在线用户',
                    'data' => array_map(static fn (array $row): int => $row['users'], $series),
                    'borderColor' => 'rgb(249, 115, 22)',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.15)',
                    'tension' => 0.35,
                ],
            ],
            'labels' => array_map(static fn (array $row): string => (string) $row['label'], $series),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true]],
            'animation' => ['duration' => 400],
        ];
    }

    /** @return array<string, string>|null */
    protected function getFilters(): ?array
    {
        return [
            '1h' => '近 1 小时',
            '6h' => '近 6 小时',
            '24h' => '近 24 小时',
            '7d' => '近 7 天',
        ];
    }
}
