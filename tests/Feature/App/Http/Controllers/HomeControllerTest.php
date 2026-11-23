<?php

namespace Tests\Feature\App\Http\Controllers;

use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    /**
     * 開く人がログインしていない人なら、/login に移ることを確かめる。
     * ログインしていない人が GET / を送り、__invoke() がログインしているかで移る先を決めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
