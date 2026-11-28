<?php

namespace App\Modules\Reports\Support;

use Carbon\CarbonImmutable;

/** «السنة» and, if chosen, «الشهر» of «داش بورد الحركات» (D-051): dated records fall in it by their date, attendance by its year and month. */
final readonly class ReportPeriod
{
    public function __construct(public int $year, public ?int $month = null) {}

    public function from(): string
    {
        return CarbonImmutable::create($this->year, $this->month ?? 1, 1)->format('Y-m-d');
    }

    public function to(): string
    {
        $start = CarbonImmutable::create($this->year, $this->month ?? 12, 1);

        return $start->endOfMonth()->format('Y-m-d');
    }

    /** @return array{year: int, month: ?int, from: string, to: string} */
    public function toArray(): array
    {
        return ['year' => $this->year, 'month' => $this->month, 'from' => $this->from(), 'to' => $this->to()];
    }
}
