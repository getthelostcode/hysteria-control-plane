<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_commands', function (Blueprint $table): void {
            $table->id();
            $table->uuid('command_id')->unique();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('type', 64)->index();
            $table->json('payload')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['node_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_commands');
    }
};
