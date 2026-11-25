<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    // users の name のカラムに入る文字数の上限で、これより長い名前は受けない。
    private const NAME_MAX_LENGTH = 255;

    // users の email のカラムに入る文字数の上限で、これより長いメールアドレスは受けない。
    private const EMAIL_MAX_LENGTH = 255;

    // 要件で決まったパスワードの文字数の下限で、これより短いパスワードは受けない。
    private const PASSWORD_MIN_LENGTH = 8;

    /**
     * 会員登録は、ログインしていない人が行うので、だれでも送れるようにする。
     *
     * @return bool 常に true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 会員登録の入力の決まりを返す。
     *
     * @return array<string, array<int, string>> 入力項目ごとの決まり
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX_LENGTH],
            'email' => ['required', 'email', 'max:'.self::EMAIL_MAX_LENGTH, 'unique:users'],
            'password' => ['required', 'string', 'min:'.self::PASSWORD_MIN_LENGTH],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    /**
     * 会員登録の入力が決まりに合わないときの文を返す。
     *
     * @return array<string, string> 決まりごとの文
     */
    public function messages(): array
    {
        return [
            'name.required' => 'お名前を入力してください',
            'name.string' => 'お名前は文字で入力してください',
            'name.max' => 'お名前は'.self::NAME_MAX_LENGTH.'文字以内で入力してください',
            'email.required' => 'メールアドレスを入力してください',
            'email.email' => 'メールアドレスはメール形式で入力してください',
            'email.max' => 'メールアドレスは'.self::EMAIL_MAX_LENGTH.'文字以内で入力してください',
            'email.unique' => 'このメールアドレスは既に登録されています',
            'password.required' => 'パスワードを入力してください',
            'password.string' => 'パスワードは文字で入力してください',
            'password.min' => 'パスワードは'.self::PASSWORD_MIN_LENGTH.'文字以上で入力してください',
            'password_confirmation.required' => 'パスワードと一致しません',
            'password_confirmation.same' => 'パスワードと一致しません',
        ];
    }
}
