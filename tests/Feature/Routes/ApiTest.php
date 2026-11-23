<?php

namespace Tests\Feature\Routes;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログインしていない人も公開API の勤怠の一覧を読めることを確かめる。
     * ログインしていない人が GET /api/v1/attendance-records を送り、ルートの行が auth:sanctum を付けずにログインしていない人も通して、200 で data が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_can_read_index(): void
    {
        $response = $this->getJson('/api/v1/attendance-records');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    /**
     * 開いた番号の勤怠がないときは、公開API の勤怠の詳細が見つからない応答を返すことを確かめる。
     * 番号 99999 の勤怠がないときに、ログインしていない人が GET /api/v1/attendance-records/99999 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_show(): void
    {
        $response = $this->getJson('/api/v1/attendance-records/99999');

        $response->assertNotFound();
    }

    /**
     * ログインしていない人も公開API の勤怠の詳細を読めることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、ログインしていない人が GET /api/v1/attendance-records/{id} を送り、ルートの行が auth:sanctum を付けずにログインしていない人も通して、200 でその勤怠の data が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_can_read_show(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertJsonPath('data.id', $attendanceRecord->id);
    }

    /**
     * ログインしていない人は、公開API の勤怠の登録で勤怠を作れないことを確かめる。
     * ログインしていない人が Accept なしで POST /api/v1/attendance-records に date 2026-06-01 と clock_in 09:00:00 を送り、ルートの行がグループに付けた auth:sanctum でログインしていない人を止めて、勤怠ができず、401 で応答が {"message": "Unauthenticated."} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_store(): void
    {
        $response = $this->post('/api/v1/attendance-records', ['date' => '2026-06-01', 'clock_in' => '09:00:00']);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('attendance_records', 0);
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 送った番号の勤怠がないときは、公開API の PUT の勤怠の更新が見つからない応答を返すことを確かめる。
     * 番号 99999 の勤怠がないときに、一般ユーザーが Sanctum::actingAs() で PUT /api/v1/attendance-records/99999 に date 2026-06-01 と clock_in 09:00:00 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_put(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/99999', ['date' => '2026-06-01', 'clock_in' => '09:00:00']);

        $response->assertNotFound();
    }

    /**
     * ログインしていない人は、公開API の PUT の勤怠の更新で勤怠を変えられないことを確かめる。
     * 一般ユーザーの勤怠（2026-06-01、09:00〜18:00）があるときに、ログインしていない人が Accept なしで PUT /api/v1/attendance-records/{id} に date 2026-06-01 と clock_in 08:30:00 を送り、ルートの行がグループに付けた auth:sanctum でログインしていない人を止めて、勤怠が変わらず、401 で応答が {"message": "Unauthenticated."} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_put(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);

        $response = $this->put('/api/v1/attendance-records/'.$attendanceRecord->id, ['date' => '2026-06-01', 'clock_in' => '08:30:00']);

        $response->assertUnauthorized();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 送った番号の勤怠がないときは、公開API の PATCH の勤怠の更新が見つからない応答を返すことを確かめる。
     * 番号 99999 の勤怠がないときに、一般ユーザーが Sanctum::actingAs() で PATCH /api/v1/attendance-records/99999 に comment「在宅勤務のため」を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_patch(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/attendance-records/99999', ['comment' => '在宅勤務のため']);

        $response->assertNotFound();
    }

    /**
     * ログインしていない人は、公開API の PATCH の勤怠の更新で勤怠を変えられないことを確かめる。
     * 一般ユーザーの勤怠（2026-06-01、comment「元の備考」）があるときに、ログインしていない人が Accept なしで PATCH /api/v1/attendance-records/{id} に comment「在宅勤務のため」を送り、ルートの行がグループに付けた auth:sanctum でログインしていない人を止めて、勤怠が変わらず、401 で応答が {"message": "Unauthenticated."} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_patch(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '元の備考']);

        $response = $this->patch('/api/v1/attendance-records/'.$attendanceRecord->id, ['comment' => '在宅勤務のため']);

        $response->assertUnauthorized();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'comment' => '元の備考']);
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * 送った番号の勤怠がないときは、公開API の勤怠の削除が見つからない応答を返すことを確かめる。
     * 番号 99999 の勤怠がないときに、一般ユーザーが Sanctum::actingAs() で DELETE /api/v1/attendance-records/99999 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_delete(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/attendance-records/99999');

        $response->assertNotFound();
    }

    /**
     * ログインしていない人は、公開API の勤怠の削除で勤怠を消せないことを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、ログインしていない人が Accept なしで DELETE /api/v1/attendance-records/{id} を送り、ルートの行がグループに付けた auth:sanctum でログインしていない人を止めて、勤怠の行が残り、401 で応答が {"message": "Unauthenticated."} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_delete(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->delete('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertUnauthorized();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id]);
        $response->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
