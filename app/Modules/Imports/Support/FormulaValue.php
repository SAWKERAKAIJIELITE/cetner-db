<?php

namespace App\Modules\Imports\Support;

/** A formula cell's last computed value (D-053): used for a field only, never a sign that a row holds data. */
final readonly class FormulaValue
{
    public function __construct(public mixed $value) {}
}
