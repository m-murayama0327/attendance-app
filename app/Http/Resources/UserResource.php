<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * 公開API で返す勤怠の持ち主を、id と name の配列にする。
     *
     * @param  Request  $request  届いたリクエスト
     * @return array<string, mixed> id と name を持つ配列
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
