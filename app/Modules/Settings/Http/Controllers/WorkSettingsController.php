<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Actions\UpdateWorkSettings;
use App\Modules\Settings\Http\Requests\UpdateWorkSettingsRequest;
use App\Modules\Settings\Http\Resources\WorkSettingsResource;
use App\Modules\Settings\Models\WorkSettings;

class WorkSettingsController extends Controller
{
    /** The branch's working rules and the four review windows. */
    public function show(): WorkSettingsResource
    {
        return new WorkSettingsResource(WorkSettings::current());
    }

    /** Change some of them (the fields sent). */
    public function update(UpdateWorkSettingsRequest $request, UpdateWorkSettings $action): WorkSettingsResource
    {
        return new WorkSettingsResource($action->handle($request->validated(), $request->user()));
    }
}
