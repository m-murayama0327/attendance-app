<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAttendanceRecordRequest extends FormRequest
{
    /**
     * true を返す。勤怠を直してよい人かはコントローラーで確かめ、入力の誤りを先に 422 で返すため。
     *
     * @return bool いつも true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 勤怠の更新の決まりを返す。送られた項目だけを確かめる。
     * 日付の重複は、管理者が他の人の勤怠を直すときも正しく確かめられるように、ログインしている人ではなく勤怠の持ち主の勤怠で、直す勤怠そのものを除いて確かめる。
     * 出勤と退勤の前後は、片方だけ送られたときに今の値と比べる必要があるので、withValidator() で確かめる。
     *
     * @return array<string, array<int, mixed>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        $attendanceRecord = $this->route('attendanceRecord');

        return [
            'date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::unique('attendance_records')->where('user_id', $attendanceRecord->user_id)->ignore($attendanceRecord->id),
            ],
            'clock_in' => ['sometimes', 'required', 'date_format:H:i:s'],
            'clock_out' => ['nullable', 'date_format:H:i:s'],
            'comment' => ['nullable', 'string', 'max:'.AttendanceRecord::COMMENT_MAX_LENGTH],
        ];
    }

    /**
     * rules() で確かめた後に、出勤と退勤の前後と、今ある休憩が勤務の中に収まるかを確かめる。
     * 送られていない側は今の勤怠の値を使う。出勤か退勤の形が誤っているときと、どちらも送られていないときは比べない。
     * 退勤が空のときは、退勤との比べをしない。休憩と勤務が接する同じ時刻は通す。
     *
     * @param  Validator  $validator  rules() で確かめた結果を持つバリデーター
     * @return void 返す値はない
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('clock_in') && ! $this->has('clock_out')) {
                return;
            }

            if ($validator->errors()->hasAny(['clock_in', 'clock_out'])) {
                return;
            }

            $attendanceRecord = $this->route('attendanceRecord');
            $clockIn = $this->has('clock_in') ? $this->input('clock_in') : $attendanceRecord->clock_in;
            $clockOut = $this->has('clock_out') ? $this->input('clock_out') : $attendanceRecord->clock_out;

            if ($clockOut !== null && $clockOut <= $clockIn) {
                $validator->errors()->add($this->has('clock_out') ? 'clock_out' : 'clock_in', $this->messages()['clock_out.after']);

                return;
            }

            $breaks = $attendanceRecord->breaks;

            if ($breaks->contains(fn (AttendanceBreak $break): bool => $break->break_in < $clockIn)) {
                $validator->errors()->add('clock_in', $this->messages()['breaks.outside_work']);
            }

            if ($clockOut !== null && $breaks->contains(fn (AttendanceBreak $break): bool => $break->break_out === null || $break->break_out > $clockOut)) {
                $validator->errors()->add('clock_out', $this->messages()['breaks.outside_work']);
            }
        });
    }

    /**
     * 決まりに反したときの文言を返す。要件の FN060 の文言を使い、要件にない文言は公開API の他の文言の形にそろえて決めた。
     * clock_out.after と breaks.outside_work は withValidator() で付ける文言で、前後が逆のときは送った側のキーに付けるので、出勤のキーにも同じ文言を使う。
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
            'breaks.outside_work' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。',
            'comment.string' => '備考は文字で指定してください。',
            'comment.max' => '備考は '.AttendanceRecord::COMMENT_MAX_LENGTH.' 文字以内で入力してください。',
        ];
    }
}
