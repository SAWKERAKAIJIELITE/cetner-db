<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Http\Requests\AuditEventIndexRequest;
use App\Modules\Audit\Http\Resources\AuditEventResource;
use App\Modules\Audit\Models\AuditEvent;
use App\Modules\Audit\Support\AuditEvents;
use App\Modules\Audit\Support\AuditTrail;
use App\Support\BusinessDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditEventController extends Controller
{
    /**
     * The audit log, newest first. `audit.view` reads everything; `audit.view_related` the operations only.
     */
    public function index(AuditEventIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $operationsOnly = ! $request->user()->hasPermission('audit.view');

        $events = AuditEvent::query()
            ->with('actor')
            ->when($operationsOnly, fn ($q) => $q->where('category', 'operations'))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['event'] ?? null, function ($q, string $event) {
                str_ends_with($event, '.')
                    ? $q->where('event', 'like', addcslashes($event, '%_\\').'%')
                    : $q->where('event', $event);
            })
            ->when($filters['actor_id'] ?? null, fn ($q, $actor) => $q->where('actor_id', $actor))
            ->when(array_key_exists('related_id', $filters) && $filters['related_id'] !== null && $filters['related_id'] !== '',
                fn ($q) => $q->whereRaw('related_ids @> ARRAY[?]::text[]', [(string) $filters['related_id']]))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', BusinessDay::bounds($from)[0]))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', BusinessDay::bounds($to)[1]))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        return AuditEventResource::collection($events);
    }

    /** The events that can appear, with their labels, for the filter. */
    public function events(Request $request): JsonResponse
    {
        $operationsOnly = ! $request->user()->hasPermission('audit.view');

        $events = collect(AuditEvents::LABELS)
            ->map(fn (string $label, string $event) => ['event' => $event, 'label' => $label, 'category' => AuditTrail::categoryOf($event)])
            ->when($operationsOnly, fn ($c) => $c->where('category', 'operations'))
            ->values();

        return response()->json(['data' => $events]);
    }
}
