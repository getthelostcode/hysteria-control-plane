<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EnrollTokenResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prefix' => $this->prefix,
            'note' => $this->note,
            'max_uses' => $this->max_uses,
            'uses' => $this->uses,
            'region_hint' => $this->region_hint,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'is_usable' => $this->isUsable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
