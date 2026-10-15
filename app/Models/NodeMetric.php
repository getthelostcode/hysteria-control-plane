<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'node_id', 'cpu', 'mem', 'disk', 'net_rx', 'net_tx', 'conn_count', 'users', 'extra', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu' => 'float',
            'mem' => 'float',
            'disk' => 'float',
            'net_rx' => 'integer',
            'net_tx' => 'integer',
            'conn_count' => 'integer',
            'users' => 'integer',
            'extra' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
