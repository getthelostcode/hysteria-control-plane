<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['code', 'name', 'contact', 'status', 'notes'];

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    public function providerUsers(): HasMany
    {
        return $this->hasMany(ProviderUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function statusLabel(): string
    {
        return $this->isActive() ? '正常' : '已停用';
    }

    public function statusColor(): string
    {
        return $this->isActive() ? 'success' : 'danger';
    }

    /** 面板里显示用：code 是租户标识，也是节点 obfs 密码 */
    public function getFilamentName(): string
    {
        return $this->name;
    }
}
