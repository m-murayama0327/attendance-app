<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void 返す値はない
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            AttendanceRecordSeeder::class,
        ]);
    }
}
