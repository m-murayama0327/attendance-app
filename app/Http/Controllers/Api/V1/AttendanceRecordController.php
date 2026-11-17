<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAttendanceRecordRequest;
use App\Http\Requests\Api\V1\StoreAttendanceRecordRequest;
use App\Http\Requests\Api\V1\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AttendanceRecordController extends Controller
{
    /**
     * 勤怠の一覧を、送られた user_id、date、month で絞り、日付の新しい順にページに分けて返す。
     *
     * @param  IndexAttendanceRecordRequest  $request  確かめた絞り込みの条件とページの指定
     * @return AnonymousResourceCollection 勤怠の一覧（data、links、meta）
     */
    public function index(IndexAttendanceRecordRequest $request): AnonymousResourceCollection
    {
        $attendanceRecords = AttendanceRecord::with('user')
            ->when($request->filled('user_id'), fn (Builder $query): Builder => $query->where('user_id', $request->validated('user_id')))
            ->when($request->filled('date'), fn (Builder $query): Builder => $query->where('date', $request->validated('date')))
            ->when($request->filled('month'), fn (Builder $query): Builder => $query->where('date', 'like', $request->validated('month').'-%'))
            ->latest('date')
            ->orderByDesc('id')
            ->paginate($request->validated('per_page') ?? 20)
            ->withQueryString();

        return AttendanceRecordResource::collection($attendanceRecords);
    }

    /**
     * ログインしている人の勤怠を、確かめた値で作り、計算した値と一緒に 201 で返す。
     *
     * @param  StoreAttendanceRecordRequest  $request  確かめた勤怠の値
     * @return JsonResponse 作った勤怠（ステータスは 201）
     */
    public function store(StoreAttendanceRecordRequest $request): JsonResponse
    {
        $attendanceRecord = DB::transaction(function () use ($request): AttendanceRecord {
            $attendanceRecord = $request->user()->attendanceRecords()->create($request->validated());
            $attendanceRecord->recalculate();

            return $attendanceRecord;
        });

        return (new AttendanceRecordResource($attendanceRecord->load(['user', 'breaks'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * 勤怠を、持ち主、休憩、同じ人と日付の修正申請（申請の休憩も含む）と一緒に返す。
     * 申請は勤怠の番号を持たず人と日付で勤怠とつながるので、リレーションで読めず、読んだ申請を applications として入れる。
     *
     * @param  AttendanceRecord  $attendanceRecord  URL の番号で読んだ勤怠
     * @return AttendanceRecordResource 勤怠の詳細
     */
    public function show(AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        $attendanceRecord->load(['user', 'breaks']);
        $attendanceRecord->setRelation('applications', $attendanceRecord->sameDayApplications()->with('proposalBreaks')->get());

        return new AttendanceRecordResource($attendanceRecord);
    }

    /**
     * 勤怠の持ち主か管理者なら、確かめた値のうち送られた項目だけを書き、計算し直して返す。
     *
     * @param  UpdateAttendanceRecordRequest  $request  確かめた勤怠の値（送られた項目だけ）
     * @param  AttendanceRecord  $attendanceRecord  URL の番号で読んだ勤怠
     * @return AttendanceRecordResource 直した勤怠
     */
    public function update(UpdateAttendanceRecordRequest $request, AttendanceRecord $attendanceRecord): AttendanceRecordResource
    {
        $this->authorize('update', $attendanceRecord);

        DB::transaction(function () use ($request, $attendanceRecord): void {
            $attendanceRecord->update($request->validated());
            $attendanceRecord->recalculate();
        });

        return new AttendanceRecordResource($attendanceRecord->load(['user', 'breaks']));
    }

    /**
     * 勤怠の持ち主か管理者なら、勤怠を消して 204 を返す。休憩と同じ人と日付の申請も一緒に消える。
     *
     * @param  AttendanceRecord  $attendanceRecord  URL の番号で読んだ勤怠
     * @return Response 中身のない応答（ステータスは 204）
     */
    public function destroy(AttendanceRecord $attendanceRecord): Response
    {
        $this->authorize('delete', $attendanceRecord);

        $attendanceRecord->delete();

        return response()->noContent();
    }
}
