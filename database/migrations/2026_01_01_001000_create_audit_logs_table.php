<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor', 128)->nullable()->index();
            $table->string('actor_type', 64)->nullable();
            $table->string('action', 64)->index();
            $table->string('target', 191)->nullable();
            $table->string('target_type', 64)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
