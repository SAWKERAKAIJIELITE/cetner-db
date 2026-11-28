<?php

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\ValueLabels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file behind on its reviews: «آخر مراجعة» of each cycle and «الدورة المتأخرة».
 *
 * @mixin Employee
 */
class OverdueReviewResource extends JsonResource
{
    /** @var array<string, string> cycle => the day before which it is overdue */
    public static array $before = [];

    /** Set for each page by the controller: one lookup of the labels for the page. */
    public static ?ValueLabels $labels = null;

    public function toArray(Request $request): array
    {
        self::$labels ??= new ValueLabels;
        $cycles = [];

        foreach (Cycle::reviewed() as $cycle) {
            $last = $this->getAttribute('last_'.$cycle->value);
            $last = $last === null ? null : substr((string) $last, 0, 10);

            $cycles[] = [
                'cycle' => $cycle->value,
                'cycle_label' => $cycle->label(),
                /** null when never reviewed */
                'last_reviewed_on' => $last,
                'overdue' => $last === null || $last < (self::$before[$cycle->value] ?? ''),
            ];
        }

        return [
            'id' => $this->id,
            'personal_number' => $this->personal_number,
            'full_name' => $this->fullName(),
            'org_unit' => self::$labels->ref('org_unit_id', $this->org_unit_id),
            /** @var list<array{cycle: string, cycle_label: string, last_reviewed_on: ?string, overdue: bool}> */
            'cycles' => $cycles,
            /** The overdue cycles' keys: «الدورة المتأخرة». */
            'overdue_cycles' => array_values(array_column(array_filter($cycles, fn ($c) => $c['overdue']), 'cycle')),
        ];
    }
}
