<?php

namespace App\Actions\Fortify;

use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     * 入力の確かめは、要件のとおり FormRequest の RegisterRequest で行い、確かめた値でユーザーを作る。
     *
     * @param  array<string, string>  $input  Fortify から渡される入力。Fortify の決まりで受け取るが、確かめる前の値なので使わない
     * @return User 作ったユーザー
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $validated = app(RegisterRequest::class)->validated();

        return User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
    }
}
