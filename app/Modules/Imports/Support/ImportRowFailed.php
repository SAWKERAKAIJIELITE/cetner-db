<?php

namespace App\Modules\Imports\Support;

use RuntimeException;
use Throwable;

/** A row an approved import could not write (D-053): the whole import is rolled back and says which and why. */
final class ImportRowFailed extends RuntimeException
{
    public function __construct(public readonly string $sheet, public readonly int $row, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
