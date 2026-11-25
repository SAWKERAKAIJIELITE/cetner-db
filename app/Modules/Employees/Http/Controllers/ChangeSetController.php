<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Actions\DecideChangeSet;
use App\Modules\Employees\Http\Requests\ChangeSetIndexRequest;
use App\Modules\Employees\Http\Requests\DecisionRequest;
use App\Modules\Employees\Http\Resources\ChangeSetResource;
use App\Modules\Employees\Models\EmployeeChangeSet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ChangeSetController extends Controller
{
    /** The review queue (pending by default), oldest first; `mine=1` for the ones I submitted. */
    public function index(ChangeSetIndexRequest $request): AnonymousResourceCollection
    {
        $f = $request->validated();
        $status = $f['status'] ?? 'pending';

        $sets = EmployeeChangeSet::query()
            ->with(['items', 'submitter', 'reviewer', 'employee'])
            ->where('status', $status)
            ->when($f['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when(! empty($f['mine']), fn ($q) => $q->where('submitted_by', $request->user()->id))
            ->when(isset($f['needs_identity_review']), fn ($q) => $q->where('needs_identity_review', (bool) $f['needs_identity_review']))
            // The queue oldest first; decided sets newest decided first; or as asked.
            ->orderBy($f['sort'] ?? ($status === 'pending' ? 'submitted_at' : 'reviewed_at'), $f['direction'] ?? ($status === 'pending' ? 'asc' : 'desc'))
            ->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25))->withQueryString();

        return ChangeSetResource::collection($sets);
    }

    /** One submission with each field's approved and proposed value. */
    public function show(EmployeeChangeSet $set): ChangeSetResource
    {
        return new ChangeSetResource($set->load(['items', 'submitter', 'reviewer', 'employee']));
    }

    /** Approve: the values are written (refused if a value changed since). Not one's own; identity needs `identity.review`. */
    public function approve(DecisionRequest $request, EmployeeChangeSet $set, DecideChangeSet $action): ChangeSetResource
    {
        return new ChangeSetResource($action->approve($set, $request->user(), $request->note())->load(['items', 'submitter', 'reviewer', 'employee']));
    }

    /** Refuse with a reason: it goes back to its author. */
    public function reject(DecisionRequest $request, EmployeeChangeSet $set, DecideChangeSet $action): ChangeSetResource
    {
        return new ChangeSetResource($action->reject($set, $request->user(), $request->note())->load(['items', 'submitter', 'reviewer', 'employee']));
    }

    /** Take back one's own pending submission. */
    public function withdraw(Request $request, EmployeeChangeSet $set, DecideChangeSet $action): ChangeSetResource
    {
        return new ChangeSetResource($action->withdraw($set, $request->user())->load(['items', 'submitter', 'reviewer', 'employee']));
    }
}
