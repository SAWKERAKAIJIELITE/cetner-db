<?php

namespace App\Modules\Documents\Support;

/** A kind of document as the field guide has it now (D-049). */
final readonly class DocumentKind
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $workbookField,
        public ?string $workbookColumn,
        public ?string $cycle,
        public ?string $cycleLabel,
        public bool $countsForCompleteness,
        public bool $onConductRecord,
    ) {}
}
