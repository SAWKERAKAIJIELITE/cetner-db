<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Actions\ManageListItems;
use App\Modules\Settings\Http\Requests\IncludeArchivedRequest;
use App\Modules\Settings\Http\Requests\ReorderListRequest;
use App\Modules\Settings\Http\Requests\StoreListItemRequest;
use App\Modules\Settings\Http\Requests\UpdateListItemRequest;
use App\Modules\Settings\Http\Resources\LookupItemResource;
use App\Modules\Settings\Http\Resources\LookupListResource;
use App\Modules\Settings\Models\LookupItem;
use App\Modules\Settings\Models\LookupList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LookupListController extends Controller
{
    /** Every list with its items, in order: what the forms offer. Archived items with `include_archived=1`. */
    public function index(IncludeArchivedRequest $request): AnonymousResourceCollection
    {
        $archived = (bool) $request->validated('include_archived');

        $lists = LookupList::query()
            ->with(['items' => fn ($q) => $archived ? $q : $q->whereNull('archived_at')])
            ->orderBy('id')
            ->get();

        return LookupListResource::collection($lists);
    }

    /** One list with its items. */
    public function show(IncludeArchivedRequest $request, LookupList $list): LookupListResource
    {
        $archived = (bool) $request->validated('include_archived');

        return new LookupListResource($list->load(['items' => fn ($q) => $archived ? $q : $q->whereNull('archived_at')]));
    }

    /** Add an item (a duplicate, typed another way, is refused). */
    public function store(StoreListItemRequest $request, LookupList $list, ManageListItems $action): JsonResponse
    {
        $item = $action->add($list, $request->validated('label'), $request->validated('sort_order'), $this->actor($request));

        return (new LookupItemResource($item))->response()->setStatusCode(201);
    }

    /** Rename an item or move it in the order. */
    public function update(UpdateListItemRequest $request, LookupList $list, LookupItem $item, ManageListItems $action): LookupItemResource
    {
        $this->belongs($list, $item);

        return new LookupItemResource($action->update($item, $request->validated('label'), $request->validated('sort_order'), $this->actor($request)));
    }

    /** Archive an item: not offered any more; files that use it keep it. System items are never archived. */
    public function archive(Request $request, LookupList $list, LookupItem $item, ManageListItems $action): LookupItemResource
    {
        $this->belongs($list, $item);

        return new LookupItemResource($action->archive($item, $this->actor($request)));
    }

    /** Offer an archived item again. */
    public function restore(Request $request, LookupList $list, LookupItem $item, ManageListItems $action): LookupItemResource
    {
        $this->belongs($list, $item);

        return new LookupItemResource($action->restore($item, $this->actor($request)));
    }

    /** Put the active items in a new order (every active id, once). */
    public function reorder(ReorderListRequest $request, LookupList $list, ManageListItems $action): LookupListResource
    {
        $action->reorder($list, array_map('intval', $request->validated('item_ids')), $this->actor($request));

        return new LookupListResource($list->load('items'));
    }

    private function belongs(LookupList $list, LookupItem $item): void
    {
        if ($item->lookup_list_id !== $list->id) {
            throw new NotFoundHttpException;
        }
    }

    private function actor(Request $request): StaffUser
    {
        return $request->user();
    }
}
