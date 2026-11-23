<?php

namespace Tests\Feature\App\Http\Requests\Api\V1;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpdateAttendanceRecordRequestTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceRecord $attendanceRecord;

    /**
     * 他の一般ユーザーが形の違う出勤時刻を送ると、持ち主かを確かめる前に出勤時刻の形のエラーで止まることを確かめる。
     * 一般ユーザーが他の人の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 09:00 にして送り、authorize() が送る人を使わずに通して、422 が返り、errors の clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_validation_error_before_authorization(): void
    {
        $owner = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($owner, '18:00:00', '13:00:00');
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->putAs($user, ['clock_in' => '09:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 直す勤怠そのものと同じ勤怠日を送っても、エラーにならずに通ることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026-05-01 にして送り、rules() が date の Rule::unique() で直す勤怠そのものを除いて通して、エラーにならず、200 が返り、勤怠の date は 2026-05-01 のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_date_as_own_record_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026-05-01']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-01',
        ]);
    }

    /**
     * 他の人に同じ日の勤怠があっても、勤怠日を直せることを確かめる。
     * 他の一般ユーザーの 2026-05-02 の勤怠があるときに、一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026-05-02 にして送り、rules() が date の Rule::unique() で重なりを確かめる勤怠を直す勤怠の持ち主の勤怠に絞って通して、エラーにならず、勤怠の date が 2026-05-02 に変わり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_same_date_is_accepted(): void
    {
        AttendanceRecord::factory()->create(['date' => '2026-05-02']);
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026-05-02']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-02',
        ]);
    }

    /**
     * 持ち主に同じ日の別の勤怠があると、勤怠日のエラーで止まることを確かめる。
     * 自分の 2026-05-02 の勤怠もあるときに、一般ユーザーが自分の 2026-05-01 の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026-05-02 にして送り、rules() が date の Rule::unique() で止めて、422 が返り、errors の date に「この日付の勤怠は既に登録されています。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_must_be_unique_per_owner(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-02',
        ]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026-05-02']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => 'この日付の勤怠は既に登録されています。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 勤怠日を送らなくても、他の項目を直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で date を送らずに clock_in を 08:00:00 にして送り、rules() が date の sometimes で送らない date を確かめずに、エラーにならず、勤怠の clock_in が 08:00:00 に変わり、200 が返り、date は 2026-05-01 のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_can_be_omitted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '08:00:00']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_in' => '08:00:00',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-01',
        ]);
    }

    /**
     * 出勤時刻を送らなくても、他の項目を直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を送らずに date を 2026-05-02 にして送り、rules() が clock_in の sometimes で送らない clock_in を確かめずに、エラーにならず、勤怠の date が 2026-05-02 に変わり、200 が返り、clock_in は 09:00:00 のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_can_be_omitted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026-05-02']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-02',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_in' => '09:00:00',
        ]);
    }

    /**
     * 退勤時刻を空にして送ると、エラーにならずに退勤時刻を空に直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_out を空にして送り、rules() が clock_out の nullable で通して、エラーにならず、勤怠の clock_out が空になり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_clock_out_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_out' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_out' => null,
        ]);
    }

    /**
     * 備考を空にして送ると、エラーにならずに備考を空に直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で comment を空にして送り、rules() が comment の nullable で通して、エラーにならず、勤怠の comment が空になり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_comment_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['comment' => '']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'comment' => null,
        ]);
    }

    /**
     * 備考が上限の長さちょうどなら、エラーにならずに備考を直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で comment を255文字にして送り、rules() が comment の max:255 で通して、エラーにならず、勤怠の comment が送った255文字になり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_of_max_length_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');
        $comment = str_repeat('あ', 255);

        $response = $this->putAs($user, ['comment' => $comment]);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'comment' => $comment,
        ]);
    }

    /**
     * 勤怠日を空にして送ると、勤怠日が必須のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で date を空にして送り、rules() が date の required で止めて、422 が返り、errors の date に「勤怠日は必須です。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_is_required_when_sent(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => '勤怠日は必須です。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 勤怠日の形が違うと、勤怠日の形のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026/05/02 にして送り、rules() が date の date_format:Y-m-d で止めて、422 が返り、errors の date に「勤怠日は YYYY-MM-DD 形式で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_format(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026/05/02']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => '勤怠日は YYYY-MM-DD 形式で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 出勤時刻を空にして送ると、出勤時刻が必須のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を空にして送り、rules() が clock_in の required で止めて、422 が返り、errors の clock_in に「出勤時刻は必須です。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_is_required_when_sent(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は必須です。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 出勤時刻の形が違うと、出勤時刻の形のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 09:00 にして送り、rules() が clock_in の date_format:H:i:s で止めて、422 が返り、errors の clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_format(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '09:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 退勤時刻の形が違うと、退勤時刻の形のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_out を 18:00 にして送り、rules() が clock_out の date_format:H:i:s で止めて、422 が返り、errors の clock_out に「退勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_format(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_out' => '18:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '退勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 備考が上限の長さを1つ超えると、備考のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で comment を256文字にして送り、rules() が comment の max:255 で止めて、422 が返り、errors の comment に「備考は 255 文字以内で入力してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_max_length(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['comment' => str_repeat('あ', 256)]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['comment' => '備考は 255 文字以内で入力してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 備考が文字でないと、備考のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で comment を配列 ['a'] にして送り、rules() が comment の string で止めて、422 が返り、errors の comment に「備考は文字で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_must_be_string(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['comment' => ['a']]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['comment' => '備考は文字で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 管理者が直すときも、持ち主に同じ日の別の勤怠があると、勤怠日のエラーで止まることを確かめる。
     * 一般ユーザーの 2026-05-02 の勤怠があるときに、管理者がその一般ユーザーの 2026-05-01 の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026-05-02 にして送り、rules() が date の Rule::unique() で重なりを送る人ではなく直す勤怠の持ち主の勤怠で確かめて止めて、422 が返り、errors の date に「この日付の勤怠は既に登録されています。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_update_checks_owner_dates(): void
    {
        $owner = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $owner->id,
            'date' => '2026-05-02',
        ]);
        $this->createAttendanceRecord($owner, '18:00:00', '13:00:00');
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->putAs($admin, ['date' => '2026-05-02']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['date' => 'この日付の勤怠は既に登録されています。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 持ち主に別の日の勤怠があっても、勤怠日を直せることを確かめる。
     * 自分の 2026-05-02 の勤怠もあるときに、一般ユーザーが自分の 2026-05-01 の勤怠に PUT /api/v1/attendance-records/{id} で date を 2026-05-03 にして送り、rules() が date の Rule::unique() で重なりを送った日と同じ日の勤怠だけで確かめて通して、エラーにならず、勤怠の date が 2026-05-03 に変わり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_date_of_owner_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-02',
        ]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['date' => '2026-05-03']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-03',
        ]);
    }

    /**
     * 今の退勤がない勤怠なら、退勤との前後と休憩の戻りを比べずに出勤時刻を直せることを確かめる。
     * 一般ユーザーが自分の勤怠（2026-05-01、出勤 09:00:00、退勤なし、休憩 12:00:00〜13:00:00）に PUT /api/v1/attendance-records/{id} で clock_in 08:00:00 だけを送り、withValidator() が退勤が空のときに退勤との前後と休憩の戻りを比べずに、エラーにならず、勤怠の clock_in が 08:00:00 に変わり、200 が返り、clock_out は空のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_times_are_not_compared_without_clock_out(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, null, '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '08:00:00']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_in' => '08:00:00',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_out' => null,
        ]);
    }

    /**
     * 戻りのない休憩がある勤怠に退勤時刻を送ると、退勤時刻の休憩のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠（2026-05-01、出勤 09:00:00、退勤なし、休憩 12:00:00〜戻りなし）に PUT /api/v1/attendance-records/{id} で clock_out 18:00:00 だけを送り、withValidator() が戻りのない休憩を送った退勤との間に収まっていないとして止めて、422 が返り、errors の clock_out に「休憩時間が出勤時刻と退勤時刻の間に収まっていません。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_open_break_rejects_clock_out(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, null, null);

        $response = $this->putAs($user, ['clock_out' => '18:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
            'comment' => null,
        ]);
    }

    /**
     * 出勤時刻が休憩の入りと同じなら、エラーにならずに出勤時刻を直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 12:00:00 にして送り、withValidator() が休憩の入りと同じ時刻の出勤を休憩が勤務の中にあるとして通して、エラーにならず、勤怠の clock_in が 12:00:00 に変わり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_on_break_in_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '12:00:00']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_in' => '12:00:00',
        ]);
    }

    /**
     * 退勤時刻が休憩の戻りと同じなら、エラーにならずに退勤時刻を直せることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_out を 13:00:00 にして送り、withValidator() が休憩の戻りと同じ時刻の退勤を休憩が勤務の中にあるとして通して、エラーにならず、勤怠の clock_out が 13:00:00 に変わり、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_on_break_out_is_accepted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_out' => '13:00:00']);

        $response->assertOk();
        $response->assertJsonMissingValidationErrors();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_out' => '13:00:00',
        ]);
    }

    /**
     * 出勤時刻の形が違うと、退勤時刻との前後を比べずに、退勤時刻にエラーが付かないことを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 09:00、clock_out を 08:00:00 にして送り、withValidator() が出勤か退勤に形のエラーがあるときに前後を比べずに、errors に clock_out のキーがなく、422 が返り、errors の clock_in に「出勤時刻は HH:MM:SS 形式で指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_times_are_not_compared_with_format_errors(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '09:00', 'clock_out' => '08:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonMissingValidationErrors('clock_out');
        $response->assertJsonValidationErrors(['clock_in' => '出勤時刻は HH:MM:SS 形式で指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 出勤時刻だけを今の退勤時刻と同じにして送ると、出勤時刻の前後のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 18:00:00 にして送り、withValidator() が clock_in だけを送ったときに今の退勤と比べて同じ時刻の出勤を止め、送った clock_in のキーにエラーを付けて、422 が返り、errors の clock_in に「退勤時刻は出勤時刻より後の時刻を指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_equal_to_current_clock_out_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '18:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '退勤時刻は出勤時刻より後の時刻を指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 出勤時刻が休憩の入りのすぐ後だと、出勤時刻の休憩のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 12:00:01 にして送り、withValidator() が休憩の入りより1秒でも後の出勤を休憩が勤務の外に出るとして止めて、422 が返り、errors の clock_in に「休憩時間が出勤時刻と退勤時刻の間に収まっていません。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_after_break_in_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '12:00:01']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_in' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 退勤時刻だけを今の出勤時刻と同じにして送ると、退勤時刻の前後のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_out を 09:00:00 にして送り、withValidator() が clock_out だけを送ったときに今の出勤と比べて同じ時刻の退勤を止め、送った clock_out のキーにエラーを付けて、422 が返り、errors の clock_out に「退勤時刻は出勤時刻より後の時刻を指定してください。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_equal_to_current_clock_in_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_out' => '09:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '退勤時刻は出勤時刻より後の時刻を指定してください。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 退勤時刻が休憩の戻りのすぐ前だと、退勤時刻の休憩のエラーで止まることを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_out を 12:59:59 にして送り、withValidator() が休憩の戻りより1秒でも前の退勤を休憩が勤務の外に出るとして止めて、422 が返り、errors の clock_out に「休憩時間が出勤時刻と退勤時刻の間に収まっていません。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_before_break_out_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_out' => '12:59:59']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 出勤時刻と退勤時刻の両方を前後が逆の値で送ると、前後のエラーが退勤時刻だけに付くことを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 19:00:00、clock_out を 18:00:00 にして送り、withValidator() が両方を送ったときに前後のエラーを clock_out のキーに付けて、422 が返り、errors の clock_out に「退勤時刻は出勤時刻より後の時刻を指定してください。」が出て、clock_in のキーがなく、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_order_error_goes_to_clock_out_when_both_are_sent(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '19:00:00', 'clock_out' => '18:00:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['clock_out' => '退勤時刻は出勤時刻より後の時刻を指定してください。']);
        $response->assertJsonMissingValidationErrors('clock_in');
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 休憩が出勤時刻の前と退勤時刻の後の両方にはみ出ると、出勤時刻と退勤時刻の両方に休憩のエラーが付くことを確かめる。
     * 一般ユーザーが自分の勤怠に PUT /api/v1/attendance-records/{id} で clock_in を 12:30:00、clock_out を 12:45:00 にして送り、withValidator() が休憩が出勤の前にも退勤の後にもはみ出るときに出勤と退勤の両方のキーにエラーを付けて、422 が返り、errors の clock_in と clock_out に「休憩時間が出勤時刻と退勤時刻の間に収まっていません。」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_outside_both_times_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user, '18:00:00', '13:00:00');

        $response = $this->putAs($user, ['clock_in' => '12:30:00', 'clock_out' => '12:45:00']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors([
            'clock_in' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。',
            'clock_out' => '休憩時間が出勤時刻と退勤時刻の間に収まっていません。',
        ]);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * その人の 2026-05-01 の勤怠（出勤 09:00:00）と休憩1行（入り 12:00:00）を作る。退勤と休憩の戻りは、引数の値にする。
     *
     * @param  User  $user  勤怠の持ち主
     * @param  string|null  $clockOut  勤怠の退勤（退勤がないときは null）
     * @param  string|null  $breakOut  休憩の戻り（戻りがないときは null）
     * @return void 返す値はない
     */
    private function createAttendanceRecord(User $user, ?string $clockOut, ?string $breakOut): void
    {
        $this->attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => $clockOut,
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $this->attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => $breakOut,
        ]);
    }

    /**
     * ユーザーを Sanctum で認証し、作った勤怠の更新に JSON を送る。条件に書いていない項目は送らない。
     *
     * @param  User  $user  送る人
     * @param  array<string, mixed>  $values  送る項目と値
     * @return TestResponse 送った後の応答
     */
    private function putAs(User $user, array $values): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->putJson('/api/v1/attendance-records/'.$this->attendanceRecord->id, $values);
    }

    /**
     * 送った値が誤っていたので、勤怠が 2026-05-01、出勤 09:00:00、退勤 18:00:00、備考なしのまま変わっていないかを確かめる。
     *
     * @return void 返す値はない
     */
    private function assertAttendanceRecordIsUnchanged(): void
    {
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => null,
        ]);
    }
}
