<?php

namespace App\Modules\Notifications\Notifications;

/** Your submission was sent back: to its author, with the reviewer's reason. */
class ChangeRejected extends StaffNotice
{
    /**
     * @param  'employees'|'records'  $area  the file's changes or a record's
     */
    public function __construct(
        private readonly string $area,
        private readonly string $changeSetId,
        private readonly string $subject,
        private readonly ?string $employeeId,
        private readonly ?string $employeeName,
        private readonly string $reason,
        private readonly string $reviewerName,
        private readonly ?string $recordType = null,
    ) {}

    public function kind(): string
    {
        return 'change_rejected';
    }

    public function title(): string
    {
        return 'أُعيد إليك إرسال';
    }

    public function message(): string
    {
        return "ردّ {$this->reviewerName} {$this->subject}".($this->employeeName ? " ({$this->employeeName})" : '').": {$this->reason}";
    }

    public function details(): array
    {
        return [
            'area' => $this->area,
            'change_set_id' => $this->changeSetId,
            'record_type' => $this->recordType,
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'reason' => $this->reason,
            'reviewer_name' => $this->reviewerName,
        ];
    }
}
