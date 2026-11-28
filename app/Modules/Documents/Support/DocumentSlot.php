<?php

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Support\Collection;

/** One of the 13 kinds on an employee's file: its current file, if any, and the uploads waiting for review. */
final readonly class DocumentSlot
{
    /** @param Collection<int, EmployeeDocument> $pending */
    public function __construct(
        public DocumentKind $kind,
        public ?EmployeeDocument $current,
        public Collection $pending,
    ) {}
}
