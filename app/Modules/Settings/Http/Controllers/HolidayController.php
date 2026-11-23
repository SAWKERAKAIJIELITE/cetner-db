<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Actions\ManageHolidays;
use App\Modules\Settings\Http\Requests\HolidayIndexRequest;
use App\Modules\Settings\Http\Requests\HolidayRequest;
use App\Modules\Settings\Http\Resources\HolidayResource;
use App\Modules\Settings\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class HolidayController extends Controller
{
    /** Holidays by start date; `year` for those touching a year. */
    public function index(HolidayIndexRequest $request): AnonymousResourceCollection
    {
        $year = $request->validated('year');

        $holidays = Holiday::query()
            ->when($year, fn ($q) => $q->where('starts_on', '<=', "{$year}-12-31")->where('ends_on', '>=', "{$year}-01-01"))
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return HolidayResource::collection($holidays);
    }

    /** Add a holiday. */
    public function store(HolidayRequest $request, ManageHolidays $action): JsonResponse
    {
        return (new HolidayResource($action->add($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    /** Correct a holiday: the fields sent change. */
    public function update(HolidayRequest $request, Holiday $holiday, ManageHolidays $action): HolidayResource
    {
        return new HolidayResource($action->update($holiday, $request->validated(), $request->user()));
    }

    /** Remove a holiday entered by mistake. */
    public function destroy(Request $request, Holiday $holiday, ManageHolidays $action): Response
    {
        $action->delete($holiday, $request->user());

        return response()->noContent();
    }
}
