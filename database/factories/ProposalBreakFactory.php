<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ProposalBreak;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalBreak>
 */
class ProposalBreakFactory extends Factory
{
    /**
     * テスト用の申請の休憩の既定の値を返す。
     *
     * @return array<string, mixed> カラムの名前と既定の値の組
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'break_in' => '12:30:00',
            'break_out' => '13:30:00',
        ];
    }
}
