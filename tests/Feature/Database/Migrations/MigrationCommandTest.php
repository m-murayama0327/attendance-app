<?php

namespace Tests\Feature\Database\Migrations;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationCommandTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * 全部のテーブルを消した空のデータベースで migrate --seed を動かすと、成功で終わることを確かめる。
     * テストから db:wipe で全部のテーブルを消した後に migrate --seed を動かし、全部のマイグレーションの up() と DatabaseSeeder の run() がテーブルを作ってデータを入れて、2つのコマンドが成功で終わるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_migrate_seed_runs(): void
    {
        $this->artisan('db:wipe')->assertSuccessful();

        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();
    }

    /**
     * 全部のマイグレーションを動かした後に migrate を動かすと、成功で終わって9つのテーブルがあることを確かめる。
     * DatabaseMigrations が全部のマイグレーションを動かしたときに、テストから migrate を動かし、全部の up() がテーブルを作って、コマンドが成功で終わり、users、password_reset_tokens、failed_jobs、personal_access_tokens、attendance_records、breaks、applications、proposal_breaks、migrations の9つのテーブルがあるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_tables_exist(): void
    {
        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasTable('attendance_records'));
        $this->assertTrue(Schema::hasTable('breaks'));
        $this->assertTrue(Schema::hasTable('applications'));
        $this->assertTrue(Schema::hasTable('proposal_breaks'));
        $this->assertTrue(Schema::hasTable('migrations'));
    }

    /**
     * 全部のマイグレーションを動かした後に migrate:rollback --step=4 を動かすと、自分たちの4つのテーブルがなくなって users が残ることを確かめる。
     * DatabaseMigrations が全部のマイグレーションを動かしたときに、テストから migrate:rollback --step=4 を動かし、自分たちの4つの down() がテーブルを消して、コマンドが成功で終わり、attendance_records、breaks、applications、proposal_breaks の4つのテーブルがなく、users があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_rollback_removes_changes(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('attendance_records'));
        $this->assertFalse(Schema::hasTable('breaks'));
        $this->assertFalse(Schema::hasTable('applications'));
        $this->assertFalse(Schema::hasTable('proposal_breaks'));
        $this->assertTrue(Schema::hasTable('users'));
    }
}
