<?php

namespace App\Modules\Imports\Support;

use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Imports\Enums\ImportSheet;
use App\Modules\Records\Support\RecordCatalogue;
use App\Support\ArabicText;

/**
 * What each column of the workbook's sheets is (D-053), found by its title, not its letter, so a copy with columns
 * moved, added or removed still imports. A title is compared by its search key (any hamza, ta marbuta or spacing).
 */
final class ImportColumns
{
    /** A field of the file or of the register. */
    public const FIELD = 'field';

    /** «الرقم الذاتي» of a register's row. */
    public const PERSONAL_NUMBER = 'personal_number';

    /** One of the six levels of «جهة العمل» (U, AP:AT). */
    public const ORG_LEVEL = 'org_level';

    /** «جهة العمل الحالية - مستوى تنظيمي 2 / 3» (AV:AW): `current_work_unit_id` (OD-15). */
    public const WORK_LEVEL = 'work_level';

    /** «آخر مراجعة - الدورة …»: a confirmation of the cycle on that day. */
    public const REVIEW = 'review';

    /** A «رابط …» of the 13 attachments: a link is not a file, so it is not imported. */
    public const LINK = 'link';

    /** «اسم الموظف» of a register: checked against the file's name. */
    public const NAME_CHECK = 'name_check';

    /** A movement's typed «عدد الأيام»: checked against its period. */
    public const TYPED_DAYS = 'typed_days';

    /** A formula of the workbook: computed here, never imported. */
    public const COMPUTED = 'computed';

    private const LEVELS = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

    private const CYCLE_TITLES = ['monthly' => 'الشهرية', 'quarterly' => 'الربعية', 'semi_annual' => 'النصف سنوية', 'annual' => 'السنوية'];

    /** @var array<string, array<string, array{0: string, 1: string|int|null}>> */
    private static array $maps = [];

    /**
     * title search key => [what, which]
     *
     * @return array<string, array{0: string, 1: string|int|null}>
     */
    public static function map(ImportSheet $sheet): array
    {
        if (isset(self::$maps[$sheet->value])) {
            return self::$maps[$sheet->value];
        }

        $map = [];
        $add = function (string $title, string $what, string|int|null $which = null) use (&$map) {
            $map[ArabicText::searchKey($title)] ??= [$what, $which];
        };

        if ($sheet === ImportSheet::Employees) {
            foreach (FieldCatalogue::FIELDS as $key => $field) {
                if (! in_array($field['type'], ['org_unit'], true)) {
                    $add($field['label'], self::FIELD, $key);
                }
            }

            foreach (self::LEVELS as $i => $level) {
                $add('المستوى التنظيمي '.$level, self::ORG_LEVEL, $i + 1);
            }

            $add('جهة العمل الحالية - مستوى تنظيمي 2', self::WORK_LEVEL, 2);
            $add('جهة العمل الحالية - مستوى تنظيمي 3', self::WORK_LEVEL, 3);

            foreach (self::CYCLE_TITLES as $cycle => $title) {
                $add('آخر مراجعة - الدورة '.$title, self::REVIEW, $cycle);
            }

            foreach (DocumentCatalogue::FILE_KINDS as $kind => $info) {
                $add($info['workbook'], self::LINK, $kind);
            }

            foreach (['م', 'الاسم الثلاثي', 'العمر', 'سنوات الخدمة', 'جهة العمل الحالية (أدق مستوى)', 'عليه ديون', 'إجمالي الديون المتبقية',
                'المتبقي على انتهاء العقد (يوم)', 'عدد الوثائق المرفقة (من 13)', 'نسبة اكتمال البيانات', 'الحقول الناقصة حسب دورة التحديث'] as $title) {
                $add($title, self::COMPUTED);
            }
        } else {
            $type = $sheet->recordType();

            foreach (RecordCatalogue::fields($type) as $key => $field) {
                $add($field['label'], self::FIELD, $key);
            }

            $add('الرقم الذاتي', self::PERSONAL_NUMBER);
            $add('اسم الموظف', self::NAME_CHECK);

            if ($sheet === ImportSheet::Movements) {
                $add('عدد الأيام', self::TYPED_DAYS);
            }

            foreach (['#', 'جهة العمل', 'المسمى الوظيفي', 'السنة', 'الشهر', 'مفتاح', 'أيام الغياب', 'نسبة الغياب', 'المبلغ المتبقي', 'حالة الدين'] as $title) {
                $add($title, self::COMPUTED);
            }
        }

        return self::$maps[$sheet->value] = $map;
    }

    /** @return array{0: string, 1: string|int|null}|null */
    public static function of(ImportSheet $sheet, string $title): ?array
    {
        return self::map($sheet)[ArabicText::searchKey(trim($title))] ?? null;
    }

    /**
     * The columns a sheet must have, or the file is refused: the employee's number, and the employees' identity
     * of a new file; a register's required fields.
     *
     * @return list<array{0: string, 1: string|int|null}>
     */
    public static function required(ImportSheet $sheet): array
    {
        if ($sheet === ImportSheet::Employees) {
            return array_map(fn ($k) => [self::FIELD, $k], ['personal_number', 'national_number', 'first_name', 'father_name', 'family_name']);
        }

        $required = [[self::PERSONAL_NUMBER, null]];

        foreach (RecordCatalogue::fields($sheet->recordType()) as $key => $field) {
            if ($field['required'] ?? false) {
                $required[] = [self::FIELD, $key];
            }
        }

        return $required;
    }

    /** How a target reads, for the column matching and the messages. */
    public static function label(ImportSheet $sheet, array $target): string
    {
        [$what, $which] = $target;

        return match ($what) {
            self::FIELD => $sheet === ImportSheet::Employees ? FieldCatalogue::get($which)['label'] : RecordCatalogue::label($sheet->recordType(), $which),
            self::PERSONAL_NUMBER => 'الرقم الذاتي',
            self::ORG_LEVEL => 'المستوى التنظيمي '.self::LEVELS[$which - 1],
            self::WORK_LEVEL => 'جهة العمل الحالية - مستوى تنظيمي '.$which,
            self::REVIEW => 'آخر مراجعة - الدورة '.self::CYCLE_TITLES[$which],
            self::LINK => DocumentCatalogue::FILE_KINDS[$which]['workbook'],
            self::NAME_CHECK => 'اسم الموظف',
            self::TYPED_DAYS => 'عدد الأيام',
            default => '',
        };
    }

    /** The target's key in a row's `values`, for the frontend: `field:first_name`, `org_level:2` … */
    public static function key(array $target): string
    {
        return $target[1] === null ? $target[0] : $target[0].':'.$target[1];
    }

    public static function cycleOf(string $cycle): Cycle
    {
        return Cycle::from($cycle);
    }
}
