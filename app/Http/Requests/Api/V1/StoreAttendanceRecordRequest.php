<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttendanceRecordRequest extends FormRequest
{
    /**
     * 勤怠の登録はログインしている人の勤怠を作るだけで、他の人の勤怠を扱わないので、true を返す。ログインはルートの auth:sanctum が確かめる。
     *
     * @return bool いつも true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 勤怠の登録の決まりを返す。日付は、ログインしている人の同じ日の勤怠がないことも確かめる。
     *
     * @return array<string, array<int, mixed>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('attendance_records')->where('user_id', $this->user()->id)],
            'clock_in' => ['required', 'date_format:H:i:s'],
            'clock_out' => ['nullable', 'date_format:H:i:s', 'after:clock_in'],
            'comment' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 決まりに反したときの文言を返す。要件の FN060 の文言を使い、要件にない文字の型の文言は自分で決めた文言にする。
     *
     * @return array<string, string> 決まりごとの文言
     */
    public function messages(): array
    {
        return [
            'date.required' => '勤怠日は必須です。',
            'date.date_format' => '勤怠日は YYYY-MM-DD 形式で指定してください。',
            'date.unique' => 'この日付の勤怠は既に登録されています。',
            'clock_in.required' => '出勤時刻は必須です。',
            'clock_in.date_format' => '出勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.date_format' => '退勤時刻は HH:MM:SS 形式で指定してください。',
            'clock_out.after' => '退勤時刻は出勤時刻より後の時刻を指定してください。',
            'comment.string' => '備考は文字で指定してください。',
            'comment.max' => '備考は 255 文字以内で入力してください。',
        ];
    }
}
