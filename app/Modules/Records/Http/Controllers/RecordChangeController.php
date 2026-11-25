<?php

namespace App\Modules\Records\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Http\Requests\DecisionRequest;
use App\Modules\Records\Actions\DecideRecordChangeSet;
use App\Modules\Records\Http\Requests\RecordChangeIndexRequest;
use App\Modules\Records\Http\Resources\RecordChangeSetResource;
use App\Modules\Records\Models\RecordChangeItem;
use App\Modules\Records\Models\RecordChangeSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RecordChangeController extends Controller
{
    /** The records' review queue (pending by default), oldest first; without the changes themselves. */
    public function index(RecordChangeIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $status = $f['status'] ?? 'pending';

        $sets = RecordChangeSet::query()
            ->with(['submitter', 'reviewer'])
            ->where('status', $status)
            ->when($f['record_type'] ?? null, fn ($q, $t) => $q->where('record_type', $t))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->whereIn('id', RecordChangeItem::query()->select('change_set_id')->where('employee_id', $id)))
            ->when(! empty($f['mine']), fn ($q) => $q->where('submitted_by', $request->user()->id))
            // The queue oldest first; decided sets newest decided first; or as asked.
            ->orderBy($f['sort'] ?? ($status === 'pending' ? 'submitted_at' : 'reviewed_at'), $f['direction'] ?? ($status === 'pending' ? 'asc' : 'desc'))
            ->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return RecordChangeSetResource::collection($sets);
    }

    /** One submission with every change: each record's approved value beside the proposed one. */
    public function show(RecordChangeSet $set): RecordChangeSetResource
    {
        return new RecordChangeSetResource($set->load(['items.employee', 'submitter', 'reviewer']));
    }

    /** Approve it whole: refused if a record changed or was removed since. Not one's own. */
    public function approve(DecisionRequest $request, RecordChangeSet $set, DecideRecordChangeSet $action): RecordChangeSetResource
    {
        return new RecordChangeSetResource($action->approve($set, $request->user(), $request->note())->load(['items.employee', 'submitter', 'reviewer']));
    }

    /** Refuse it with a reason: it goes back to its author. */
    public function reject(DecisionRequest $request, RecordChangeSet $set, DecideRecordChangeSet $action): RecordChangeSetResource
    {
        return new RecordChangeSetResource($action->reject($set, $request->user(), $request->note())->load(['items.employee', 'submitter', 'reviewer']));
    }

    /** Take back one's own pending submission. */
    public function withdraw(Request $request, RecordChangeSet $set, DecideRecordChangeSet $action): RecordChangeSetResource
    {
        return new RecordChangeSetResource($action->withdraw($set, $request->user())->load(['items.employee', 'submitter', 'reviewer']));
    }
}
