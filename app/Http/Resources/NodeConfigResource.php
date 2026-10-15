<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeConfigResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $withContent = $request->boolean('with_content', false);

        return array_filter([
            'id' => $this->id,
            'version' => $this->version,
            'sha256' => $this->sha256,
            'status' => $this->status,
            'error' => $this->error,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at?->toIso8601String(),
            'applied_at' => $this->applied_at?->toIso8601String(),
            'node' => new NodeResource($this->whenLoaded('node')),
            'content_yaml' => $withContent ? $this->content_yaml : null,
        ], static fn ($value) => $value !== null);
    }
}
