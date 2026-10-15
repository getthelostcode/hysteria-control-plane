<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('level', 16)->default('info')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['node_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_logs');
    }
};
