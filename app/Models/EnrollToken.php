<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EnrollToken extends Model implements AuditableContract
{
    use HasFactory;

    use AuditableTrait;

    protected $fillable = [
        'token_hash', 'prefix', 'note', 'max_uses', 'uses', 'region_hint', 'tags_hint',
        'expires_at', 'used_at', 'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'tags_hint' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** @return array{0: self, 1: string} */
    public static function issue(array $attributes = [], ?int $createdBy = null): array
    {
        $plain = 'enr_' . Str::random(40);

        $token = static::create(array_merge([
            'token_hash' => self::hashToken($plain),
            'prefix' => substr($plain, 0, 12),
            'max_uses' => 1,
            'expires_at' => now()->addDays(7),
            'created_by' => $createdBy,
        ], $attributes));

        return [$token, $plain];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        if ($this->uses >= $this->max_uses) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function consume(): void
    {
        $this->increment('uses');
        $this->forceFill(['used_at' => now()])->save();
    }
}
