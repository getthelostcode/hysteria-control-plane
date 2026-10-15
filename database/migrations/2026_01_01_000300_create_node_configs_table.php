<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('content_yaml');
            $table->string('sha256', 64);
            $table->string('status', 16)->default('published')->index();
            $table->text('error')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_configs');
    }
};
