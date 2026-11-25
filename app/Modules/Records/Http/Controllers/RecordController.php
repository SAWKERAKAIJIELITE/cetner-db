<?php

namespace App\Modules\Records\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Records\Actions\SubmitRecordChanges;
use App\Modules\Records\Enums\DebtStatus;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Http\Requests\BatchRecordsRequest;
use App\Modules\Records\Http\Requests\DeleteRecordRequest;
use App\Modules\Records\Http\Requests\RecordIndexRequest;
use App\Modules\Records\Http\Requests\StoreRecordRequest;
use App\Modules\Records\Http\Requests\UpdateRecordRequest;
use App\Modules\Records\Http\Resources\RecordChangeSetResource;
use App\Modules\Records\Http\Resources\RecordResource;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Records\Models\RecordChangeItem;
use App\Modules\Records\Support\RecordCatalogue;
use App\Modules\Records\Support\RecordChange;
use App\Modules\Records\Support\RecordLabels;
use App\Modules\Records\Support\RecordPage;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The four records: `{type}` is movements, attendance, conduct or debts. Every entry is a change that waits for a
 * reviewer, unless its author reviews (D-041).
 */
class RecordController extends Controller
{
    /** The columns of a kind of record, to build its form: label, type, list, whether required, whether fixed. */
    public function fields(string $type): JsonResponse
    {
        $t = RecordType::from($type);

        return response()->json(['data' => [
            'record_type' => ['value' => $t->value, 'label' => $t->label()],
            'fields' => collect(RecordCatalogue::fields($t))->map(fn (array $f, string $key) => [
                'key' => $key,
                'label' => $f['label'],
                'type' => $f['type'],
                'list' => $f['list'] ?? null,
                'required' => $f['required'] ?? false,
                /** Set when the record is added, never changed. */
                'fixed' => $f['fixed'] ?? false,
                'max' => $f['max'] ?? null,
            ])->values(),
        ]]);
    }

    /** A kind of record, newest first, with filters; each with the changes waiting for it. */
    public function index(RecordIndexRequest $request, string $type): AnonymousResourceCollection
    {
        $t = RecordType::from($type);
        $f = $request->validated();
        $model = $t->model();
        $table = $t->table();
        $date = $t === RecordType::Debts ? 'incurred_on' : 'occurred_on';
        $today = BusinessDay::today()->format('Y-m-d');

        $query = $model::query()
            ->with(['employee', 'creator', 'approver'])
            ->when(! empty($f['deleted']), fn ($q) => $q->whereNotNull('deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($f['personal_number'] ?? null, fn ($q, $n) => $q->whereIn('employee_id', DB::table('employees')->select('id')->where('personal_number', $n)));

        if ($t === RecordType::Attendance) {
            $query->when($f['year'] ?? null, fn ($q, $y) => $q->where('year', $y))
                ->when($f['month'] ?? null, fn ($q, $m) => $q->where('month', $m))
                ->when(isset($f['high_absence']), fn ($q) => $q->whereRaw(
                    ($f['high_absence'] ? '' : 'NOT ').'(planned_days > 0 AND absent_days::numeric / planned_days >= ?)', [config('hr.high_absence_rate')]
                ));
        } else {
            $query->when($f['year'] ?? null, fn ($q, $y) => $q->whereBetween($date, [sprintf('%04d-01-01', $y), sprintf('%04d-12-31', $y)]))
                ->when($f['month'] ?? null, fn ($q, $m) => $q->whereRaw("extract(month FROM {$date}) = ?", [$m]));
        }

        $list = ['movements' => 'movement_type_id', 'conduct' => 'conduct_type_id', 'debts' => 'debt_type_id'][$t->value] ?? null;
        $query->when($list && isset($f['type_id']), fn ($q) => $q->where($list, $f['type_id']));

        if ($t === RecordType::Debts && isset($f['debt_status'])) {
            match (DebtStatus::from($f['debt_status'])) {
                DebtStatus::Paid => $query->where('remaining_amount', '<=', 0),
                DebtStatus::Overdue => $query->where('remaining_amount', '>', 0)->where('due_on', '<', $today),
                DebtStatus::Outstanding => $query->where('remaining_amount', '>', 0)->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '>=', $today)),
            };
        }

        foreach ($t->order() as $column) {
            $query->orderByDesc("{$table}.{$column}");
        }

        $records = $query->orderByDesc("{$table}.created_at")->orderBy("{$table}.id")->paginate((int) ($f['per_page'] ?? 25));
        RecordPage::prepare($t, $records->getCollection());

        return RecordResource::collection($records);
    }

