<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class IndexAttendanceRecordRequest extends FormRequest
{
    /**
     * 勤怠一覧は認証も認可も要らないので、だれにでも true を返す。
     *
     * @return bool いつも true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 勤怠一覧の絞り込みとページの指定の決まりを返す。どの項目も送らなくてよい。
     *
     * @return array<string, array<int, string>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * 決まりに反したときの文言を返す。要件に文言がないので、公開API の他の文言の形にそろえて決めた文言を使う。
     *
     * @return array<string, string> 決まりごとの文言
     */
    public function messages(): array
    {
        return [
            'user_id.integer' => 'ユーザーIDは整数で指定してください。',
            'date.date_format' => '日付は YYYY-MM-DD 形式で指定してください。',
            'month.date_format' => '年月は YYYY-MM 形式で指定してください。',
            'page.integer' => 'ページ番号は1以上の整数で指定してください。',
            'page.min' => 'ページ番号は1以上の整数で指定してください。',
            'per_page.integer' => '1ページあたりの件数は1以上100以下の整数で指定してください。',
            'per_page.min' => '1ページあたりの件数は1以上100以下の整数で指定してください。',
            'per_page.max' => '1ページあたりの件数は1以上100以下の整数で指定してください。',
        ];
    }
}
