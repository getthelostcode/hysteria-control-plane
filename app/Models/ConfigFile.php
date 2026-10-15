<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * 配置版本文件：与节点无关的一份 Hysteria 配置正文。
 *
 * 「默认版本」= 新建/采纳节点、或运维手工下发时默认取用的那一份（全库只有一个 default）。
 */
class ConfigFile extends Model implements AuditableContract
{
    use AuditableTrait;

    protected $fillable = [
        'version', 'note', 'content_yaml', 'sha256', 'is_default', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** 默认版本（全库唯一；没有就返回最新一版）。 */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return 'v' . $this->version;
    }

    public function shortHash(): string
    {
        return substr((string) $this->sha256, 0, 12);
    }
}
