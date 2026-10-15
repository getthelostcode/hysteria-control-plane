<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use App\Services\MetricsService;
use Filament\Widgets\Widget;

class NodeDetailWidget extends Widget
{
    protected static string $view = 'filament.widgets.node-detail';

    protected static ?string $pollingInterval = '5s';

    /** Primary content of the node view page: render it in the first paint. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static ?int $nodeId = null;

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $node = Node::query()->with(['latestMetric', 'latestConfig'])->find(self::$nodeId);

        if ($node === null) {
            return ['node' => null, 'metrics' => [], 'commands' => collect(), 'configs' => collect()];
        }

        return [
            'node' => $node,
            'metrics' => app(MetricsService::class)->series($node, '6h', 48),
            'commands' => $node->commands()->latest('id')->limit(5)->get(),
            'configs' => $node->configs()->orderByDesc('version')->limit(5)->get(),
        ];
    }
}
