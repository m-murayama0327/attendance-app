<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * applications テーブルを作る。
     *
     * @return void 返す値はない
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('attendance_record_id')->constrained()->cascadeOnDelete();
            $table->time('new_clock_in');
            $table->time('new_clock_out');
            $table->string('comment');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();
        });
    }

    /**
     * applications テーブルを消す。
     *
     * @return void 返す値はない
     */
    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
