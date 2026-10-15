<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配置版本文件库。
 *
 * 与 node_configs（某个节点下发的第几版）不同：这里是**与节点无关的配置文件版本**，
 * 一台节点都没有也能先攒版本；节点下发时从库里挑一份内容即可。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version');
            $table->string('note', 200)->default('');
            $table->text('content_yaml');
            $table->char('sha256', 64);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('version');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_files');
    }
};
