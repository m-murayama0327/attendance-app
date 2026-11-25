<?php

namespace Tests\Feature\App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StampRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * action が空なら、action に required の文言のエラーがあり、勤怠ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で action を空にして送り、rules() が action の required で止めて、required の文言が出て、勤怠ができず、/attendance へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_action_is_required(): void
    {
        $response = $this->postStamp('');

        $response->assertRedirect('/attendance');
        $response->assertSessionHasErrors(['action' => __('validation.required', ['attribute' => 'action'])]);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * action が delete なら、action にエラーがあり、勤怠ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で action を delete にして送り、rules() が action の Rule::in() で止めて、勤怠ができず、/attendance へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_action_must_be_known(): void
    {
        $response = $this->postStamp('delete');

        $response->assertRedirect('/attendance');
        $response->assertSessionHasErrors('action');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * action が配列 ['clock_in'] なら、action にエラーがあり、勤怠ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で action を配列 ['clock_in'] にして送り、rules() が action の Rule::in() で止めて、勤怠ができず、/attendance へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_action_array_is_rejected(): void
    {
        $response = $this->postStamp(['clock_in']);

        $response->assertRedirect('/attendance');
        $response->assertSessionHasErrors('action');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 今日の勤怠がない一般ユーザーで、打刻の画面から action を送る。
     *
     * @param  mixed  $action  送る action の値
     * @return TestResponse 送った後の応答
     */
    private function postStamp(mixed $action): TestResponse
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));

        return $this->actingAs($user)
            ->from('/attendance')
            ->post('/attendance', ['action' => $action]);
    }
}
