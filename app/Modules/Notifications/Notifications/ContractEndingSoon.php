<?php

namespace App\Modules\Notifications\Notifications;

/** A contract ends within the window (30 days): once per file and end date. */
class ContractEndingSoon extends StaffNotice
{
    public function __construct(
        private readonly string $employeeId,
        private readonly ?string $personalNumber,
        private readonly string $employeeName,
        private readonly string $endsOn,
        private readonly int $daysLeft,
    ) {}

    public function kind(): string
    {
        return 'contract_ending_soon';
    }

    public function title(): string
    {
        return 'عقد ينتهي قريباً';
    }

    public function message(): string
    {
        return "ينتهي عقد {$this->employeeName} في {$this->endsOn} (بعد {$this->daysLeft} يوماً).";
    }

    public function details(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'personal_number' => $this->personalNumber,
            'employee_name' => $this->employeeName,
            'ends_on' => $this->endsOn,
            'days_left' => $this->daysLeft,
        ];
    }
}
