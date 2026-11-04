<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * proposal_breaks テーブルを作る。
     *
     * @return void 返す値はない
     */
    public function up(): void
    {
        Schema::create('proposal_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->time('break_in');
            $table->time('break_out');
            $table->timestamps();
        });
    }

    /**
     * proposal_breaks テーブルを消す。
     *
     * @return void 返す値はない
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_breaks');
    }
};
