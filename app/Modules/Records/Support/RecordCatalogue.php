<?php

namespace App\Modules\Records\Support;

use App\Modules\Records\Enums\RecordType;

/**
 * The columns of each record, as the workbook names them (D-040). The employee's number, name and unit columns
 * are the file's, shown beside the record, not entered again; the year, month, row number and key columns
 * were the workbook's machinery.
 *
 * type: date, text (one line, `max`), longtext, int (`min`, `max`), days (one decimal: a half day), decimal
 * (`scale`, `min`, `max`), money, list (`list`: a managed list's key), debt (a kept debt of the same employee).
 * `required`: on adding. `fixed`: set when added, never changed. A movement's days are computed from its dates.
 */
final class RecordCatalogue
{
    private const NOTES = ['label' => 'ملاحظات', 'type' => 'longtext', 'max' => 2000];

    /** @return array<string, array<string, mixed>> */
    public static function fields(RecordType $type): array
    {
        return match ($type) {
            RecordType::Movements => [
                'occurred_on' => ['label' => 'التاريخ', 'type' => 'date', 'required' => true],
                'movement_type_id' => ['label' => 'نوع الحركة', 'type' => 'list', 'list' => 'movement_type', 'required' => true],
                'description' => ['label' => 'الوصف', 'type' => 'longtext', 'max' => 2000],
                'identified_by' => ['label' => 'المعرف بها', 'type' => 'text', 'max' => 200],
                'entity' => ['label' => 'الجهة', 'type' => 'text', 'max' => 200],
                'course_mark' => ['label' => 'علامات الدورات', 'type' => 'decimal', 'scale' => 2, 'min' => 0, 'max' => 1000],
                'amount' => ['label' => 'المبلغ', 'type' => 'money'],
                'starts_on' => ['label' => 'من تاريخ', 'type' => 'date'],
                'ends_on' => ['label' => 'إلى تاريخ', 'type' => 'date'],
                'status' => ['label' => 'الحالة', 'type' => 'text', 'max' => 100],
                // What the file becomes (D-048): by the movement's type, see MovementEffects.
                'new_org_unit_id' => ['label' => 'الجهة الجديدة', 'type' => 'unit'],
                'new_job_status_id' => ['label' => 'الحالة الوظيفية الجديدة', 'type' => 'list', 'list' => 'job_status'],
                'new_position_level_id' => ['label' => 'المنصب الجديد', 'type' => 'list', 'list' => 'position_level'],
                'new_job_title_id' => ['label' => 'المسمى الوظيفي الجديد', 'type' => 'list', 'list' => 'job_title'],
                'notes' => self::NOTES,
            ],
            RecordType::Attendance => [
                'year' => ['label' => 'السنة', 'type' => 'int', 'min' => 2000, 'max' => 2100, 'required' => true, 'fixed' => true],
                'month' => ['label' => 'الشهر', 'type' => 'int', 'min' => 1, 'max' => 12, 'required' => true, 'fixed' => true],
                'planned_days' => ['label' => 'أيام الدوام المقررة', 'type' => 'int', 'min' => 0, 'max' => 31, 'required' => true],
                // A half day too: the workbook takes any number up to the planned days.
                'attended_days' => ['label' => 'أيام الدوام الفعلية (الحضور)', 'type' => 'days', 'min' => 0, 'max' => 31, 'required' => true],
                'exceptional_skills' => ['label' => 'المهارات الاستثنائية', 'type' => 'longtext', 'max' => 2000],
                'notes' => self::NOTES,
            ],
            RecordType::Conduct => [
                'occurred_on' => ['label' => 'التاريخ', 'type' => 'date', 'required' => true],
                'conduct_type_id' => ['label' => 'نوع السجل', 'type' => 'list', 'list' => 'conduct_type', 'required' => true],
                'subject' => ['label' => 'الموضوع / التفاصيل', 'type' => 'longtext', 'max' => 4000],
                'reported_by' => ['label' => 'مقدم الشكوى / الجهة المبلِّغة', 'type' => 'text', 'max' => 200],
                'decision' => ['label' => 'القرار الرقابي', 'type' => 'longtext', 'max' => 4000],
                'decision_number' => ['label' => 'رقم القرار', 'type' => 'text', 'max' => 100],
                'decision_on' => ['label' => 'تاريخ القرار', 'type' => 'date'],
                'conduct_status_id' => ['label' => 'حالة المعالجة', 'type' => 'list', 'list' => 'conduct_status'],
                'document_link' => ['label' => 'رابط الوثيقة', 'type' => 'text', 'max' => 500],
                'notes' => self::NOTES,
            ],
            RecordType::Debts => [
                'creditor' => ['label' => 'الجهة الدائنة', 'type' => 'text', 'max' => 200],
                'debt_type_id' => ['label' => 'نوع الدين', 'type' => 'list', 'list' => 'debt_type'],
                'reason' => ['label' => 'سبب الدين / التفاصيل', 'type' => 'longtext', 'max' => 4000],
                'incurred_on' => ['label' => 'تاريخ الدين', 'type' => 'date'],
                'amount' => ['label' => 'قيمة الدين', 'type' => 'money', 'required' => true, 'positive' => true],
                // Paid before the debt was entered; later payments are entries of their own (D-045).
                'paid_amount' => ['label' => 'المبلغ المسدد', 'type' => 'money'],
                'monthly_installment' => ['label' => 'القسط الشهري', 'type' => 'money'],
                'due_on' => ['label' => 'تاريخ الاستحقاق', 'type' => 'date'],
                'notes' => self::NOTES,
            ],
            RecordType::Payments => [
                'debt_id' => ['label' => 'الدين', 'type' => 'debt', 'required' => true, 'fixed' => true],
                'paid_on' => ['label' => 'تاريخ الدفعة', 'type' => 'date', 'required' => true],
                'amount' => ['label' => 'مبلغ الدفعة', 'type' => 'money', 'required' => true, 'positive' => true],
                'payment_method_id' => ['label' => 'طريقة السداد', 'type' => 'list', 'list' => 'payment_method', 'required' => true],
                'notes' => ['label' => 'ملاحظة القيد', 'type' => 'longtext', 'max' => 2000],
            ],
        };
    }

    /** @return array<string, mixed> */
    public static function field(RecordType $type, string $key): array
    {
        return self::fields($type)[$key];
    }

    /** @return list<string> */
    public static function keys(RecordType $type): array
    {
        return array_keys(self::fields($type));
    }

    public static function label(RecordType $type, string $key): string
    {
        return self::fields($type)[$key]['label'] ?? $key;
    }
}
