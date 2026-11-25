<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\NotificationIndexRequest;
use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Notifications\Support\Badges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The signed-in user's own notifications; nobody sees another's (D-046). */
class NotificationController extends Controller
{
    /** Mine, newest first. */
    public function index(NotificationIndexRequest $request): AnonymousResourceCollection
    {
        $notifications = $request->user()->notifications()
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->reorder()->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 25))->withQueryString();

        return NotificationResource::collection($notifications);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => (int) $request->user()->unreadNotifications()->count()]);
    }

    /** Mark one of mine read: 404 for anyone else's. */
    public function read(Request $request, string $notification): NotificationResource
    {
        $found = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $found->markAsRead();

        return new NotificationResource($found);
    }

    public function readAll(Request $request): JsonResponse
    {
        return response()->json(['marked' => (int) $request->user()->unreadNotifications()->update(['read_at' => now()])]);
    }

    /** The sidebar's counts of the sections the user sees; null for a section they do not. */
    public function badges(Request $request): JsonResponse
    {
        return response()->json(['data' => Badges::for($request->user())]);
    }
}
