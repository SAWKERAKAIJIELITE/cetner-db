<?php

namespace App\Modules\Records\Support;

use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

/**
 * Which movements are leaves (D-044): the four coded leave types (`leave_internal`, `leave_external`,
 * `leave_sick`, `leave_emergency`), renamed or not, and any type the settings managers add whose name begins with
 * «إجازة», as the workbook counts them. Only internal and external leave come out of the year's balance (owner,
 * 25 Nov).
 */
final class LeaveTypes
{
    /** The leave types that reduce «رصيد الإجازات». */
    public const FROM_BALANCE = ['leave_internal', 'leave_external'];

    /** @var array<int, bool>|null */
    private static ?array $leaves = null;

    public static function isLeave(int $movementTypeId): bool
    {
        return self::all()[$movementTypeId] ?? false;
    }

    /** SQL true for a leave type of the list joined as `$alias`; bind sqlBindings(). */
    public static function sql(string $alias): string
    {
        return "({$alias}.code LIKE 'leave\\_%' OR {$alias}.search_key LIKE ?)";
    }

    /** @return list<string> */
    public static function sqlBindings(): array
    {
        return [ArabicText::searchKey('إجازة').'%'];
    }

    /** @return array<int, bool> */
    private static function all(): array
    {
        if (self::$leaves !== null && ! app()->runningUnitTests()) {
            return self::$leaves;
        }

        return self::$leaves = DB::table('movement_types AS t')->whereRaw(self::sql('t'), self::sqlBindings())
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
