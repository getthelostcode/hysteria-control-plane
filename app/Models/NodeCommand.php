<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class NodeCommand extends Model implements AuditableContract
{
    use HasFactory;

    use AuditableTrait;

    public const TYPE_RESTART = 'restart_hysteria';

    public const TYPE_RELOAD = 'reload_config';

    public const TYPE_UPGRADE = 'upgrade';

    public const TYPE_ROTATE_SECRET = 'rotate_secret';

    public const TYPE_DIAGNOSTICS = 'collect_diagnostics';

    public const TYPE_KICK_USER = 'kick_user';

    public const TYPE_TRAFFIC_RESET = 'traffic_reset';

    public const TYPE_SHELL = 'exec';

    /** @var array<string, string> */
    public const TYPES = [
        self::TYPE_RESTART => '重启 Hysteria2',
        self::TYPE_RELOAD => '热重载配置',
        self::TYPE_UPGRADE => '升级节点程序',
        self::TYPE_ROTATE_SECRET => '轮换节点密钥',
        self::TYPE_DIAGNOSTICS => '采集诊断信息',
        self::TYPE_KICK_USER => '踢出用户',
        self::TYPE_TRAFFIC_RESET => '清零流量统计',
        self::TYPE_SHELL => '自定义指令',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKED = 'acked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'command_id', 'node_id', 'type', 'payload', 'status', 'issued_by',
        'issued_at', 'delivered_at', 'acked_at', 'expires_at', 'result', 'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'issued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'acked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $command): void {
            $command->command_id ??= (string) Str::uuid();
            $command->issued_at ??= now();
            $command->expires_at ??= now()->addMinutes(30);
        });
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_ACKED, self::STATUS_FAILED, self::STATUS_EXPIRED], true);
    }
}
