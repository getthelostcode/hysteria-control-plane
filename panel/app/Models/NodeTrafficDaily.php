<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeTrafficDaily extends Model
{
    use HasFactory;

    protected $table = 'node_traffic_daily';

    protected $fillable = ['node_id', 'date', 'tx_bytes', 'rx_bytes', 'online_users'];

    protected function casts(): array
    {
        // date 不用 Carbon cast：库里存 "Y-m-d" 字符串，firstOrCreate / groupBy 的键才能直接对上
        return ['tx_bytes' => 'integer', 'rx_bytes' => 'integer', 'online_users' => 'integer'];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function totalBytes(): int
    {
        return (int) $this->tx_bytes + (int) $this->rx_bytes;
    }

    public function humanTotal(): string
    {
        return self::human((int) $this->totalBytes());
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return $i === 0 ? sprintf('%d %s', $value, $units[$i]) : sprintf('%.2f %s', $value, $units[$i]);
    }
}
