<?php

namespace Tests\Feature\App\Http\Controllers\Api\V1;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\ProposalBreak;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AttendanceRecordControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログインしていない人が API で勤怠の一覧を開くと、持ち主の付いた勤怠がページに分けた形で返ることを確かめる。
     * 一般ユーザー A の勤怠が2件と一般ユーザー B の勤怠が1件あるときに、ログインしていない人が GET /api/v1/attendance-records を開き、index() が勤怠を持ち主と一緒に読み（with('user')）ページに分けた形で返して、200 で、data が3行、meta に current_page、last_page、per_page、total があり、各行に user の id と name があり、breaks と applications がなく、links があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_returns_paginated_list(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure(['meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $response->assertJsonStructure(['data' => ['*' => ['user' => ['id', 'name']]]]);
        collect($response->json('data'))->each(function (array $row): void {
            $this->assertArrayNotHasKey('breaks', $row);
            $this->assertArrayNotHasKey('applications', $row);
        });
        $response->assertJsonStructure(['links']);
    }

    /**
     * 勤怠の一覧が日付の新しい順に並び、同じ日の中では id の大きい順に並ぶことを確かめる。
     * 2026-06-02 の勤怠が2件とその後に作った 2026-06-01 の勤怠が1件あるときに、ログインしていない人が GET /api/v1/attendance-records を開き、index() が日付の新しい順に並べ（latest('date')）同じ日は id の大きい順にして（orderByDesc('id')）、200 で data の順が 2026-06-02 の id の大きい行、2026-06-02 の id の小さい行、2026-06-01 の行かを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_orders_by_date_and_id(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $smallerIdRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        $largerIdRecord = AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-02']);
        $olderRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records');

        $response->assertOk();
        $this->assertSame(
            [$largerIdRecord->id, $smallerIdRecord->id, $olderRecord->id],
            $response->collect('data')->pluck('id')->all(),
        );
    }

    /**
     * user_id を送ると、勤怠の一覧がその人の勤怠だけに絞られることを確かめる。
     * A の勤怠が2件と B の勤怠が1件あるときに、ログインしていない人が GET /api/v1/attendance-records?user_id={A の id} を開き、index() が送った user_id の人の勤怠だけに絞って、200 で data が A の2行だけかを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_filters_by_user_id(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $firstRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $secondRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['user_id' => $user->id]));

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            [$firstRecord->id, $secondRecord->id],
            $response->collect('data')->pluck('id')->all(),
        );
    }

    /**
     * user_id に空とみなされやすい値を送っても、勤怠の一覧を絞ることを確かめる。
     * 一般ユーザー A の勤怠が2件と一般ユーザー B の勤怠が1件あるときに、ログインしていない人が GET /api/v1/attendance-records?user_id=0 を開き、index() が filled() で 0 も送った値とみなして user_id で絞り、200 で data が空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_filters_by_user_id_zero(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['user_id' => 0]));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /**
     * date を送ると、勤怠の一覧がその日の勤怠だけに絞られることを確かめる。
     * 2026-06-01 と 2026-06-02 の勤怠が1件ずつあるときに、ログインしていない人が GET /api/v1/attendance-records?date=2026-06-01 を開き、index() が送った date の日の勤怠だけに絞って、200 で data が 2026-06-01 の1行だけかを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_filters_by_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['date' => '2026-06-01']));

        $response->assertOk();
        $this->assertSame(['2026-06-01'], $response->collect('data')->pluck('date')->all());
    }

    /**
     * month を送ると、勤怠の一覧がその月の初めの日から終わりの日までの勤怠だけに絞られることを確かめる。
     * 2026-05-31、2026-06-01、2026-06-30、2026-07-01 の勤怠が1件ずつあるときに、ログインしていない人が GET /api/v1/attendance-records?month=2026-06 を開き、index() が date が送った月で始まる勤怠だけに絞って（like 'YYYY-MM-%'）、200 で data が 2026-06-01 と 2026-06-30 の2行だけかを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_filters_by_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-30']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-07-01']);

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['month' => '2026-06']));

        $response->assertOk();
        $this->assertSame(['2026-06-30', '2026-06-01'], $response->collect('data')->pluck('date')->all());
    }

    /**
     * date と month を両方送ると、勤怠の一覧が両方の条件に合う勤怠だけに絞られることを確かめる。
     * 2026-06-01、2026-06-02、2026-07-01 の勤怠が1件ずつあるときに、ログインしていない人が GET /api/v1/attendance-records?date=2026-06-01&month=2026-07 を開き、index() が date と month の両方で絞って、200 で data が0行かを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_filters_by_date_and_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-07-01']);

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['date' => '2026-06-01', 'month' => '2026-07']));

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    /**
     * per_page を送らないと、勤怠の一覧が決まった件数ずつに区切られることを確かめる。
     * 勤怠が21件あるときに、ログインしていない人が GET /api/v1/attendance-records を開き、index() が per_page を送らないので20件ずつに区切って（?? 20）、200 で data が20行で、meta.per_page が 20、meta.last_page が 2 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_defaults_to_twenty_per_page(): void
    {
        AttendanceRecord::factory()->count(21)->create();

        $response = $this->getJson('/api/v1/attendance-records');

        $response->assertOk();
        $response->assertJsonCount(20, 'data');
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.last_page', 2);
    }

    /**
     * per_page と page を送ると、勤怠の一覧がその区切りで返り、前と次のページの URL に絞り込みの値が残ることを確かめる。
     * A の 2026-06-01〜2026-06-05 の5件の勤怠があるときに、ログインしていない人が GET /api/v1/attendance-records?user_id={A の id}&per_page=2&page=2 を開き、index() が送った per_page と page で区切り前と次のページの URL に絞り込みの値を残して（withQueryString()）、200 で data が 2026-06-03 と 2026-06-02 の2行で、links.prev と links.next に user_id={A の id} があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_index_pages_keep_query(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        collect(['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'])->each(function (string $date) use ($user): void {
            AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => $date]);
        });

        $response = $this->getJson('/api/v1/attendance-records?'.http_build_query(['user_id' => $user->id, 'per_page' => 2, 'page' => 2]));

        $response->assertOk();
        $this->assertSame(['2026-06-03', '2026-06-02'], $response->collect('data')->pluck('date')->all());
        $this->assertStringContainsString('user_id='.$user->id, $response->json('links.prev'));
        $this->assertStringContainsString('user_id='.$user->id, $response->json('links.next'));
    }

    /**
     * 一般ユーザーが API で自分の勤怠を作ると、働いた時間も計算されることを確かめる。
     * 一般ユーザー A が Sanctum::actingAs() で POST /api/v1/attendance-records に date 2026-06-01、clock_in 09:00:00、clock_out 18:00:00、comment「打刻漏れのため」を送り、store() がログインしている人の勤怠を確かめた値で作り働いた時間を計算して、201 で、A の 2026-06-01 の勤怠が送った値ででき、work_minutes が 540 になり、応答の data に user と breaks があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_store_creates_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance-records', [
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '打刻漏れのため',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '打刻漏れのため',
            'work_minutes' => 540,
        ]);
        $response->assertJsonStructure(['data' => ['user', 'breaks']]);
    }

    /**
     * 一般ユーザーが API で他の人の user_id を送っても、自分の勤怠ができることを確かめる。
     * 一般ユーザー A が Sanctum::actingAs() で POST /api/v1/attendance-records に一般ユーザー B の user_id、date 2026-06-01、clock_in 09:00:00 を送り、store() がログインしている人のリレーション（attendanceRecords()）で作って送った user_id を使わずに、A の勤怠ができ、B の勤怠ができず、201 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_store_ignores_sent_user_id(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/attendance-records', [
            'user_id' => $otherUser->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->assertDatabaseMissing('attendance_records', ['user_id' => $otherUser->id]);
    }

    /**
     * ログインしていない人が API で勤怠を開くと、持ち主、休憩、同じ日の申請と一緒に返ることを確かめる。
     * 一般ユーザーの勤怠（2026-06-01、09:00〜18:00、休憩 12:00〜13:00）と同じ人の同じ日の申請（申請の休憩 12:30〜13:30）に加えて、同じ人の別の日（2026-06-02）の申請と別の人の同じ日の申請があるときに、ログインしていない人が GET /api/v1/attendance-records/{id} を開き、show() が勤怠を持ち主と休憩と一緒に読み同じ人の同じ日の申請を申請の休憩と一緒に applications に入れて、200 で、data に user の id と name、breaks の1行、同じ人の同じ日の申請だけの applications の1行とその中の breaks の1行があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_show_returns_detail_with_relations(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        ProposalBreak::factory()->create([
            'application_id' => $application->id,
            'break_in' => '12:30:00',
            'break_out' => '13:30:00',
        ]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);
        Application::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
        $response->assertJsonPath('data.user.name', $user->name);
        $response->assertJsonCount(1, 'data.breaks');
        $response->assertJsonCount(1, 'data.applications');
        $response->assertJsonPath('data.applications.0.id', $application->id);
        $response->assertJsonCount(1, 'data.applications.0.breaks');
    }

    /**
     * API では、承認待ちの申請があっても勤怠を書き換えられることを確かめる。
     * 持ち主の 2026-06-01 の勤怠（09:00〜18:00）に承認待ちの申請があるときに、持ち主が Sanctum::actingAs() で PUT /api/v1/attendance-records/{id} に date 2026-06-01、clock_in 08:30:00、clock_out 18:00:00、comment「打刻の時刻を誤ったため」を送り、update() が承認待ちの申請を見ずに勤怠を書き換えて、勤怠の clock_in が 08:30:00 に変わり、申請は承認待ちのままで、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_record_can_be_updated_by_api(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, [
            'date' => '2026-06-01',
            'clock_in' => '08:30:00',
            'clock_out' => '18:00:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'clock_in' => '08:30:00']);
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'is_approved' => false]);
    }

    /**
     * 勤怠の持ち主が API で自分の勤怠を書き換えられることを確かめる。
     * 持ち主が Sanctum::actingAs() で自分の勤怠（2026-06-01、09:00〜18:00）に PUT /api/v1/attendance-records/{id} で date 2026-06-01、clock_in 08:30:00、clock_out 18:00:00 を送り、update() が authorize('update') を通して勤怠を書き換えて、200 で勤怠の clock_in が 08:30:00 に変わるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_update(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, [
            'date' => '2026-06-01',
            'clock_in' => '08:30:00',
            'clock_out' => '18:00:00',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'clock_in' => '08:30:00']);
    }

    /**
     * PUT で全部の項目を送ると、勤怠が送った値に変わり、働いた時間が計算し直されることを確かめる。
     * 持ち主が Sanctum::actingAs() で休憩のない自分の勤怠（2026-06-01、09:00〜18:00、work_minutes 540）に PUT /api/v1/attendance-records/{id} で date 2026-06-02、clock_in 08:00:00、clock_out 17:30:00、comment「打刻の時刻を誤ったため」を送り、update() が確かめた値で勤怠を書き換え働いた時間を計算し直して、200 で、勤怠が送った値に変わり、work_minutes が 570 になり、応答の data に user と breaks があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_put_updates_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        $attendanceRecord->recalculate();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, [
            'date' => '2026-06-02',
            'clock_in' => '08:00:00',
            'clock_out' => '17:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'date' => '2026-06-02',
            'clock_in' => '08:00:00',
            'clock_out' => '17:30:00',
            'comment' => '打刻の時刻を誤ったため',
            'work_minutes' => 570,
        ]);
        $response->assertJsonStructure(['data' => ['user', 'breaks']]);
    }

    /**
     * PATCH で一部の項目だけを送ると、送らなかった項目が変わらないことを確かめる。
     * 持ち主が Sanctum::actingAs() で自分の勤怠（2026-06-01、09:00〜18:00、comment「元の備考」）に PATCH /api/v1/attendance-records/{id} で comment「在宅勤務のため」を送り、update() が確かめた値のうち送られた項目だけを書いて（validated()）、200 で、comment が「在宅勤務のため」に変わり、date、clock_in、clock_out が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_patch_keeps_other_fields(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '元の備考',
        ]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/attendance-records/'.$attendanceRecord->id, ['comment' => '在宅勤務のため']);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '在宅勤務のため',
        ]);
    }

    /**
     * API で勤怠を書き換えるときに他の人の user_id を送っても、勤怠の持ち主が変わらないことを確かめる。
     * 一般ユーザー A が Sanctum::actingAs() で自分の勤怠（2026-06-01、09:00〜18:00）に PUT /api/v1/attendance-records/{id} で B の user_id、date 2026-06-01、clock_in 08:30:00 を送り、update() が確かめた値だけで書き換えて送った user_id で持ち主を変えずに、勤怠の user_id が A の id のままで、200 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_update_ignores_sent_user_id(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, [
            'user_id' => $otherUser->id,
            'date' => '2026-06-01',
            'clock_in' => '08:30:00',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'user_id' => $user->id]);
    }

    /**
     * 勤怠を消した後に処理が失敗したら、同じ人と日付の申請の削除も元に戻ることを確かめる。
     * 持ち主の 2026-06-01 の勤怠と同じ人と日付の承認待ちの申請があり、勤怠を消した直後に例外を投げる処理を足したときに、持ち主が Sanctum::actingAs() で DELETE /api/v1/attendance-records/{id} を送り、destroy() が DB::transaction() で申請と勤怠の削除をまとめて、例外が起き、申請の行と勤怠の行が残るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_delete_rolls_back_applications_on_failure(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceRecord::deleted(function (): void {
            throw new RuntimeException('勤怠を消した後の失敗');
        });
        Sanctum::actingAs($user);
        $this->withoutExceptionHandling();

        $this->assertThrows(
            fn () => $this->deleteJson('/api/v1/attendance-records/'.$attendanceRecord->id),
            RuntimeException::class,
            '勤怠を消した後の失敗'
        );
        $this->assertDatabaseHas('applications', ['id' => $application->id]);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id]);
    }

    /**
     * 勤怠の持ち主が API で自分の勤怠を消せることを確かめる。
     * 持ち主が Sanctum::actingAs() で自分の勤怠（2026-06-01）に DELETE /api/v1/attendance-records/{id} を送り、destroy() が authorize('delete') を通して勤怠を消し中身のない応答を返して、204 で勤怠の行が消えるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_delete(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertNoContent();
        $this->assertDatabaseMissing('attendance_records', ['id' => $attendanceRecord->id]);
    }
}
