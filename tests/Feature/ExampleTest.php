<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * ログインしていない人が `/` を開くと、ログイン画面へ移ることを確かめる。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
