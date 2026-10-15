<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = ['actor', 'actor_type', 'action', 'target', 'target_type', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function summary(): string
    {
        return sprintf('%s %s', $this->actor ?? 'system', $this->action);
    }
}
