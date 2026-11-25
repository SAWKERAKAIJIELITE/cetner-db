<?php

namespace App\Modules\Records\Http\Resources;

use App\Modules\Employees\Models\Employee;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordPage;
use App\Modules\Records\Support\RecordSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * «تقرير الحالة الوظيفية»'s year from the records (D-044) and its latest records, for one employee.
 *
 * @mixin Employee
 */
class RecordSummaryResource extends JsonResource
{
    public function __construct(Employee $employee, private readonly int $year)
    {
        parent::__construct($employee);
    }

    public function toArray(Request $request): array
    {
        $s = RecordSummary::of($this->id, $this->year);
        $latest = RecordSummary::latest($this->id);
        $records = function (RecordType $type, $models) use ($request) {
            $models = collect($models)->each->setRelation('employee', $this->resource);
            RecordPage::prepare($type, $models);

            return RecordResource::collection($models)->resolve($request);
        };

        return [
            'year' => $s['year'],
            'leaves' => $s['leaves'],
            'courses' => $s['courses'],
            'rewards_total' => $s['rewards_total'],
            'deductions_total' => $s['deductions_total'],
            'attendance' => $s['attendance'],
            'conduct' => $s['conduct'],
            'debts' => $s['debts'],
            /** @var list<RecordResource> the last ten, newest first */
            'latest_movements' => $records(RecordType::Movements, $latest['movements']),
            /** @var list<RecordResource> the last five */
            'latest_conduct' => $records(RecordType::Conduct, $latest['conduct']),
            /** @var RecordResource|null the last month */
            'latest_attendance' => $latest['attendance'] === null ? null : $records(RecordType::Attendance, [$latest['attendance']])[0],
        ];
    }
}
