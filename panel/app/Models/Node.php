<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Node extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'provider_id', 'name', 'ip', 'hostname', 'obfs_password',
        'traffic_port', 'traffic_secret', 'version', 'status', 'meta',
        'last_seen_at', 'api_token_hash',
    ];

    protected $hidden = ['traffic_secret', 'api_token_hash'];

    protected function casts(): array
    {
        return [
            'traffic_secret' => 'encrypted',
            'meta' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function trafficDaily(): HasMany
    {
        return $this->hasMany(NodeTrafficDaily::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function onlineTtl(): int
    {
        return (int) config('hysteria.node_online_ttl', 90);
    }

    /** 在线判定只看 DB 的 last_seen_at（Redis 里的 node:online:{id} 是给外部消费方用的加速副本） */
    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds($this->onlineTtl()));
    }

    public function isDisabled(): bool
    {
        return $this->status === self::STATUS_DISABLED;
    }

    public function runtimeStatusLabel(): string
    {
        if ($this->isDisabled()) {
            return '已禁用';
        }

        return $this->isOnline() ? '在线' : '离线';
    }

    public function runtimeStatusColor(): string
    {
        if ($this->isDisabled()) {
            return 'gray';
        }

        return $this->isOnline() ? 'success' : 'danger';
    }

    public function trafficToday(): ?NodeTrafficDaily
    {
        return $this->trafficDaily()->where('date', now()->toDateString())->first();
    }

    public function trafficTodayBytes(): int
    {
        $row = $this->trafficToday();

        return $row ? $row->totalBytes() : 0;
    }

    /** 首次注册/重置时签发，明文只返回这一次，库里只存 sha256 */
    public function issueApiToken(): string
    {
        $plain = 'nd_' . Str::random(48);
        $this->api_token_hash = hash('sha256', $plain);
        $this->save();

        return $plain;
    }

    public static function findByApiToken(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->where('api_token_hash', hash('sha256', $plain))->first();
    }

    /** 面板里给运维直接复制的客户端配置（服务器端口固定 443，由 install.sh 的 listen 决定） */
    public function clientConfigYaml(int $port = 443): string
    {
        return implode("\n", [
            "server: {$this->ip}:{$port}",
            'auth: <用户名:密码>   # 由鉴权服务发放',
            'obfs:',
            '  type: salamander',
            '  salamander:',
            "    password: {$this->obfs_password}",
            'tls:',
            '  insecure: true      # 自签证书；或换成 ca: /path/to/cert.pem',
        ]);
    }
}
