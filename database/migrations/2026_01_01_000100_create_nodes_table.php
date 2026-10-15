<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('node_id', 64)->unique();
            $table->string('secret_hash', 64);
            $table->string('secret_ciphertext', 512);
            $table->string('status', 16)->default('pending')->index();
            $table->string('ip', 45)->nullable();
            $table->string('region', 64)->nullable()->index();
            $table->json('tags')->nullable();
            $table->string('version', 32)->nullable();
            $table->string('agent_version', 32)->nullable();
            $table->json('meta')->nullable();
            $table->string('enrolled_ip', 45)->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
