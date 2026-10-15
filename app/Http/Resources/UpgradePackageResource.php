<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UpgradePackageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'arch' => $this->arch,
            'url' => $this->url,
            'sha256' => $this->sha256,
            'signature' => $this->signature,
            'channel' => $this->channel,
            'rollout_percent' => $this->rollout_percent,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
