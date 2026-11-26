<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Settings\Support\ListCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * What a movement makes of the employee's file (owner, 25 Nov; D-048), by its type's code, so renaming the type
 * changes nothing:
 *
 * | Type | The file takes |
 * | ---- | -------------- |
 * | نقل داخلي (`transfer`) | the new unit (required); a new position or title if given |
 * | تغيير حالة (`status_change`) | the new «الحالة الوظيفية» (required) |
 * | ترقية (`promotion`) | the new position or title (one at least) |
 * | تكليف، تعيين، تثبيت (`assignment`, `appointment`, `confirmation`) | its date as «تاريخ التكليف / التعيين / التثبيت»; a new status, unit, position or title if given |
 * | تسريح (`discharge`) | «تاريخ التسريح» = its date, «الحالة الوظيفية» «مسرح» (or the one given), «حالة الموظف» «غير فعال» |
 * | استقالة (`resignation`) | «تاريخ التسريح» = its date, «حالة الموظف» «غير فعال»; a new status if given |
 *
 * Leaves, rewards, deductions and courses change nothing. A target given for a type that does not take it is refused.
 */
final class MovementEffects
{
    /** target => the file's field */
    public const TARGETS = [
        'new_org_unit_id' => 'org_unit_id',
        'new_job_status_id' => 'job_status_id',
        'new_position_level_id' => 'position_level_id',
        'new_job_title_id' => 'job_title_id',
    ];

    /** @var array<string, array{allows: list<string>, requires?: list<string>, one_of?: list<string>, date?: string, sets?: array<string, array{0: string, 1: string}>}> */
    private const BY_CODE = [
        'transfer' => ['allows' => ['new_org_unit_id', 'new_position_level_id', 'new_job_title_id'], 'requires' => ['new_org_unit_id']],
        'status_change' => ['allows' => ['new_job_status_id'], 'requires' => ['new_job_status_id']],
        'promotion' => ['allows' => ['new_position_level_id', 'new_job_title_id'], 'one_of' => ['new_position_level_id', 'new_job_title_id']],
        'assignment' => ['allows' => ['new_job_status_id', 'new_org_unit_id', 'new_position_level_id', 'new_job_title_id'], 'date' => 'assignment_date'],
        'appointment' => ['allows' => ['new_job_status_id', 'new_org_unit_id', 'new_position_level_id', 'new_job_title_id'], 'date' => 'appointment_date'],
        'confirmation' => ['allows' => ['new_job_status_id', 'new_org_unit_id', 'new_position_level_id', 'new_job_title_id'], 'date' => 'confirmation_date'],
        'discharge' => ['allows' => ['new_job_status_id'], 'date' => 'discharge_date',
            'sets' => ['job_status_id' => ['job_status', 'discharged'], 'employee_status_id' => ['employee_status', 'inactive']]],
        'resignation' => ['allows' => ['new_job_status_id'], 'date' => 'discharge_date',
            'sets' => ['employee_status_id' => ['employee_status', 'inactive']]],
    ];

    /** The codes of the movement types that change the file. */
    public static function codes(): array
    {
        return array_keys(self::BY_CODE);
    }

    /**
     * The targets a movement of this type may not have, and the ones it needs.
     *
     * @param  array<string, mixed>  $values  the movement as it would be
     * @return array<string, string> error key => message
     */
    public static function check(int $movementTypeId, array $values, string $prefix): array
    {
        $rule = self::BY_CODE[self::code($movementTypeId)] ?? ['allows' => []];
        $errors = [];

        foreach (array_keys(self::TARGETS) as $target) {
            if (($values[$target] ?? null) !== null && ! in_array($target, $rule['allows'], true)) {
                $errors[$prefix.'.'.$target] = __('records.movement_target_unused', ['field' => RecordCatalogue::label(RecordType::Movements, $target)]);
            }
        }

        foreach ($rule['requires'] ?? [] as $target) {
            if (($values[$target] ?? null) === null) {
                $errors[$prefix.'.'.$target] = __('records.movement_target_required', ['field' => RecordCatalogue::label(RecordType::Movements, $target)]);
            }
        }

        if (isset($rule['one_of']) && array_filter(array_map(fn ($t) => $values[$t] ?? null, $rule['one_of'])) === []) {
            $errors[$prefix.'.'.$rule['one_of'][0]] = __('records.movement_target_one_of');
        }

        return $errors;
    }

    /**
     * What the file takes from this movement: file field => value, in the file's normal form.
     *
     * @return array<string, mixed>
     */
    public static function of(EmployeeMovement $movement): array
    {
        $rule = self::BY_CODE[self::code((int) $movement->getAttribute('movement_type_id'))] ?? null;

        if ($rule === null) {
            return [];
        }

        $effects = [];

        foreach ($rule['sets'] ?? [] as $field => [$list, $code]) {
            $effects[$field] = (int) DB::table(ListCatalogue::table($list))->where('code', $code)->value('id');
        }

        if (isset($rule['date'])) {
            $effects[$rule['date']] = $movement->getAttribute('occurred_on')->format('Y-m-d');
        }

        foreach (self::TARGETS as $target => $field) {
            if ($movement->getAttribute($target) !== null) {
                $effects[$field] = (int) $movement->getAttribute($target);
            }
        }

        return $effects;
    }

    /** The file fields a movement of this type may change, for the register. */
    public static function fields(int $movementTypeId): array
    {
        $rule = self::BY_CODE[self::code($movementTypeId)] ?? null;

        if ($rule === null) {
            return [];
        }

        return array_values(array_unique([
            ...array_keys($rule['sets'] ?? []),
            ...(isset($rule['date']) ? [$rule['date']] : []),
            ...array_map(fn ($t) => self::TARGETS[$t], $rule['allows']),
        ]));
    }

    private static function code(int $movementTypeId): ?string
    {
        return DB::table('movement_types')->where('id', $movementTypeId)->value('code');
    }
}
