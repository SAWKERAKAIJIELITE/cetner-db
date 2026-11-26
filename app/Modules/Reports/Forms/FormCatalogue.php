<?php

namespace App\Modules\Reports\Forms;

/**
 * The workbook's three printed sheets for one employee (D-050), section by section and row by row as it lays them
 * out. A row is a field of the file (FieldCatalogue) or one of the computed rows EmployeeForm knows («العمر»,
 * «سنوات الخدمة», the six levels of the unit, the attachments, the last reviews …). «تقرير الحالة الوظيفية» adds
 * the chosen year's figures and the latest records.
 */
final class FormCatalogue
{
    public const BASIC = 'basic';

    public const DETAILED = 'detailed';

    public const STATUS = 'status';

    /** @var array<string, array{title: string, sections: list<array{key: string, title: string, rows: list<string>}>}> */
    public const FORMS = [
        self::BASIC => [
            'title' => 'استمارة المعلومات الأساسية',
            'sections' => [
                ['key' => 'id', 'title' => 'التعريف', 'rows' => ['internal_code', 'personal_number', 'full_name', 'first_name', 'father_name', 'family_name', 'mother_name', 'nickname']],
                ['key' => 'personal', 'title' => 'البيانات الشخصية', 'rows' => ['gender_id', 'nationality', 'birth_date', 'age', 'birth_place', 'national_number', 'registry_number', 'registry_place', 'blood_type_id', 'distinguishing_marks', 'height_cm', 'weight_kg']],
                ['key' => 'family', 'title' => 'الحالة الاجتماعية', 'rows' => ['marital_status_id', 'wives_count', 'wife_nationality', 'children_count']],
                ['key' => 'contact', 'title' => 'التواصل', 'rows' => ['phone', 'telegram', 'email', 'emergency_contact', 'emergency_phone']],
                ['key' => 'home', 'title' => 'الإقامة الحالية والسكن', 'rows' => ['residence_governorate', 'residence_city', 'residence_district', 'residence_address', 'housing_type_id', 'rent_amount']],
                ['key' => 'guardian', 'title' => 'معلومات الوصي', 'rows' => ['guardian_name', 'guardian_relation_id', 'guardian_national_number', 'guardian_phone', 'guardian_address']],
                ['key' => 'pilgrimage', 'title' => 'العمرة والحج', 'rows' => ['did_umrah', 'umrah_count', 'last_umrah_on', 'did_hajj', 'hajj_count', 'last_hajj_on']],
            ],
        ],
        self::DETAILED => [
            'title' => 'استمارة المعلومات التفصيلية',
            'sections' => [
                ['key' => 'quals', 'title' => 'المؤهلات الدراسية', 'rows' => ['first_study_level_id', 'first_study_major', 'first_study_graduation_year', 'first_study_grade', 'first_study_thesis', 'second_study_level_id', 'second_study_major', 'second_study_graduation_year', 'second_study_grade', 'second_study_thesis', 'education_graduation_year', 'education_grade', 'education_thesis']],
                ['key' => 'study', 'title' => 'التحصيل العلمي والدراسة الحالية', 'rows' => ['education_level_id', 'education_major', 'current_study_id', 'current_study_major', 'study_year_id', 'university']],
                ['key' => 'org', 'title' => 'جهة العمل والوضع الوظيفي', 'rows' => ['org_level_1', 'org_level_2', 'org_level_3', 'org_level_4', 'org_level_5', 'org_level_6', 'org_unit_name', 'current_work_unit_id', 'position_level_id', 'job_title_id', 'work_area_address', 'military_rank', 'work_nature_id', 'employee_status_id', 'job_status_id', 'org_stage_id', 'org_stage_level_id', 'work_schedule_id']],
                ['key' => 'contract', 'title' => 'العقد والتواريخ الوظيفية', 'rows' => ['contract_type_id', 'contract_signed_on', 'contract_starts_on', 'contract_ends_on', 'contract_days_left', 'assignment_date', 'appointment_date', 'confirmation_date', 'discharge_date', 'service_years']],
                ['key' => 'salary', 'title' => 'الراتب والديون', 'rows' => ['sham_cash_account', 'base_salary', 'salary_eligible', 'has_debts', 'total_remaining']],
                ['key' => 'external', 'title' => 'الارتباط مع الجهات الأخرى', 'rows' => ['has_external_link', 'external_entity_type_id', 'external_entity_name', 'external_link_type_id', 'external_schedule_id', 'external_title', 'external_salary', 'external_start_date']],
                ['key' => 'health', 'title' => 'الحالة الصحية', 'rows' => ['health_status_id', 'injury_type', 'illness_type']],
                ['key' => 'quran', 'title' => 'القرآن والخطابة والتدريس', 'rows' => ['quran_licensed', 'quran_readings', 'quran_parts', 'preaching_level_id', 'preaching_years', 'preaches_now', 'preaching_mosque', 'teaching_level_id', 'teaching_years', 'teaches_now', 'teaching_place']],
                ['key' => 'vehicles', 'title' => 'المركبات', 'rows' => ['owns_car', 'car_ownership_id', 'car_number', 'car_fuel', 'owns_motorbike', 'motorbike_ownership_id', 'motorbike_number']],
                ['key' => 'arms', 'title' => 'التجهيز والتسليح', 'rows' => ['suit_size', 'shoe_size', 'cap_size', 'has_weapon', 'weapon_type', 'weapon_number', 'weapon2_type', 'weapon2_number', 'logistics_issued']],
                ['key' => 'documents', 'title' => 'الوثائق والمرفقات', 'rows' => ['documents', 'documents_attached']],
                ['key' => 'quality', 'title' => 'اكتمال البيانات ومتابعة التحديث', 'rows' => ['completeness', 'missing_fields', 'last_review_monthly', 'last_review_quarterly', 'last_review_semi_annual', 'last_review_annual']],
                ['key' => 'notes', 'title' => 'ملاحظات', 'rows' => ['notes']],
            ],
        ],
        self::STATUS => [
            'title' => 'تقرير الحالة الوظيفية',
            'sections' => [
                ['key' => 'org', 'title' => 'جهة العمل والوضع الوظيفي', 'rows' => ['org_unit_name', 'position_level_id', 'job_title_id', 'military_rank', 'employee_status_id', 'job_status_id', 'org_stage_id', 'org_stage_level_id', 'work_schedule_id', 'work_nature_id', 'work_area_address', 'current_work_unit_id']],
                ['key' => 'contract', 'title' => 'العقد والتواريخ الوظيفية', 'rows' => ['contract_type_id', 'contract_signed_on', 'contract_starts_on', 'contract_ends_on', 'contract_days_left', 'service_years', 'assignment_date', 'appointment_date', 'confirmation_date', 'discharge_date']],
                ['key' => 'year', 'title' => 'مؤشرات السنة المختارة (من السجلات)', 'rows' => ['year.leaves', 'year.leave_days', 'year.leave_balance', 'year.courses', 'year.rewards', 'year.deductions', 'year.planned_days', 'year.attended_days', 'year.absent_days', 'year.absence_rate', 'year.complaints', 'year.warnings', 'year.punishments', 'year.thanks']],
                ['key' => 'finance', 'title' => 'المالية والديون والارتباط الخارجي وجودة البيانات', 'rows' => ['base_salary', 'salary_eligible', 'has_debts', 'total_remaining', 'has_external_link', 'external_entity_name', 'completeness', 'missing_fields', 'debts.open', 'debts.overdue']],
            ],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FORMS);
    }
}
