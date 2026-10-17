<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class ProviderUser extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory;
    use Notifiable;

    protected $fillable = ['provider_id', 'name', 'email', 'password', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** 只有 provider 面板可登录，且所属服务商必须是正常状态 */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'provider' && $this->provider?->isActive() === true;
    }

    /** 只能进自己那一个租户 */
    public function canAccessTenant(Model $tenant): bool
    {
        return (int) $tenant->getKey() === (int) $this->provider_id;
    }

    public function getTenants(Panel $panel): Collection|array
    {
        return Provider::query()->whereKey($this->provider_id)->get();
    }
}
