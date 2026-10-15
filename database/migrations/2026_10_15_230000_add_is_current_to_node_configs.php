<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 配置版本「当前生效/默认下发」标记。
 *
 * 之前「最新版本」= version 最大的那条，运维没法指定「就下发第 3 版」。
 * 现在每台节点有一个 is_current 的版本：发布新版本会自动成为 current，
 * 也可以在面板/API 上把任意旧版本设为 current（= 指定下载哪一版）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_configs', function (Blueprint $table): void {
            $table->boolean('is_current')->default(false)->after('status');
            $table->index(['node_id', 'is_current']);
        });

        // 回填：每台节点当前「最新版本」即默认 current，行为与之前保持一致。
        DB::table('node_configs')->distinct()->pluck('node_id')->each(function ($nodeId): void {
            $max = DB::table('node_configs')->where('node_id', $nodeId)->max('version');

            if ($max !== null) {
                DB::table('node_configs')
                    ->where('node_id', $nodeId)
                    ->where('version', $max)
                    ->update(['is_current' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('node_configs', function (Blueprint $table): void {
            $table->dropIndex(['node_id', 'is_current']);
            $table->dropColumn('is_current');
        });
    }
};
