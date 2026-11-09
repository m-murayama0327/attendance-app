<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttendanceDetailRequest extends FormRequest
{
    /**
     * 勤怠を直してよい人（勤怠の持ち主か管理者）かを、AttendanceRecordPolicy の update() で確かめる。
     * 入力を確かめる前に動くので、他の人が送ると、値によらず 403 になる。勤怠がなければ、ここに来る前にルートモデルバインディングが 404 にする。
     *
     * @return bool 直してよいなら true
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('attendanceRecord'));
    }

    /**
     * 修正申請の入力の決まりを返す。出勤と退勤の前後の決まりは、前後が逆のときに同じ文言を2回出さないよう、退勤にだけ付ける。
     *
     * @return array<string, array<int, string>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'new_clock_in' => ['required', 'date_format:H:i'],
            'new_clock_out' => ['required', 'date_format:H:i', 'after:new_clock_in'],
            'new_break_in' => ['array'],
            'new_break_out' => ['array'],
            'new_break_in.*' => ['nullable', 'required_with:new_break_out.*', 'date_format:H:i', 'after_or_equal:new_clock_in', 'before_or_equal:new_clock_out'],
            'new_break_out.*' => ['nullable', 'required_with:new_break_in.*', 'date_format:H:i', 'after:new_break_in.*', 'before_or_equal:new_clock_out'],
            'comment' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * rules() の決まりで確かめた後に、休憩どうしが重なっていないかを確かめる。
     * 休憩の項目と出勤、退勤にエラーがあるときは、形の誤った値で比べて誤ったエラーを重ねないよう、比べない。
     *
     * @param  Validator  $validator  rules() の決まりで確かめた結果を持つバリデーター
     * @return void 返す値はない
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['new_clock_in', 'new_clock_out', 'new_break_in', 'new_break_out', 'new_break_in.*', 'new_break_out.*'])) {
                return;
            }

            // 長い休憩の中に短い休憩が2つ入っても見逃さないよう、直前の休憩ではなく、それまでで一番遅い戻りと比べる
            $breaks = collect($this->input('new_break_in', []))
                ->map(fn ($breakIn, $index) => ['index' => $index, 'in' => $breakIn, 'out' => $this->input("new_break_out.{$index}")])
                ->filter(fn (array $break) => filled($break['in']) && filled($break['out']))
                ->sortBy('in');

            $latestOut = null;

            foreach ($breaks as $break) {
                if ($latestOut !== null && $break['in'] < $latestOut) {
                    $validator->errors()->add("new_break_in.{$break['index']}", '休憩時間が不適切な値です');
                }

                $latestOut = max($latestOut ?? $break['out'], $break['out']);
            }
        });
    }

    /**
     * 入力の決まりに反したときの文言を返す。文言は機能要件の FN029 のものを使い、FN029 にない場合は自分で決めた文言にする。
     *
     * @return array<string, string> 決まりごとの文言
     */
    public function messages(): array
    {
        return [
            'new_clock_in.required' => '出勤時間を入力してください',
            'new_clock_out.required' => '退勤時間を入力してください',
            'new_clock_in.date_format' => '出勤時間もしくは退勤時間が不適切な値です',
            'new_clock_out.date_format' => '出勤時間もしくは退勤時間が不適切な値です',
            'new_clock_out.after' => '出勤時間もしくは退勤時間が不適切な値です',
            'new_break_in.*.date_format' => '休憩時間が不適切な値です',
            'new_break_in.*.required_with' => '休憩時間が不適切な値です',
            'new_break_out.*.required_with' => '休憩時間が不適切な値です',
            'new_break_in.*.after_or_equal' => '休憩時間が不適切な値です',
            'new_break_in.*.before_or_equal' => '休憩時間が不適切な値です',
            'new_break_out.*.date_format' => '休憩時間が不適切な値です',
            'new_break_out.*.after' => '休憩時間が不適切な値です',
            'new_break_out.*.before_or_equal' => '休憩時間もしくは退勤時間が不適切な値です',
            'comment.required' => '備考を記入してください',
            'comment.max' => '備考は255文字以内で入力してください',
        ];
    }
}
