<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeCommandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'command_id' => $this->command_id,
            'node_id' => $this->whenLoaded('node', fn () => $this->node?->node_id, $this->node_id),
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'payload' => $this->payload ?? [],
            'status' => $this->status,
            'issued_by' => $this->issued_by,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'acked_at' => $this->acked_at?->toIso8601String(),
            'result' => $this->result,
            'error' => $this->error,
        ];
    }
}
