<?php

namespace App\Modules\Records\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Records\Http\Requests\PlannedDaysRequest;
use App\Modules\Records\Http\Requests\YearRequest;
use App\Modules\Records\Http\Resources\PlannedDaysResource;
use App\Modules\Records\Http\Resources\RecordSummaryResource;
use App\Modules\Records\Support\PlannedDays;
use App\Support\BusinessDay;

class EmployeeRecordsController extends Controller
{
    /**
     * The employee's year from the records, as «تقرير الحالة الوظيفية» shows it: leaves and their balance,
     * courses, rewards and deductions, attendance, conduct, debts, and the latest movements, conduct and month.
     */
    public function summary(YearRequest $request, Employee $employee): RecordSummaryResource
    {
        return new RecordSummaryResource($employee, (int) ($request->validated('year') ?? BusinessDay::today()->year));
    }

    /** A month's planned working days, to fill the attendance: less the weekly days off and the holidays. */
    public function plannedDays(PlannedDaysRequest $request): PlannedDaysResource
    {
        return new PlannedDaysResource(PlannedDays::of((int) $request->validated('year'), (int) $request->validated('month')));
    }
}
