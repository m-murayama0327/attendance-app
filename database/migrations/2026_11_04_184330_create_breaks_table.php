<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * breaks テーブルを作る。
     *
     * @return void 返す値はない
     */
    public function up(): void
    {
        Schema::create('breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->time('break_in');
            $table->time('break_out')->nullable();
            $table->timestamps();
        });
    }

    /**
     * breaks テーブルを消す。
     *
     * @return void 返す値はない
     */
    public function down(): void
    {
        Schema::dropIfExists('breaks');
    }
};
