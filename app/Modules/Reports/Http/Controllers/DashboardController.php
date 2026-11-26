<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Reports\Http\Requests\ContractsEndingRequest;
use App\Modules\Reports\Http\Requests\EmployeesDashboardRequest;
use App\Modules\Reports\Http\Requests\MovementsDashboardRequest;
use App\Modules\Reports\Http\Requests\MovementsEmployeesRequest;
use App\Modules\Reports\Http\Requests\OverdueReviewsRequest;
use App\Modules\Reports\Http\Requests\OverviewRequest;
use App\Modules\Reports\Http\Resources\ContractEndingResource;
use App\Modules\Reports\Http\Resources\MovementsEmployeeResource;
use App\Modules\Reports\Http\Resources\OverdueReviewResource;
use App\Modules\Reports\Support\EmployeeScope;
use App\Modules\Reports\Support\EmployeesDashboard;
use App\Modules\Reports\Support\MovementsByEmployee;
use App\Modules\Reports\Support\MovementsDashboard;
use App\Modules\Reports\Support\OverviewReport;
use App\Modules\Reports\Support\ReportPeriod;
use App\Modules\Reports\Support\Tile;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The dashboards (D-051): `reports.view`. */
class DashboardController extends Controller
{
    /**
     * «لوحة التحكم»: the home tiles, the job statuses with their share, the contracts' state and the fields most
     * often missing, for a unit (with its sub-units) or all.
     */
    #[Response(200, type: 'array{data: array{total: int, tiles: list<'.Tile::TYPE.'>, job_statuses: list<array{item: array{id: int, name_ar: string, archived: bool}|null, count: int, share: float}>, contracts: array{valid: int, ending: int, expired: int, no_end_date: int}, missing_fields: list<array{key: string, label: string, cycle: string, cycle_label: string, count: int, share: float}>}}')]
    public function overview(OverviewRequest $request): array
    {
        $f = $request->validated();

        return ['data' => OverviewReport::build($f, (int) ($f['missing_limit'] ?? 5))];
    }

    /** «عقود تنتهي قريباَ»: contracts ending within `days` (30 by default), soonest first. */
    public function contractsEnding(ContractsEndingRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $files = EmployeeQuery::build([
            'org_unit_id' => $f['org_unit_id'] ?? null,
            'include_sub_units' => $f['include_sub_units'] ?? true,
            'contract_ends_within_days' => (int) ($f['days'] ?? 30),
            'file_status' => 'active',
        ])->reorder()->orderBy('contract_ends_on')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 10))->withQueryString();

        ContractEndingResource::$labels = new ValueLabels;

        return ContractEndingResource::collection($files);
    }

    /** «متأخرون في التحديث»: files with a cycle overdue (or the chosen one), the longest unreviewed first. */
    public function overdueReviews(OverdueReviewsRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $before = EmployeeScope::overdueBefore();
        $cycles = isset($f['cycle']) ? [Cycle::from($f['cycle'])] : Cycle::reviewed();

        $query = EmployeeQuery::build([
            'org_unit_id' => $f['org_unit_id'] ?? null,
            'include_sub_units' => $f['include_sub_units'] ?? true,
            'file_status' => 'active',
        ])->reorder()->leftJoinSub(EmployeeScope::reviews(), 'r', 'r.employee_id', '=', 'employees.id')
            ->select('employees.*');

        foreach (Cycle::reviewed() as $cycle) {
            $query->addSelect("r.{$cycle->value} AS last_{$cycle->value}");
        }

        $query->where(function ($w) use ($cycles, $before) {
            foreach ($cycles as $cycle) {
                $w->orWhereNull("r.{$cycle->value}")->orWhere("r.{$cycle->value}", '<', $before[$cycle->value]);
            }
        });

        $first = $cycles[0]->value;
        $files = $query->orderByRaw("r.{$first} ASC NULLS FIRST")->orderBy('employees.search_name')->orderBy('employees.id')
            ->paginate((int) ($f['per_page'] ?? 10))->withQueryString();

        OverdueReviewResource::$before = $before;
        OverdueReviewResource::$labels = new ValueLabels;

        return OverdueReviewResource::collection($files);
    }

    /** «داش بورد الموظفين»: the workbook's tiles in its groups, within its six filters. */
    #[Response(200, type: 'array{data: array{total: int, groups: list<'.Tile::GROUP_TYPE.'>}}')]
    public function employees(EmployeesDashboardRequest $request): array
    {
        return ['data' => EmployeesDashboard::build($request->validated())];
    }

    /** «داش بورد الحركات»: a year (or one of its months), and a chosen employee's figures. */
    #[Response(200, type: 'array{data: array{period: array{year: int, month: int|null, from: string, to: string}, employee_id: string|null, groups: list<'.Tile::GROUP_TYPE.'>}}')]
    public function movements(MovementsDashboardRequest $request): array
    {
        $f = $request->validated();

        return ['data' => MovementsDashboard::build(new ReportPeriod((int) $f['year'], isset($f['month']) ? (int) $f['month'] : null), $f['employee_id'] ?? null)];
    }

    /** «ملخص جميع الموظفين»: one row per approved file for the period; `sort` by name (default) or a figure. */
    public function movementsByEmployee(MovementsEmployeesRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $period = new ReportPeriod((int) $f['year'], isset($f['month']) ? (int) $f['month'] : null);

        MovementsEmployeeResource::$labels = new ValueLabels;

        return MovementsEmployeeResource::collection(MovementsByEmployee::query($period, $f)
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString());
    }
}
