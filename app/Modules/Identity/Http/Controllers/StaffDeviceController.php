<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Actions\ApproveDevice;
use App\Modules\Identity\Actions\RegisterDevice;
use App\Modules\Identity\Actions\RevokeDevice;
use App\Modules\Identity\Http\Requests\ApproveDeviceRequest;
use App\Modules\Identity\Http\Requests\DeviceIndexRequest;
use App\Modules\Identity\Http\Requests\ReasonRequest;
use App\Modules\Identity\Http\Requests\RegisterDeviceRequest;
use App\Modules\Identity\Http\Resources\DeviceRegistrationResource;
use App\Modules\Identity\Http\Resources\StaffDeviceResource;
use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Models\StaffUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffDeviceController extends Controller
{
    /**
     * Register this computer (201 new, 200 already registered with this key).
     *
     * @unauthenticated
     */
    public function register(RegisterDeviceRequest $request, RegisterDevice $action): JsonResponse
    {
        [$device, $created] = $action->handle($request->validated());

        return (new DeviceRegistrationResource($device))->response()->setStatusCode($created ? 201 : 200);
    }

    /**
     * The device's status, for the waiting screen.
     *
     * @unauthenticated
     */
    public function status(StaffDevice $device): DeviceRegistrationResource
    {
        return new DeviceRegistrationResource($device);
    }

    /** Devices, newest first; `status=pending` for the ones waiting. */
    public function index(DeviceIndexRequest $request): AnonymousResourceCollection
    {
        $devices = StaffDevice::query()
            ->with(['owner', 'approver', 'revoker'])
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderBy('created_at', $request->validated('direction') ?? 'desc')
            ->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 25))->withQueryString();

        return StaffDeviceResource::collection($devices);
    }

    /** Approve a pending device for its one owner, after comparing the fingerprint. */
    public function approve(ApproveDeviceRequest $request, StaffDevice $device, ApproveDevice $action): StaffDeviceResource
    {
        $owner = StaffUser::query()->findOrFail($request->validated('staff_user_id'));
        $device = $action->handle($device, $request->validated('fingerprint'), $owner, $request->user());

        return new StaffDeviceResource($device->load(['owner', 'approver', 'revoker']));
    }

    /** Revoke a device: its tokens end at once. */
    public function revoke(ReasonRequest $request, StaffDevice $device, RevokeDevice $action): StaffDeviceResource
    {
        $device = $action->handle($device, $request->validated('reason'), $request->user());

        return new StaffDeviceResource($device->load(['owner', 'approver', 'revoker']));
    }
}
