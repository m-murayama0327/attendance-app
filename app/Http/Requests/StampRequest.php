<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, array<int, string>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:clock_in,clock_out,break_in,break_out'],
        ];
    }
}
