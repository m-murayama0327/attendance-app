<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * users テーブルに admin_status カラムを足す。
     *
     * @return void 返す値はない
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('admin_status')->default(false)->after('remember_token');
        });
    }

    /**
     * users テーブルから admin_status カラムを消す。
     *
     * @return void 返す値はない
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_status');
        });
    }
};
