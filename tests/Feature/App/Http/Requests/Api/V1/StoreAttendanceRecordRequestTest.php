<?php

namespace Tests\Feature\App\Http\Requests\Api\V1;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreAttendanceRecordRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 他の人に同じ日の勤怠があっても、送る人の勤怠を登録できることを確かめる。
     * 他の一般ユーザーの 2026-05-01 の勤怠があるときに、一般ユーザーが POST /api/v1/attendance-records で正しい値を送り、rules() が date の Rule::unique() で重なりを確かめる勤怠を送る人の勤怠に絞って通して、エラーにならず、送る人の 2026-05-01 の勤怠が1行でき、201 が返り、他の人の勤怠は変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_same_date_is_accepted(): void
    {
        $otherAttendanceRecord = AttendanceRecord::factory()->create([
            'date' => '2026-05-01',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
        ]);
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, []);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-05-01',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $otherAttendanceRecord->id,
            'user_id' => $otherAttendanceRecord->user_id,
            'date' => '2026-05-01',
            'clock_in' => '10:00:00',
            'clock_out' => '19:00:00',
        ]);
    }

    /**
     * 送る人に同じ日の勤怠がすでにあると、勤怠日のエラーで止まることを確かめる。
     * 送る人の 2026-05-01 の勤怠があるときに、一般ユーザーが POST /api/v1/attendance-records で正しい値を送り、rules() が date の Rule::unique() で止めて、422 が返り、errors の date に「この日付の勤怠は既に登録されています。」が出て、勤怠が増えないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_must_be_unique_per_user(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
        ]);

        $response = $this->postAs($user, []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => 'この日付の勤怠は既に登録されています。']);
        $this->assertDatabaseCount('attendance_records', 1);
    }

    /**
     * 退勤時刻が空なら、エラーにならずに勤怠を登録できることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_out を空にして送り、rules() が clock_out の nullable で通して、エラーにならず、勤怠が1行でき、clock_out が空で、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_clock_out_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_out' => '']);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'clock_out' => null,
        ]);
    }

    /**
     * 退勤時刻が出勤時刻のすぐ後なら、エラーにならずに勤怠を登録できることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で出勤 09:00:00、退勤 09:00:01 を送り、rules() が clock_out の after:clock_in で通して、エラーにならず、勤怠が1行でき、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_one_second_after_clock_in_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => '09:00:00', 'clock_out' => '09:00:01']);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 1);
    }

    /**
     * 備考が空なら、エラーにならずに勤怠を登録できることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で comment を空にして送り、rules() が comment の nullable で通して、エラーにならず、勤怠が1行でき、comment が空で、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_comment_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['comment' => '']);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'comment' => null,
        ]);
    }

    /**
     * 備考が上限の長さちょうどなら、エラーにならずに勤怠を登録できることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で comment を255文字にして送り、rules() が comment の max:255 で通して、エラーにならず、勤怠が1行でき、comment が送った255文字で、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_of_max_length_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $comment = str_repeat('あ', 255);

        $response = $this->postAs($user, ['comment' => $comment]);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'comment' => $comment,
        ]);
    }

    /**
     * 勤怠日が空だと、勤怠日が必須のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で date を空にして送り、rules() が date の required で止めて、422 が返り、errors の date に「勤怠日は必須です。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_is_required(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['date' => '']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => '勤怠日は必須です。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 勤怠日の形が違うと、勤怠日の形のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で date を 2026/05/01 にして送り、rules() が date の date_format:Y-m-d で止めて、422 が返り、errors の date に「勤怠日は YYYY-MM-DD 形式で指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_format(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['date' => '2026/05/01']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => '勤怠日は YYYY-MM-DD 形式で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 出勤時刻が空だと、出勤時刻が必須のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_in を空にし、clock_out を送らずに送り、rules() が clock_in の required で止めて、422 が返り、errors の clock_in に「出勤時刻は必須です。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_is_required(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => ''], ['clock_out']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は必須です。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 出勤時刻の形が違うと、出勤時刻の形のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_in を 09:00 にし、clock_out を送らずに送り、rules() が clock_in の date_format:H:i:s で止めて、422 が返り、errors の clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_format(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => '09:00'], ['clock_out']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 退勤時刻の形が違うと、退勤時刻には形のエラーだけが出て、前後のエラーは出ないことを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_out を 6時 にして送り、rules() が clock_out の date_format:H:i:s で止め、bail で after:clock_in まで比べずに、422 が返り、errors の clock_out が「退勤時刻は HH:MM:SS 形式で指定してください。」の1つだけで、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_invalid_clock_out_has_only_format_error(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_out' => '6時']);

        $response->assertUnprocessable();
        $response->assertJsonPath('errors.clock_out', ['退勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 退勤時刻が出勤時刻と同じだと、退勤時刻の前後のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で出勤と退勤を 09:00:00 にして送り、rules() が clock_out の after:clock_in で止めて、422 が返り、errors の clock_out に「退勤時刻は出勤時刻より後の時刻を指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_clock_in_and_out_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => '09:00:00', 'clock_out' => '09:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '退勤時刻は出勤時刻より後の時刻を指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 備考が上限の長さを1つ超えると、備考のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で comment を256文字にして送り、rules() が comment の max:255 で止めて、422 が返り、errors の comment に「備考は 255 文字以内で入力してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_max_length(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['comment' => str_repeat('あ', 256)]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['comment' => '備考は 255 文字以内で入力してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 備考が文字でないと、備考のエラーで止まることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で comment を配列 ['a'] にして送り、rules() が comment の string で止めて、422 が返り、errors の comment に「備考は文字で指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_must_be_string(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['comment' => ['a']]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['comment' => '備考は文字で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 送る人に別の日の勤怠があっても、勤怠を登録できることを確かめる。
     * 送る人の 2026-05-01 の勤怠があるときに、一般ユーザーが POST /api/v1/attendance-records で date を 2026-05-02 にして送り、rules() が date の Rule::unique() で重なりを送った日と同じ日の勤怠だけで確かめて通して、エラーにならず、送る人の 2026-05-02 の勤怠が1行でき、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_date_of_same_user_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
        ]);

        $response = $this->postAs($user, ['date' => '2026-05-02']);

        $response->assertCreated();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-05-02',
        ]);
    }

    /**
     * 出勤時刻の形が違うと、退勤時刻が正しくても退勤時刻に前後のエラーが付かないことを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_in を 09:00、clock_out を 18:00:00 にして送り、withValidator() が clock_in にエラーがあるときに正しい clock_out に付いた前後のエラーを消して、errors に clock_out のキーがなく、422 が返り、errors の clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_order_error_is_removed_when_clock_in_is_invalid(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => '09:00', 'clock_out' => '18:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonMissingValidationErrors('clock_out');
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 出勤時刻と退勤時刻の両方の形が違うと、退勤時刻の形のエラーが消えずに残ることを確かめる。
     * 一般ユーザーが POST /api/v1/attendance-records で clock_in を 09:00、clock_out を 6時 にして送り、withValidator() が前後のエラーだけを消して clock_out の形のエラーを残し、422 が返り、errors の clock_out に「退勤時刻は HH:MM:SS 形式で指定してください。」が出て、clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_format_error_is_kept_when_clock_in_is_invalid(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->postAs($user, ['clock_in' => '09:00', 'clock_out' => '6時']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '退勤時刻は HH:MM:SS 形式で指定してください。']);
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * ユーザーを Sanctum で認証し、勤怠の登録に JSON を送る。条件に書いていない項目は、date 2026-05-01、clock_in 09:00:00、clock_out 18:00:00、comment 在宅勤務のため を送る。
     *
     * @param  User  $user  送る人
     * @param  array<string, mixed>  $overrides  既定の値から変える項目と値
     * @param  array<int, string>  $omitted  送らない項目
     * @return TestResponse 送った後の応答
     */
    private function postAs(User $user, array $overrides, array $omitted = []): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/attendance-records', Arr::except(array_merge([
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '打刻漏れのため',
        ], $overrides), $omitted));
    }
}
