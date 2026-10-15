<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeLog extends Model
{
    use HasFactory;

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'diagnostic'];

    protected $fillable = ['node_id', 'level', 'message', 'context', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
