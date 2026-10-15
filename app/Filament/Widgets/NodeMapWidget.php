<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Node;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class NodeMapWidget extends Widget
{
    protected static string $view = 'filament.widgets.node-map';

    protected static ?string $pollingInterval = '10s';

    protected int|string|array $columnSpan = 'full';

    /** Rough centroid per region slug; meta.lat/meta.lon on a node always wins. */
    public const REGION_COORDS = [
        'hk' => [22.3193, 114.1694], 'tw' => [25.0330, 121.5654], 'sg' => [1.3521, 103.8198],
        'jp' => [35.6762, 139.6503], 'kr' => [37.5665, 126.9780], 'us' => [37.7749, -122.4194],
        'us-west' => [37.7749, -122.4194], 'us-east' => [40.7128, -74.0060],
        'de' => [50.1109, 8.6821], 'uk' => [51.5074, -0.1278], 'fr' => [48.8566, 2.3522],
        'nl' => [52.3676, 4.9041], 'ru' => [55.7558, 37.6173], 'in' => [19.0760, 72.8777],
        'au' => [-33.8688, 151.2093], 'ca' => [43.6532, -79.3832], 'br' => [-23.5505, -46.6333],
        'ae' => [25.2048, 55.2708], 'tr' => [41.0082, 28.9784],
    ];

    /** @return array<int, array<string, mixed>> */
    public function getMarkers(): array
    {
        $markers = [];

        foreach (Node::query()->with('latestMetric')->get() as $node) {
            $meta = $node->meta ?? [];
            $coordinates = isset($meta['lat'], $meta['lon'])
                ? [(float) $meta['lat'], (float) $meta['lon']]
                : self::REGION_COORDS[strtolower((string) $node->region)] ?? null;

            if ($coordinates === null) {
                continue;
            }

            $markers[] = [
                'name' => $node->name,
                'node_id' => $node->node_id,
                'region' => $node->region,
                'status' => $node->isOnline() ? 'online' : 'offline',
                'users' => (int) ($node->latestMetric->users ?? 0),
                'cpu' => (float) ($node->latestMetric->cpu ?? 0),
                'lat' => $coordinates[0],
                'lon' => $coordinates[1],
            ];
        }

        return $markers;
    }

    public static function canView(): bool
    {
        return Auth::user()?->can('viewAny', Node::class) ?? false;
    }
}
