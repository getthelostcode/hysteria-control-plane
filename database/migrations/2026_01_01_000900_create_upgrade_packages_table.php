<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_packages', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 32)->index();
            $table->string('arch', 16)->default('amd64');
            $table->string('url', 512);
            $table->string('sha256', 64);
            $table->text('signature')->nullable();
            $table->string('channel', 16)->default('stable')->index();
            $table->unsignedTinyInteger('rollout_percent')->default(100);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['version', 'arch', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_packages');
    }
};
