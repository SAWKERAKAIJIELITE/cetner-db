<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\StaffDevice;
use App\Modules\Identity\Support\DeviceCrypto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffDevice
 */
class StaffDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            /** Upper case in groups of four, as the device shows it. */
            'fingerprint' => DeviceCrypto::displayFingerprint($this->fingerprint),
            'reported_mac' => $this->reported_mac,
            'os_info' => $this->os_info,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'owner' => $this->owner ? new StaffUserSummaryResource($this->owner) : null,
            'approved_by' => $this->approver ? new StaffUserSummaryResource($this->approver) : null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'revoked_by' => $this->revoker ? new StaffUserSummaryResource($this->revoker) : null,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoke_reason' => $this->revoke_reason,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
