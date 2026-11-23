<?php

namespace Tests\Feature\App\Exceptions;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HandlerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * API で番号の勤怠がないと、勤怠が見つからないという要件の文言を JSON で返すことを確かめる。
     * 番号 99999 の勤怠がないときに、ログインしていない人が GET /api/v1/attendance-records/99999 を開き、register() が足した2つの renderable() で勤怠の見つからない 404 を要件の文言の JSON にして、404 で応答が {"error": "勤怠情報が見つかりませんでした。"} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_error_json(): void
    {
        $response = $this->getJson('/api/v1/attendance-records/99999');

        $response->assertNotFound();
        $response->assertExactJson(['error' => '勤怠情報が見つかりませんでした。']);
    }

    /**
     * API で他の一般ユーザーが勤怠を書き換えようとすると、権限がないという要件の文言を JSON で返すことを確かめる。
     * 一般ユーザー A の勤怠（2026-06-01、09:00〜18:00）があるときに、一般ユーザー B が Sanctum::actingAs() で PUT /api/v1/attendance-records/{id} に date 2026-06-01、clock_in 08:30:00、clock_out 18:00:00 を送り、register() が足した2つの renderable() で AttendanceRecordPolicy.php に止められた 403 を要件の文言の JSON にして、403 で応答が {"error": "この操作を実行する権限がありません。"} になり、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_forbidden_returns_error_json(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        Sanctum::actingAs($otherUser);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, [
            'date' => '2026-06-01',
            'clock_in' => '08:30:00',
            'clock_out' => '18:00:00',
        ]);

        $response->assertForbidden();
        $response->assertExactJson(['error' => 'この操作を実行する権限がありません。']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }

    /**
     * ルートのない API のパスを開くと、勤怠が見つからないという文言を返さないことを確かめる。
     * ログインしていない人が GET /api/foo を開き、register() が足した2つの renderable() で元の例外が ModelNotFoundException でない 404 を勤怠の文言にせず、404 で応答に「勤怠情報が見つかりませんでした。」がないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_route_has_no_record_message(): void
    {
        $response = $this->getJson('/api/foo');

        $response->assertNotFound();
        $response->assertJsonMissing(['error' => '勤怠情報が見つかりませんでした。']);
    }

    /**
     * API でないパスでは、番号の勤怠がなくても、勤怠が見つからないという文言の JSON を返さないことを確かめる。
     * 番号 99999 の勤怠がないときに、一般ユーザーが GET /attendance/99999 を開き、register() が足した2つの renderable() で api/* でないパスの 404 を勤怠の文言の JSON にせず、404 で応答が {"error": "勤怠情報が見つかりませんでした。"} の JSON でないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_web_missing_record_is_not_error_json(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance/99999');

        $response->assertNotFound();
        $this->assertNotSame(['error' => '勤怠情報が見つかりませんでした。'], json_decode($response->getContent(), true));
    }

    /**
     * API でないパスでは、他の一般ユーザーが勤怠を開いても、権限がないという文言の JSON を返さないことを確かめる。
     * 一般ユーザー A の勤怠（2026-06-01、09:00〜18:00）があるときに、一般ユーザー B が GET /attendance/{id} で A の勤怠を開き、register() が足した2つの renderable() で api/* でないパスの 403 を権限の文言の JSON にせず、403 で応答が {"error": "この操作を実行する権限がありません。"} の JSON でないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_web_forbidden_is_not_error_json(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($otherUser)->get('/attendance/'.$attendanceRecord->id);

        $response->assertForbidden();
        $this->assertNotSame(['error' => 'この操作を実行する権限がありません。'], json_decode($response->getContent(), true));
    }

    /**
     * ログインしていない人が Accept なしで API のパスに送ると、ログインが要ることを JSON で返すことを確かめる。
     * ログインしていない人が Accept なしで POST /api/v1/attendance-records に date 2026-06-01 と clock_in 09:00:00 を送り、shouldReturnJson() が api/* のパスなので JSON で返すと決めて、401 で応答が {"message": "Unauthenticated."} になり、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_api_guest_gets_unauthenticated_json_without_accept(): void
    {
        $response = $this->post('/api/v1/attendance-records', ['date' => '2026-06-01', 'clock_in' => '09:00:00']);

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * ログインしていない人が Accept なしで API でないパスを開くと、ログインの画面に移ることを確かめる。
     * ログインしていない人が Accept なしで GET /attendance を開き、shouldReturnJson() が Laravel の判定のとおり JSON にしないと決めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_web_guest_redirects_to_login_without_accept(): void
    {
        $response = $this->get('/attendance');

        $response->assertRedirect('/login');
    }

    /**
     * ログインしていない人が JSON を求める Accept で API でないパスを開くと、ログインが要ることを JSON で返すことを確かめる。
     * ログインしていない人が Accept: application/json で GET /attendance を開き、shouldReturnJson() が Laravel の判定（parent::shouldReturnJson()）のとおり JSON で返すと決めて、401 で応答が {"message": "Unauthenticated."} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_web_guest_gets_json_with_accept(): void
    {
        $response = $this->get('/attendance', ['Accept' => 'application/json']);

        $response->assertUnauthorized();
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
