<?php

namespace App\Modules\Records\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Records\Actions\SubmitRecordChanges;
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
use App\Modules\Records\Support\RecordRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            'record_type' => $t->value,
            'record_type_label' => $t->label(),
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

    /**
     * A register, newest first: the approved records and the additions waiting for review («قيد التدقيق»), each
     * with the corrections waiting for it. Filters stay in the page links.
     */
    public function index(RecordIndexRequest $request, string $type): AnonymousResourceCollection
    {
        $t = RecordType::from($type);
        $f = $request->validated();

        $records = RecordRegister::query($t, $f)->with(['employee', 'creator', 'approver'])
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();
        RecordPage::prepare($t, $records->getCollection());

        return RecordResource::collection($records);
    }

    /** One record: approved (removed ones too) or an addition waiting for review. */
    public function show(string $type, string $record): RecordResource
    {
        $t = RecordType::from($type);
        $model = RecordRegister::find($t, $record) ?? abort(404);
        $model->load(['employee', 'creator', 'approver']);
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
            'action' => $item->action->value,
            'action_label' => $item->action->label(),
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
            new RecordChange(RecordAction::Create, $request->validated('employee_id'), null, $request->validated('values'), 'values', $request->validated('personal_number')),
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

        $set = $action->handle($t, [new RecordChange(RecordAction::Delete, null, $record, [])], $request->validated('reason'), $request->user());

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
            $c['personal_number'] ?? null,
        ), $request->validated('changes'), array_keys($request->validated('changes')));

        return $this->submitted($action->handle(RecordType::from($type), $changes, $request->validated('note'), $request->user()));
    }

    /** An approved record (removed ones too), for a change. */
    private function find(RecordType $type, string $id): EmployeeRecord
    {
        return $type->model()::query()->findOrFail($id);
    }

    private function submitted($set): JsonResponse
    {
        return (new RecordChangeSetResource($set->load(['items.employee', 'submitter', 'reviewer'])))->response()->setStatusCode(201);
    }
}
