<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * attendance_records テーブルを作る。
     *
     * @return void 返す値はない
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->date('date');
            $table->time('clock_in');
            $table->time('clock_out')->nullable();
            $table->string('comment')->nullable();
            $table->unsignedSmallInteger('work_minutes')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->unsignedSmallInteger('overtime_minutes')->nullable();
            $table->boolean('is_late')->default(false);
            $table->boolean('is_early_leave')->default(false);
            $table->boolean('is_long_work')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * attendance_records テーブルを消す。
     *
     * @return void 返す値はない
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
