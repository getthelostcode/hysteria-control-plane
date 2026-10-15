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
        'expires_at', 'used_at', 'revoked_at', 'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'tags_hint' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
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
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->uses >= $this->max_uses) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** 面板状态：可用 / 已用尽 / 已过期 / 已作废（四种含义对运维完全不同）。 */
    public function statusLabel(): string
    {
        if ($this->isRevoked()) {
            return '已作废';
        }

        if ($this->uses >= $this->max_uses) {
            return '已用尽';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return '已过期';
        }

        return '可用';
    }

    /**
     * 令牌被拒时给出**可操作**的原因。
     *
     * 运维在节点机上只看到一行 401，如果「从未存在过」「已用尽」「已过期」「复制到的是面板上的
     * 掩码值」混成同一句话，排查就是纯猜。这里按最可能的顺序分辨，并把前缀（不含明文）回给调用方。
     *
     * @return array{reason: string, message: string, prefix: string}
     */
    public static function diagnose(string $plain): array
    {
        $prefix = substr(trim($plain), 0, 12);

        if (trim($plain) !== $plain || $plain === '') {
            return [
                'reason' => 'token_malformed',
                'message' => '令牌为空或两端带空白字符（复制时带上了空格/换行），请去掉空白后重试。',
                'prefix' => $prefix,
            ];
        }

        $token = static::query()->where('token_hash', self::hashToken($plain))->first();

        if ($token !== null) {
            if ($token->isRevoked()) {
                return [
                    'reason' => 'token_revoked',
                    'message' => sprintf('该令牌已于 %s 被作废（面板操作），请重新签发。', $token->revoked_at?->toIso8601String() ?? '—'),
                    'prefix' => $prefix,
                ];
            }

            if ($token->uses >= $token->max_uses) {
                return [
                    'reason' => 'token_used',
                    'message' => sprintf(
                        '该令牌已用尽（%d/%d 次，最近一次 %s）。一次性令牌每次注册都会消耗，请重新签发。',
                        $token->uses, $token->max_uses,
                        $token->used_at?->toIso8601String() ?? '—',
                    ),
                    'prefix' => $prefix,
                ];
            }

            if ($token->expires_at !== null && $token->expires_at->isPast()) {
                return [
                    'reason' => 'token_expired',
                    'message' => sprintf('该令牌已于 %s 过期，请重新签发。', $token->expires_at->toIso8601String()),
                    'prefix' => $prefix,
                ];
            }

            return [
                'reason' => 'token_unusable',
                'message' => '该令牌当前不可用（已撤销或状态异常），请在面板确认。',
                'prefix' => $prefix,
            ];
        }

        // 前缀命中：绝大多数情况是复制到了面板列表里的掩码值（prefix + …）。
        $known = static::query()->where('prefix', $prefix)->first()
            ?? (strlen($prefix) >= 6
                ? static::query()->where('prefix', 'like', substr($prefix, 0, 6) . '%')->first()
                : null);

        if ($known !== null) {
            return [
                'reason' => 'token_truncated',
                'message' => sprintf(
                    '令牌与库里记录 #%d（备注：%s）前 %d 位一致但整体不匹配：控制面只保存哈希，界面/列表里显示的「%s…」是掩码，必须使用签发时给出的完整明文（44 字符）。'
                    . '明文只在创建时显示一次；如果已丢失，请作废该令牌并重新签发。',
                    $known->id, (string) ($known->note ?? '—'), strlen($prefix), $known->prefix,
                ),
                'prefix' => $prefix,
            ];
        }

        return [
            'reason' => 'token_unknown',
            'message' => '未找到该令牌：可能已删除、不属于本控制面，或复制不完整（完整令牌形如 enr_ + 40 位随机字符）。',
            'prefix' => $prefix,
        ];
    }

    /** 作废：只打标记，不改有效期与次数（记录保持真实，状态列才能如实显示）。 */
    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function canBeManagedBy(?\App\Models\User $user): bool
    {
        $roles = ['super-admin', 'admin', 'operator'];

        return $user !== null && $user->hasAnyRole($roles);
    }

    public function consume(): void
    {
        $this->increment('uses');
        $this->forceFill(['used_at' => now()])->save();
    }
}
