<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * テストごとに、画面の @vite を何もしないものに変える。
     * npm run dev を止めると public/hot も public/build もなく、@vite のある画面が描けないため。
     *
     * @return void 返す値はない
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
