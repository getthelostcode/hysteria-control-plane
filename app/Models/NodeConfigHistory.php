<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeConfigHistory extends Model
{
    protected $table = 'node_config_history';

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_ACK = 'ack';

    public const ACTION_ROLLBACK = 'rollback';

    protected $fillable = ['node_id', 'from_version', 'to_version', 'action', 'operator', 'reason', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
