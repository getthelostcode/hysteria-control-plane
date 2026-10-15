<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「已作废」需要与「已用尽」「已过期」区分开：三者对运维的含义完全不同
 * （作废=我主动撤的；用尽=有人用过了，可能不是我；过期=时间到了）。
 * 之前 revoke() 靠把 expires_at 推到过去 + max_uses 压到 uses 来伪造，
 * 结果三种状态在库里长得一模一样，报错也只能含糊其辞。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enroll_tokens', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('enroll_tokens', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
    }
};
