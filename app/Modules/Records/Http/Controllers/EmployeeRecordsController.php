<?php

namespace App\Modules\Records\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Http\Requests\PlannedDaysRequest;
use App\Modules\Records\Http\Requests\YearRequest;
use App\Modules\Records\Http\Resources\RecordResource;
use App\Modules\Records\Support\PlannedDays;
use App\Modules\Records\Support\RecordPage;
use App\Modules\Records\Support\RecordSummary;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;

class EmployeeRecordsController extends Controller
{
    /**
     * The employee's year from the records, as «تقرير الحالة الوظيفية» shows it: leaves and their balance,
     * courses, rewards and deductions, attendance, conduct, debts, and the latest movements, conduct and month.
     */
    public function summary(YearRequest $request, Employee $employee): JsonResponse
    {
        $year = (int) ($request->validated('year') ?? BusinessDay::today()->year);
        $latest = RecordSummary::latest($employee->getKey());
        $resolve = function (RecordType $type, $records) use ($request) {
            RecordPage::prepare($type, $records);

            return RecordResource::collection($records)->resolve($request);
        };

        return response()->json(['data' => [
            ...RecordSummary::of($employee->getKey(), $year),
            'latest_movements' => $resolve(RecordType::Movements, $latest['movements']->each->setRelation('employee', $employee)),
            'latest_conduct' => $resolve(RecordType::Conduct, $latest['conduct']->each->setRelation('employee', $employee)),
            'latest_attendance' => $latest['attendance'] === null ? null
                : $resolve(RecordType::Attendance, collect([$latest['attendance']->setRelation('employee', $employee)]))[0],
        ]]);
    }

    /** A month's planned working days, to fill the attendance: less the weekly days off and the holidays. */
    public function plannedDays(PlannedDaysRequest $request): JsonResponse
    {
        return response()->json(['data' => PlannedDays::of((int) $request->validated('year'), (int) $request->validated('month'))]);
    }
}
