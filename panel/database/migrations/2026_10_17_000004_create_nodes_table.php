<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('ip', 45);
            $table->string('hostname', 190)->nullable();
            // 与 provider.code 同值：register-hysteria 用它作为 obfs salamander 密码
            $table->string('obfs_password', 64);
            $table->unsignedSmallInteger('traffic_port');
            // 加密存储（模型 casts: encrypted）
            $table->text('traffic_secret');
            $table->string('version', 32)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('api_token_hash', 64)->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'ip']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