    /** One record, removed ones too, with the changes waiting for it. */
    public function show(string $type, string $record): RecordResource
    {
        $t = RecordType::from($type);
        $model = $this->find($t, $record)->load(['employee', 'creator', 'approver']);
        RecordPage::prepare($t, [$model]);

        return new RecordResource($model);
    }

    /** Every approved change of one record, oldest first: who sent it, who approved it, from what to what. */
    public function history(string $type, string $record): JsonResponse
    {
        $t = RecordType::from($type);
        $this->find($t, $record);

        $items = RecordChangeItem::query()->where('record_id', $record)
            ->whereHas('changeSet', fn ($q) => $q->where('status', ChangeSetStatus::Approved))
            ->with(['changeSet.submitter', 'changeSet.reviewer'])
            ->orderBy('id')->get();

        $labels = (new RecordLabels)->prime($t, $items->flatMap(fn ($i) => [$i->old_values, $i->new_values]));

        return response()->json(['data' => $items->map(fn (RecordChangeItem $item) => [
            'change_set_id' => $item->change_set_id,
            'action' => ['value' => $item->action->value, 'label' => $item->action->label()],
            'note' => $item->changeSet->note,
            'submitted_by' => $item->changeSet->submitter?->fullName(),
            'submitted_at' => $item->changeSet->submitted_at->toIso8601String(),
            'approved_by' => $item->changeSet->reviewer?->fullName(),
            'approved_at' => $item->changeSet->reviewed_at?->toIso8601String(),
            'self_approved' => $item->changeSet->self_approved,
            'fields' => collect(array_keys(($item->new_values ?? []) + ($item->old_values ?? [])))->map(fn ($key) => [
                'field' => $key,
                'label' => RecordCatalogue::label($t, $key),
                'old_display' => $labels->display($t, $key, $item->old_values[$key] ?? null),
                'new_display' => $labels->display($t, $key, $item->new_values[$key] ?? null),
            ])->values(),
        ])]);
    }

    /** Add a record: waits for a reviewer, unless its author reviews. */
    public function store(StoreRecordRequest $request, string $type, SubmitRecordChanges $action): JsonResponse
    {
        $set = $action->handle(RecordType::from($type), [
            new RecordChange(RecordAction::Create, $request->validated('employee_id'), null, $request->validated('values')),
        ], $request->validated('note'), $request->user());

        return $this->submitted($set);
    }

    /** Correct a record's fields: only real changes are kept. */
    public function update(UpdateRecordRequest $request, string $type, string $record, SubmitRecordChanges $action): JsonResponse
    {
        $t = RecordType::from($type);
        $this->find($t, $record);

        $set = $action->handle($t, [new RecordChange(RecordAction::Update, null, $record, $request->validated('values'))], $request->validated('note'), $request->user());

        return $this->submitted($set);
    }

    /** Remove a record entered by mistake, with the reason: it is marked, never deleted. */
    public function destroy(DeleteRecordRequest $request, string $type, string $record, SubmitRecordChanges $action): JsonResponse
    {
        $t = RecordType::from($type);
        $this->find($t, $record);

        $set = $action->handle($t, [new RecordChange(RecordAction::Delete, null, $record, [])], $request->validated('note'), $request->user());

        return $this->submitted($set);
    }

    /** Many records of one kind together: checked whole, every error reported, reviewed and approved together. */
    public function batch(BatchRecordsRequest $request, string $type, SubmitRecordChanges $action): JsonResponse
    {
        $changes = array_map(fn (array $c, int $i) => new RecordChange(
            RecordAction::from($c['action']),
            $c['employee_id'] ?? null,
            $c['record_id'] ?? null,
            $c['values'],
            "changes.{$i}.values",
        ), $request->validated('changes'), array_keys($request->validated('changes')));

        return $this->submitted($action->handle(RecordType::from($type), $changes, $request->validated('note'), $request->user()));
    }

    private function find(RecordType $type, string $id): EmployeeRecord
    {
        return $type->model()::query()->findOrFail($id);
    }

    private function submitted($set): JsonResponse
    {
        return (new RecordChangeSetResource($set->load(['items.employee', 'submitter', 'reviewer'])))->response()->setStatusCode(201);
    }
}
