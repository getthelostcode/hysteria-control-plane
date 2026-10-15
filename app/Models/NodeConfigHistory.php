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

    /** 把某个历史版本设为当前下发版本（不一定回滚配置内容）。 */
    public const ACTION_ACTIVATE = 'activate';

    /** 就地把某个版本的正文改掉（sha256 变化 ⇒ 节点下次同步会取走新内容）。 */
    public const ACTION_EDIT = 'edit';

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
