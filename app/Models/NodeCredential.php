<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeCredential extends Model
{
    use HasFactory;

    protected $fillable = ['node_id', 'secret_hash', 'secret_ciphertext', 'label', 'rotated_at', 'revoked_at'];

    protected $hidden = ['secret_ciphertext'];

    protected function casts(): array
    {
        return [
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
