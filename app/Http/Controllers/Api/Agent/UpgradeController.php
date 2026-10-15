<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Api\Agent\Concerns\InteractsWithAgent;
use App\Http\Controllers\Controller;
use App\Models\UpgradePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpgradeController extends Controller
{
    use InteractsWithAgent;

    public function __invoke(Request $request): JsonResponse
    {
        $node = $this->node($request);
        $arch = (string) ($request->query('arch', 'amd64'));
        $current = (string) ($request->query('version', $node->agent_version ?? '0.0.0'));
        $channel = (string) ($request->query('channel', UpgradePackage::CHANNEL_STABLE));

        $candidate = UpgradePackage::query()
            ->where('arch', $arch)
            ->whereIn('channel', [$channel, UpgradePackage::CHANNEL_STABLE])
            ->where('is_active', true)
            ->orderByDesc('released_at')
            ->get()
            ->first(static fn (UpgradePackage $package): bool =>
                version_compare($package->version, $current, '>') && $package->inRolloutFor($node->node_id)
            );

        if ($candidate === null) {
            return $this->ok(['update_available' => false, 'current_version' => $current]);
        }

        return $this->ok([
            'update_available' => true,
            'current_version' => $current,
            'package' => [
                'version' => $candidate->version,
                'arch' => $candidate->arch,
                'url' => $candidate->url,
                'sha256' => $candidate->sha256,
                'signature' => $candidate->signature,
                'channel' => $candidate->channel,
                'released_at' => $candidate->released_at?->toIso8601String(),
            ],
        ]);
    }
}
