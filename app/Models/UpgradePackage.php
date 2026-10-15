<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class UpgradePackage extends Model implements AuditableContract
{
    use HasFactory;

    use AuditableTrait;

    public const CHANNEL_STABLE = 'stable';

    public const CHANNEL_BETA = 'beta';

    public const CHANNEL_CANARY = 'canary';

    protected $fillable = [
        'version', 'arch', 'url', 'sha256', 'signature', 'channel', 'rollout_percent',
        'is_active', 'notes', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rollout_percent' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    public function shortHash(): string
    {
        return substr((string) $this->sha256, 0, 12);
    }

    public function inRolloutFor(string $nodeId): bool
    {
        if ($this->rollout_percent >= 100) {
            return true;
        }

        return (abs(crc32($nodeId)) % 100) < $this->rollout_percent;
    }
}
