<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordAction;
use App\Support\ArabicText;

/** One change as sent: what to do, to which record or for which employee, with which values. */
final class RecordChange
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public readonly RecordAction $action,
        public readonly ?string $employeeId,
        public readonly ?string $recordId,
        public readonly array $values,
        /** Where its errors go: `values` for one change, `changes.3.values` in a batch. */
        public readonly string $prefix = 'values',
        /** «الرقم الذاتي», instead of the employee's id, for an addition (any digits). */
        public ?string $personalNumber = null,
    ) {
        $this->personalNumber = $personalNumber === null || trim($personalNumber) === '' ? null : ArabicText::digitsToAscii(trim($personalNumber));
    }
}
