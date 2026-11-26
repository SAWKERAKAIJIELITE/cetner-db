<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Actions\LockEmployeeIdentity;
use App\Modules\Employees\Actions\SubmitEmployeeChanges;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Http\Requests\EmployeeIndexRequest;
use App\Modules\Employees\Http\Requests\HistoryRequest;
use App\Modules\Employees\Http\Requests\ReviewCycleRequest;
use App\Modules\Employees\Http\Requests\StoreEmployeeRequest;
use App\Modules\Employees\Http\Requests\UpdateEmployeeRequest;
use App\Modules\Employees\Http\Resources\ChangeSetResource;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Http\Resources\EmployeeSummaryResource;
use App\Modules\Employees\Http\Resources\HistoryItemResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeChangeItem;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Employees\Support\ReviewStatus;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Http\Resources\StaffUserSummaryResource;
use App\Modules\Records\Support\DebtTotals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class EmployeeController extends Controller
{
    /** The files, 25 per page, with filters; active files by default. */
    public function index(EmployeeIndexRequest $request): AnonymousResourceCollection
    {
        $page = EmployeeQuery::build($request->validated())->paginate((int) ($request->validated('per_page') ?? 25))->withQueryString();
        $ids = $page->getCollection()->pluck('id')->all();

        // Every row's list values in one query per list (D-031).
        $labels = new ValueLabels;

        foreach ($page->getCollection() as $row) {
            foreach (['employee_status_id', 'job_status_id', 'job_title_id'] as $field) {
                $labels->add($field, $row->getAttribute($field));
            }
        }

        EmployeeSummaryResource::$labels = $labels;
        EmployeeSummaryResource::$reviews = ReviewStatus::forMany($ids);
        EmployeeSummaryResource::$pending = DB::table('employee_change_sets')
            ->whereIn('employee_id', $ids)->where('status', ChangeSetStatus::Pending->value)
            ->groupBy('employee_id')->selectRaw('employee_id, count(*) as pending')
            ->pluck('pending', 'employee_id')->map(fn ($n) => (int) $n)->all();
        EmployeeSummaryResource::$debts = DebtTotals::remaining($ids);

        return EmployeeSummaryResource::collection($page);
    }

    /** One file: approved values with pending changes beside them, computed values, completeness, warnings, cycles. */
    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee);
    }

    /**
     * Add an employee. From data entry it waits for the supervisor; from a reviewer it is applied at once.
     * 201 with the change set.
     */
    public function store(StoreEmployeeRequest $request, SubmitEmployeeChanges $action): JsonResponse
    {
        $set = $action->create($request->values(), $request->validated('note'), $request->user());

        return (new ChangeSetResource($set->load(['items', 'submitter', 'reviewer', 'employee'])))->response()->setStatusCode(201);
    }

    /** Change fields: only real changes are kept; waits for review unless the author reviews (D-023, D-034). */
    public function update(UpdateEmployeeRequest $request, Employee $employee, SubmitEmployeeChanges $action): JsonResponse
    {
        $set = $action->update($employee, $request->values(), $request->validated('note'), $request->user());

        return (new ChangeSetResource($set->load(['items', 'submitter', 'reviewer', 'employee'])))->response()->setStatusCode(201);
    }

    /** Confirm a cycle for the current period, with the changes found among its fields (or none). */
    public function review(ReviewCycleRequest $request, Employee $employee, SubmitEmployeeChanges $action): JsonResponse
    {
        $set = $action->review($employee, Cycle::from($request->validated('cycle')), $request->values(), $request->validated('note'), $request->user());

        return (new ChangeSetResource($set->load(['items', 'submitter', 'reviewer', 'employee'])))->response()->setStatusCode(201);
    }

    /**
     * Confirm and lock the file's identity (the administrator): its six identity fields are never changed again.
     * Empty ones may still be filled once.
     */
    public function lockIdentity(Request $request, Employee $employee, LockEmployeeIdentity $action): EmployeeResource
    {
        return new EmployeeResource($action->handle($employee, $request->user()));
    }

    /** The four cycles: last confirmation, due date, overdue. And every confirmation, newest first. */
    public function reviews(Employee $employee): JsonResponse
    {
        $all = $employee->reviews()->with(['confirmer', 'approver'])->orderByDesc('reviewed_on')->orderByDesc('id')->get();

        return response()->json(['data' => [
            'cycles' => $employee->isActive() ? ReviewStatus::of($employee->id) : [],
            'history' => $all->map(fn ($r) => [
                'cycle' => $r->cycle->value,
                'cycle_label' => $r->cycle->label(),
                'period' => $r->period,
                'reviewed_on' => $r->reviewed_on->format('Y-m-d'),
                'change_set_id' => $r->change_set_id,
                'confirmed_by' => new StaffUserSummaryResource($r->confirmer),
                'approved_by' => new StaffUserSummaryResource($r->approver),
            ])->values(),
        ]]);
    }

    /** What changed, field by field, newest first: the approved changes only (D-033). `field` for one field. */
    public function history(HistoryRequest $request, Employee $employee): AnonymousResourceCollection
    {
        $page = EmployeeChangeItem::query()
            ->join('employee_change_sets as s', 's.id', '=', 'employee_change_items.change_set_id')
            ->where('s.employee_id', $employee->id)
            ->where('s.status', ChangeSetStatus::Approved->value)
            ->when($request->validated('field'), fn ($q, $field) => $q->where('employee_change_items.field', $field))
            ->orderByDesc('s.reviewed_at')->orderByDesc('employee_change_items.id')
            ->select('employee_change_items.*')
            ->with('changeSet.submitter', 'changeSet.reviewer')
            ->paginate((int) ($request->validated('per_page') ?? 50))->withQueryString();

        $labels = new ValueLabels;

        foreach ($page->getCollection() as $item) {
            $labels->add($item->field, $item->old_value);
            $labels->add($item->field, $item->new_value);
        }

        HistoryItemResource::$labels = $labels;

        return HistoryItemResource::collection($page);
    }
}
