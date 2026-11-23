<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Actions\ManageListItems;
use App\Modules\Settings\Http\Requests\IncludeArchivedRequest;
use App\Modules\Settings\Http\Requests\ReorderListRequest;
use App\Modules\Settings\Http\Requests\StoreListItemRequest;
use App\Modules\Settings\Http\Requests\UpdateListItemRequest;
use App\Modules\Settings\Http\Resources\LookupItemResource;
use App\Modules\Settings\Http\Resources\LookupListResource;
use App\Modules\Settings\Support\ManagedList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** `{list}` is a list's key (404 when unknown); `{item}` an item's id in that list's own table. */
class LookupListController extends Controller
{
    /** Every list with its items, in order: what the forms offer. Archived items with `include_archived=1`. */
    public function index(IncludeArchivedRequest $request): AnonymousResourceCollection
    {
        $archived = (bool) $request->validated('include_archived');

        return LookupListResource::collection(
            array_map(fn (ManagedList $list) => $list->withItems($archived), ManagedList::all())
        );
    }

    /** One list with its items. */
    public function show(IncludeArchivedRequest $request, string $list): LookupListResource
    {
        return new LookupListResource(ManagedList::findOrFail($list)->withItems((bool) $request->validated('include_archived')));
    }

    /** Add an item (a duplicate, typed another way, is refused). */
    public function store(StoreListItemRequest $request, string $list, ManageListItems $action): JsonResponse
    {
        $item = $action->add(ManagedList::findOrFail($list), $request->validated('label'), $request->validated('sort_order'), $request->user());

        return (new LookupItemResource($item))->response()->setStatusCode(201);
    }

    /** Rename an item or move it in the order. */
    public function update(UpdateListItemRequest $request, string $list, int $item, ManageListItems $action): LookupItemResource
    {
        return new LookupItemResource($action->update(
            ManagedList::findOrFail($list), $item, $request->validated('label'), $request->validated('sort_order'), $request->user(),
        ));
    }

    /** Archive an item: not offered any more; files that use it keep it. System items are never archived. */
    public function archive(Request $request, string $list, int $item, ManageListItems $action): LookupItemResource
    {
        return new LookupItemResource($action->archive(ManagedList::findOrFail($list), $item, $request->user()));
    }

    /** Offer an archived item again. */
    public function restore(Request $request, string $list, int $item, ManageListItems $action): LookupItemResource
    {
        return new LookupItemResource($action->restore(ManagedList::findOrFail($list), $item, $request->user()));
    }

    /** Put the active items in a new order (every active id, once). */
    public function reorder(ReorderListRequest $request, string $list, ManageListItems $action): LookupListResource
    {
        $managed = ManagedList::findOrFail($list);
        $action->reorder($managed, array_map('intval', $request->validated('item_ids')), $request->user());

        return new LookupListResource($managed->withItems());
    }
}
