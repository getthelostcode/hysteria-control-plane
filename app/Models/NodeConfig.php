<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class NodeConfig extends Model implements AuditableContract
{
    use HasFactory;

    use AuditableTrait;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'node_id', 'version', 'content_yaml', 'sha256', 'status', 'error',
        'published_by', 'published_at', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function previous(): ?self
    {
        return static::query()
            ->where('node_id', $this->node_id)
            ->where('version', '<', $this->version)
            ->orderByDesc('version')
            ->first();
    }

    public function shortHash(): string
    {
        return substr($this->sha256, 0, 12);
    }
}
