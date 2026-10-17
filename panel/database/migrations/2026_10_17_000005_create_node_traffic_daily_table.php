<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            // 字节数，tx = 节点上行（用户下载），rx = 节点下行（用户上传）
            $table->unsignedBigInteger('tx_bytes')->default(0);
            $table->unsignedBigInteger('rx_bytes')->default(0);
            $table->unsignedInteger('online_users')->default(0);
            $table->timestamps();

            $table->unique(['node_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_traffic_daily');
    }
};
