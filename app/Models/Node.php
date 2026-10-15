<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Setting;
use App\Support\SignatureVerifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Node extends Model implements AuditableContract
{
    use AuditableTrait;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ONLINE = 'online';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    /** @var array<int, string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ONLINE,
        self::STATUS_OFFLINE,
        self::STATUS_ERROR,
        self::STATUS_DISABLED,
    ];

    protected $fillable = [
        'name', 'node_id', 'secret_hash', 'secret_ciphertext', 'status', 'ip', 'region',
        'tags', 'version', 'agent_version', 'meta', 'enrolled_ip', 'enrolled_at', 'last_seen_at',
    ];

    protected $hidden = ['secret_hash', 'secret_ciphertext'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'meta' => 'array',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public static function generateNodeId(): string
    {
        return 'nd_' . strtolower((string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'node_id';
    }

    // ---------------------------------------------------------------- relations

    /** @return HasMany<NodeMetric> */
    public function metrics(): HasMany
    {
        return $this->hasMany(NodeMetric::class);
    }

    /** @return HasOne<NodeMetric> */
    public function latestMetric(): HasOne
    {
        return $this->hasOne(NodeMetric::class)->latestOfMany('recorded_at');
    }

    /** @return HasMany<NodeConfig> */
    public function configs(): HasMany
    {
        return $this->hasMany(NodeConfig::class);
    }

    /** @return HasOne<NodeConfig> */
    public function latestConfig(): HasOne
    {
        return $this->hasOne(NodeConfig::class)->latestOfMany('version');
    }

    /** @return HasOne<NodeConfig> */
    public function activeConfig(): HasOne
    {
        return $this->hasOne(NodeConfig::class)->ofMany(
            ['version' => 'max'],
            static fn ($query) => $query->where('status', NodeConfig::STATUS_APPLIED),
        );
    }

    /** @return HasMany<NodeCommand> */
    public function commands(): HasMany
    {
        return $this->hasMany(NodeCommand::class);
    }

    /** @return HasMany<NodeLog> */
    public function logs(): HasMany
    {
        return $this->hasMany(NodeLog::class);
    }

    /** @return HasMany<NodeCredential> */
    public function credentials(): HasMany
    {
        return $this->hasMany(NodeCredential::class);
    }

    /** @return HasMany<NodeConfigHistory> */
    public function configHistory(): HasMany
    {
        return $this->hasMany(NodeConfigHistory::class);
    }

    // ------------------------------------------------------------------ scopes

    public function scopeOnline($query): void
    {
        $query->where('status', self::STATUS_ONLINE);
    }

    public function scopeOffline($query): void
    {
        $query->whereIn('status', [self::STATUS_OFFLINE, self::STATUS_PENDING]);
    }

    public function scopeHealthy($query): void
    {
        $query->whereNotIn('status', [self::STATUS_DISABLED, self::STATUS_ERROR]);
    }

    // ----------------------------------------------------------------- helpers

    public function isOnline(): bool
    {
        if ($this->status !== self::STATUS_ONLINE) {
            return false;
        }

        if ($this->last_seen_at === null) {
            return false;
        }

        return $this->last_seen_at->greaterThanOrEqualTo(
            now()->subSeconds((int) Setting::get('offline_threshold', 90))
        );
    }

    public function plainSecret(): string
    {
        $cipher = (string) $this->getRawOriginal('secret_ciphertext');

        try {
            return Crypt::decryptString($cipher);
        } catch (\Throwable) {
            return '';
        }
    }

    /** Current secret plus any non-revoked credentials (rotation grace window). */
    public function signingSecrets(): array
    {
        $secrets = array_filter([$this->plainSecret(), ...$this->credentials->map(function (NodeCredential $credential): string {
            try {
                return Crypt::decryptString($credential->secret_ciphertext);
            } catch (\Throwable) {
                return '';
            }
        })->all()]);

        return array_values($secrets);
    }

    /** Deterministic secrets derived from the node secret (rotation invalidates them). */
    public function statsSecret(): string
    {
        return substr(hash_hmac('sha256', 'stats:' . $this->node_id, $this->secret_hash), 0, 32);
    }

    public function obfsPassword(): string
    {
        return substr(hash_hmac('sha256', 'obfs:' . $this->node_id, $this->secret_hash), 0, 24);
    }

    public function rotateSecret(): string
    {
        [$plain, $hash, $cipher] = SignatureVerifier::issueSecret();

        $this->credentials()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $this->credentials()->create([
            'secret_hash' => $hash,
            'secret_ciphertext' => $cipher,
            'label' => 'rotated',
            'rotated_at' => now(),
        ]);

        $this->forceFill(['secret_hash' => $hash, 'secret_ciphertext' => $cipher])->save();

        return $plain;
    }

    public function displayTags(): string
    {
        return implode(', ', $this->tags ?? []);
    }
}
