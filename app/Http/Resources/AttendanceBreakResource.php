<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceBreakResource extends JsonResource
{
    /**
     * 公開API で返す勤怠の休憩を、id と入りと戻りの時刻の配列にする。
     *
     * @param  Request  $request  届いたリクエスト
     * @return array<string, mixed> id、break_in、break_out を持つ配列
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'break_in' => $this->break_in,
            'break_out' => $this->break_out,
        ];
    }
}
