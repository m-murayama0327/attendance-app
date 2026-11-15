<?php

namespace App\Http\Requests;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class StampRequest extends FormRequest
{
    /**
     * 打刻を送れる人は、ルートのミドルウェアで一般ユーザーに絞っているので、ここではだれでも送れるようにする。
     *
     * @return bool 常に true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 打刻の入力の決まりを返す。
     *
     * @return array<string, array<int, string|In>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(AttendanceRecord::ACTIONS)],
        ];
    }
}
