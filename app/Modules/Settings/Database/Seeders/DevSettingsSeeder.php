<?php

namespace App\Modules\Settings\Database\Seeders;

use App\Modules\Settings\Models\Holiday;
use App\Modules\Settings\Models\OrgUnit;
use Illuminate\Database\Seeder;

/**
 * Local only: the workbook's sample structure and 2026 holidays (D-029). A real installation enters its own.
 */
class DevSettingsSeeder extends Seeder
{
    /** The workbook's rows: level 1 → level 4. */
    private const UNITS = [
        ['القيادة العامة', 'مديرية العمليات', 'فرع العمليات', 'شعبة الميدان'],
        ['القيادة العامة', 'مديرية الإدارة', 'فرع الموارد البشرية', 'شعبة التجنيد'],
        ['القيادة العامة', 'مديرية الإدارة', 'فرع المالية', 'شعبة الرواتب'],
        ['القيادة العامة', 'مديرية التدريب', 'فرع التأهيل', 'شعبة الدورات'],
        ['القيادة العامة', 'مديرية اللوجستيات', 'فرع التسليح', 'شعبة المستودعات'],
        ['القيادة العامة', 'مديرية الاتصالات', 'فرع الشبكات'],
        ['القيادة العامة', 'مديرية الأمن', 'فرع الاستخبارات'],
        ['القيادة العامة', 'مديرية الصحة', 'فرع الإسعاف'],
    ];

    private const HOLIDAYS = [
        ['عيد الفطر', '2026-03-20', '2026-03-25'],
        ['عيد الأضحى', '2026-05-27', '2026-06-02'],
        ['اليوم الوطني', '2026-09-23', '2026-09-23'],
        ['يوم التأسيس', '2026-02-22', '2026-02-22'],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $path) {
            $parent = null;

            foreach ($path as $name) {
                $unit = OrgUnit::query()->where('parent_id', $parent?->id)->where('name', $name)->first();

                if ($unit === null) {
                    $unit = new OrgUnit(['name' => $name]);
                    $unit->forceFill(['parent_id' => $parent?->id, 'level' => ($parent?->level ?? 0) + 1])->save();
                }

                $parent = $unit;
            }
        }

        foreach (self::HOLIDAYS as [$name, $from, $to]) {
            Holiday::query()->firstOrCreate(['name' => $name, 'starts_on' => $from], ['ends_on' => $to]);
        }
    }
}
