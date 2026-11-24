<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Employees\Actions\UpdateFieldDefinition;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Http\Requests\UpdateFieldDefinitionRequest;
use App\Modules\Employees\Http\Resources\FieldDefinitionResource;
use App\Modules\Employees\Models\FieldDefinition;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FieldDefinitionController extends Controller
{
    /** «دليل الحقول»: every field with its type, list, cycle, completeness flag and identity flag, in order. */
    public function index(): AnonymousResourceCollection
    {
        return FieldDefinitionResource::collection(FieldDefinition::query()->orderBy('sort_order')->get());
    }

    /** Change whether a field counts for completeness, or its cycle; every file's completeness follows. */
    public function update(UpdateFieldDefinitionRequest $request, FieldDefinition $definition, UpdateFieldDefinition $action): FieldDefinitionResource
    {
        $counts = $request->has('counts_for_completeness') ? $request->boolean('counts_for_completeness') : null;
        $cycle = $request->validated('cycle') === null ? null : Cycle::from($request->validated('cycle'));

        return new FieldDefinitionResource($action->handle($definition, $counts, $cycle, $request->user()));
    }
}
