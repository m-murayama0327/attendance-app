<?php

namespace Tests\Feature\App\Http\Requests\Api\V1;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IndexAttendanceRecordRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ユーザーIDが空なら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?user_id= を送り、rules() が user_id の nullable で通して、エラーにならず、200 が返り、data に勤怠の1行が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_user_id_is_accepted(): void
    {
        $response = $this->getIndex(['user_id' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * 日付が空なら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?date= を送り、rules() が date の nullable で通して、エラーにならず、200 が返り、data に勤怠の1行が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_date_is_accepted(): void
    {
        $response = $this->getIndex(['date' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * 年月が空なら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?month= を送り、rules() が month の nullable で通して、エラーにならず、200 が返り、data に勤怠の1行が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_month_is_accepted(): void
    {
        $response = $this->getIndex(['month' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * ページ番号が空なら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?page= を送り、rules() が page の nullable で通して、エラーにならず、200 が返り、data に勤怠の1行が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_page_is_accepted(): void
    {
        $response = $this->getIndex(['page' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * ページ番号が下限ちょうどなら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?page=1 を送り、rules() が page の min:1 で通して、エラーにならず、200 が返り、meta.current_page が 1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_page_one_is_accepted(): void
    {
        $response = $this->getIndex(['page' => 1]);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonPath('meta.current_page', 1);
    }

    /**
     * 1ページあたりの件数が空なら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page= を送り、rules() が per_page の nullable で通して、エラーにならず、200 が返り、data に勤怠の1行が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_per_page_is_accepted(): void
    {
        $response = $this->getIndex(['per_page' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonCount(1, 'data');
    }

    /**
     * 1ページあたりの件数が下限ちょうどなら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page=1 を送り、rules() が per_page の min:1 で通して、エラーにならず、200 が返り、meta.per_page が 1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_per_page_one_is_accepted(): void
    {
        $response = $this->getIndex(['per_page' => 1]);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonPath('meta.per_page', 1);
    }

    /**
     * 1ページあたりの件数が上限ちょうどなら、エラーにならずに通ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page=100 を送り、rules() が per_page の max:100 で通して、エラーにならず、200 が返り、meta.per_page が 100 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_per_page_max_is_accepted(): void
    {
        $response = $this->getIndex(['per_page' => 100]);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $response->assertJsonPath('meta.per_page', 100);
    }

    /**
     * ユーザーIDが整数でないと、ユーザーIDのエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?user_id=abc を送り、rules() が user_id の integer で止めて、422 が返り、errors の user_id に「ユーザーIDは整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_id_must_be_integer(): void
    {
        $response = $this->getIndex(['user_id' => 'abc']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['user_id' => 'ユーザーIDは整数で指定してください。']);
    }

    /**
     * 日付の形が違うと、日付の形のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?date=2026/05/01 を送り、rules() が date の date_format:Y-m-d で止めて、422 が返り、errors の date に「日付は YYYY-MM-DD 形式で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_format(): void
    {
        $response = $this->getIndex(['date' => '2026/05/01']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => '日付は YYYY-MM-DD 形式で指定してください。']);
    }

    /**
     * 年月の形が違うと、年月の形のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?month=2026/05 を送り、rules() が month の date_format:Y-m で止めて、422 が返り、errors の month に「年月は YYYY-MM 形式で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_month_format(): void
    {
        $response = $this->getIndex(['month' => '2026/05']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['month' => '年月は YYYY-MM 形式で指定してください。']);
    }

    /**
     * ページ番号が下限を1つ下回ると、ページ番号のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?page=0 を送り、rules() が page の min:1 で止めて、422 が返り、errors の page に「ページ番号は1以上の整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_page_zero_is_rejected(): void
    {
        $response = $this->getIndex(['page' => 0]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['page' => 'ページ番号は1以上の整数で指定してください。']);
    }

    /**
     * ページ番号が整数でないと、ページ番号のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?page=abc を送り、rules() が page の integer で止めて、422 が返り、errors の page に「ページ番号は1以上の整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_page_must_be_integer(): void
    {
        $response = $this->getIndex(['page' => 'abc']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['page' => 'ページ番号は1以上の整数で指定してください。']);
    }

    /**
     * 1ページあたりの件数が下限を1つ下回ると、1ページあたりの件数のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page=0 を送り、rules() が per_page の min:1 で止めて、422 が返り、errors の per_page に「1ページあたりの件数は1以上100以下の整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_per_page_zero_is_rejected(): void
    {
        $response = $this->getIndex(['per_page' => 0]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['per_page' => '1ページあたりの件数は1以上100以下の整数で指定してください。']);
    }

    /**
     * 1ページあたりの件数が上限を1つ超えると、1ページあたりの件数のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page=101 を送り、rules() が per_page の max:100 で止めて、422 が返り、errors の per_page に「1ページあたりの件数は1以上100以下の整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_per_page_over_max_is_rejected(): void
    {
        $response = $this->getIndex(['per_page' => 101]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['per_page' => '1ページあたりの件数は1以上100以下の整数で指定してください。']);
    }

    /**
     * 1ページあたりの件数が整数でないと、1ページあたりの件数のエラーで止まることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）が1行あるときに、ログインしていない人が GET /api/v1/attendance-records?per_page=abc を送り、rules() が per_page の integer で止めて、422 が返り、errors の per_page に「1ページあたりの件数は1以上100以下の整数で指定してください。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_per_page_must_be_integer(): void
    {
        $response = $this->getIndex(['per_page' => 'abc']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['per_page' => '1ページあたりの件数は1以上100以下の整数で指定してください。']);
    }

    /**
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）を1行作り、ログインしていない人として勤怠一覧にクエリを付けて送る。
     *
     * @param  array<string, mixed>  $query  URL の ? の後に付けるクエリの名前と値
     * @return TestResponse 送った後の応答
     */
    private function getIndex(array $query): TestResponse
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        return $this->getJson('/api/v1/attendance-records?'.http_build_query($query));
    }
}
