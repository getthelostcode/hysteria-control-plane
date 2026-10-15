<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->decimal('cpu', 6, 2)->default(0);
            $table->decimal('mem', 6, 2)->default(0);
            $table->decimal('disk', 6, 2)->default(0);
            $table->unsignedBigInteger('net_rx')->default(0);
            $table->unsignedBigInteger('net_tx')->default(0);
            $table->unsignedInteger('conn_count')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->json('extra')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->index(['node_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_metrics');
    }
};
