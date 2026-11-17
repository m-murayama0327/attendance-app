<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    /**
     * 公開API で返す修正申請を、id、直したい時刻、備考、承認の状態、申請の休憩の配列にする。
     * 申請の時刻はアクセサで 09:00 の形に変わるので、勤怠の時刻と同じ 09:00:00 の形で返すために、getRawOriginal() でデータベースの値を読む。
     *
     * @param  Request  $request  届いたリクエスト
     * @return array<string, mixed> id、new_clock_in、new_clock_out、comment、is_approved、breaks を持つ配列
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'new_clock_in' => $this->getRawOriginal('new_clock_in'),
            'new_clock_out' => $this->getRawOriginal('new_clock_out'),
            'comment' => $this->comment,
            'is_approved' => $this->is_approved,
            'breaks' => ProposalBreakResource::collection($this->whenLoaded('proposalBreaks')),
        ];
    }
}
