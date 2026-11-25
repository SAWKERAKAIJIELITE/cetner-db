<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordAction;

/** One change as sent: what to do, to which record or for which employee, with which values. */
final readonly class RecordChange
{
    /** @param array<string, mixed> $values */
    public function __construct(
        public RecordAction $action,
        public ?string $employeeId,
        public ?string $recordId,
        public array $values,
        /** Where its errors go: `values` for one change, `changes.3.values` in a batch. */
        public string $prefix = 'values',
    ) {}
}
