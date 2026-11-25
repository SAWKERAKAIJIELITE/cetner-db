<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Actions\ManageOrgUnits;
use App\Modules\Settings\Http\Requests\IncludeArchivedRequest;
use App\Modules\Settings\Http\Requests\RenameOrgUnitRequest;
use App\Modules\Settings\Http\Requests\StoreOrgUnitRequest;
use App\Modules\Settings\Http\Resources\OrgUnitResource;
use App\Modules\Settings\Models\OrgUnit;
use App\Modules\Settings\Support\OrgUnitTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrgUnitController extends Controller
{
    /** The whole structure, a parent before its children, each with its path. */
    public function index(IncludeArchivedRequest $request): AnonymousResourceCollection
    {
        return OrgUnitResource::collection(OrgUnitTree::all((bool) $request->validated('include_archived'), withCounts: true));
    }

    /** Add a unit under a parent (six levels at most), or at the top. */
    public function store(StoreOrgUnitRequest $request, ManageOrgUnits $action): JsonResponse
    {
        $parentId = $request->validated('parent_id');
        $parent = $parentId === null ? null : OrgUnit::query()->findOrFail($parentId);
        $unit = $action->add($request->validated('name'), $parent, $request->user());

        return (new OrgUnitResource($unit))->response()->setStatusCode(201);
    }

    /** Rename a unit. */
    public function update(RenameOrgUnitRequest $request, OrgUnit $unit, ManageOrgUnits $action): OrgUnitResource
    {
        return new OrgUnitResource($action->rename($unit, $request->validated('name'), $request->user()));
    }

    /** Archive a unit whose children are archived. */
    public function archive(Request $request, OrgUnit $unit, ManageOrgUnits $action): OrgUnitResource
    {
        return new OrgUnitResource($action->archive($unit, $request->user()));
    }

    /** Restore an archived unit under an active parent. */
    public function restore(Request $request, OrgUnit $unit, ManageOrgUnits $action): OrgUnitResource
    {
        return new OrgUnitResource($action->restore($unit, $request->user()));
    }
}
