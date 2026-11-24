<?php

namespace App\Modules\Employees\Support;

/**
 * The employee's input fields, from «دليل الحقول» (D-032). Generated once from the workbook; edited by hand since.
 * Each field: workbook column, label, section, update cycle, type and its limits, whether it counts for
 * completeness (the workbook's ✔, editable later in field_definitions) and whether it identifies the person:
 * the six identity fields, locked once the administrator approves them (D-034). The column of the `employees` table is the key. The workbook's formulas, its 13 document links
 * (Step 5) and its four «آخر مراجعة» dates (employee_reviews) are not input fields.
 *
 * Types: text, longtext, name (no digits), code, digits (keeps leading zeros), phone, email, date, year, rate
 * (0 to 100), int, decimal, money (≥ 0), bool (نعم / لا, null when unknown), list (a managed list's item),
 * org_unit (a unit of the organisational structure).
 */
final class FieldCatalogue
{
    public const SECTIONS = [
        'id' => 'التعريف',
        'personal' => 'البيانات الشخصية',
        'org' => 'جهة العمل والوضع الوظيفي',
        'contract' => 'العقد والتواريخ الوظيفية',
        'quals' => 'المؤهلات الدراسية',
        'salary' => 'الراتب والديون',
        'family' => 'الحالة الاجتماعية',
        'home' => 'الإقامة الحالية والسكن',
        'quran' => 'القرآن والخطابة والتدريس',
        'notes' => 'ملاحظات',
        'contact' => 'التواصل',
        'health' => 'الحالة الصحية',
        'external' => 'الارتباط مع الجهات الأخرى',
        'arms' => 'التجهيز والتسليح',
        'study' => 'التحصيل العلمي والدراسة الحالية',
        'vehicles' => 'المركبات',
        'guardian' => 'معلومات الوصي',
        'pilgrimage' => 'العمرة والحج',
    ];

    public const FIELDS = [
        'internal_code' => ['column' => 'B', 'label' => 'الرمز الداخلي', 'section' => 'id', 'cycle' => 'once', 'type' => 'text', 'max' => 50, 'counts' => false, 'identity' => false],
        'personal_number' => ['column' => 'C', 'label' => 'الرقم الذاتي', 'section' => 'id', 'cycle' => 'once', 'type' => 'code', 'max' => 50, 'unique' => true, 'counts' => true, 'identity' => true],
        'first_name' => ['column' => 'E', 'label' => 'الاسم', 'section' => 'id', 'cycle' => 'once', 'type' => 'name', 'counts' => true, 'identity' => true],
        'father_name' => ['column' => 'F', 'label' => 'اسم الأب', 'section' => 'id', 'cycle' => 'once', 'type' => 'name', 'counts' => true, 'identity' => true],
        'family_name' => ['column' => 'G', 'label' => 'العائلة', 'section' => 'id', 'cycle' => 'once', 'type' => 'name', 'counts' => true, 'identity' => true],
        'mother_name' => ['column' => 'H', 'label' => 'اسم الأم', 'section' => 'id', 'cycle' => 'once', 'type' => 'name', 'counts' => true, 'identity' => true],
        'nickname' => ['column' => 'I', 'label' => 'اللقب', 'section' => 'id', 'cycle' => 'once', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'gender_id' => ['column' => 'J', 'label' => 'الجنس', 'section' => 'personal', 'cycle' => 'once', 'type' => 'list', 'list' => 'gender', 'counts' => true, 'identity' => false],
        'nationality' => ['column' => 'K', 'label' => 'الجنسية', 'section' => 'personal', 'cycle' => 'once', 'type' => 'text', 'max' => 100, 'counts' => true, 'identity' => false],
        'birth_date' => ['column' => 'L', 'label' => 'تاريخ الميلاد', 'section' => 'personal', 'cycle' => 'once', 'type' => 'date', 'past' => true, 'counts' => true, 'identity' => false],
        'birth_place' => ['column' => 'N', 'label' => 'مكان الميلاد', 'section' => 'personal', 'cycle' => 'once', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'national_number' => ['column' => 'O', 'label' => 'الرقم الوطني', 'section' => 'personal', 'cycle' => 'once', 'type' => 'digits', 'min' => 5, 'max' => 20, 'unique' => true, 'counts' => true, 'identity' => true],
        'registry_number' => ['column' => 'P', 'label' => 'رقم القيد', 'section' => 'personal', 'cycle' => 'once', 'type' => 'text', 'max' => 50, 'counts' => true, 'identity' => false],
        'registry_place' => ['column' => 'Q', 'label' => 'مكان القيد', 'section' => 'personal', 'cycle' => 'once', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'blood_type_id' => ['column' => 'R', 'label' => 'فصيلة الدم', 'section' => 'personal', 'cycle' => 'once', 'type' => 'list', 'list' => 'blood_type', 'counts' => true, 'identity' => false],
        'distinguishing_marks' => ['column' => 'S', 'label' => 'العلامات الفارقة', 'section' => 'personal', 'cycle' => 'once', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'height_cm' => ['column' => 'T', 'label' => 'الطول (سم)', 'section' => 'personal', 'cycle' => 'once', 'type' => 'decimal', 'min' => 0, 'max' => 300, 'precision' => 5, 'scale' => 1, 'counts' => true, 'identity' => false],
        'confirmation_date' => ['column' => 'V', 'label' => 'تاريخ التثبيت', 'section' => 'contract', 'cycle' => 'once', 'type' => 'date', 'counts' => false, 'identity' => false],
        'discharge_date' => ['column' => 'W', 'label' => 'تاريخ التسريح', 'section' => 'contract', 'cycle' => 'once', 'type' => 'date', 'counts' => false, 'identity' => false],
        'first_study_level_id' => ['column' => 'Y', 'label' => 'الدراسة الأولى', 'section' => 'quals', 'cycle' => 'once', 'type' => 'list', 'list' => 'education_level', 'counts' => true, 'identity' => false],
        'first_study_major' => ['column' => 'Z', 'label' => 'اختصاص الدراسة الأولى', 'section' => 'quals', 'cycle' => 'once', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'first_study_graduation_year' => ['column' => 'AA', 'label' => 'سنة تخرج الدراسة الأولى', 'section' => 'quals', 'cycle' => 'once', 'type' => 'year', 'counts' => true, 'identity' => false],
        'first_study_grade' => ['column' => 'AB', 'label' => 'معدل تخرج الدراسة الأولى', 'section' => 'quals', 'cycle' => 'once', 'type' => 'rate', 'counts' => false, 'identity' => false],
        'first_study_thesis' => ['column' => 'AC', 'label' => 'بحث تخرج الدراسة الأولى', 'section' => 'quals', 'cycle' => 'once', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'second_study_level_id' => ['column' => 'AD', 'label' => 'الدراسة الثانية', 'section' => 'quals', 'cycle' => 'once', 'type' => 'list', 'list' => 'education_level', 'counts' => false, 'identity' => false],
        'second_study_major' => ['column' => 'AE', 'label' => 'اختصاص الدراسة الثانية', 'section' => 'quals', 'cycle' => 'once', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'second_study_graduation_year' => ['column' => 'AF', 'label' => 'سنة تخرج الدراسة الثانية', 'section' => 'quals', 'cycle' => 'once', 'type' => 'year', 'counts' => false, 'identity' => false],
        'second_study_grade' => ['column' => 'AG', 'label' => 'معدل تخرج الدراسة الثانية', 'section' => 'quals', 'cycle' => 'once', 'type' => 'rate', 'counts' => false, 'identity' => false],
        'second_study_thesis' => ['column' => 'AH', 'label' => 'بحث تخرج الدراسة الثانية', 'section' => 'quals', 'cycle' => 'once', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'education_graduation_year' => ['column' => 'AI', 'label' => 'سنة التخرج (التحصيل العلمي)', 'section' => 'quals', 'cycle' => 'once', 'type' => 'year', 'counts' => false, 'identity' => false],
        'education_grade' => ['column' => 'AJ', 'label' => 'معدل التخرج (التحصيل العلمي)', 'section' => 'quals', 'cycle' => 'once', 'type' => 'rate', 'counts' => false, 'identity' => false],
        'education_thesis' => ['column' => 'AK', 'label' => 'عنوان بحث التخرج (التحصيل العلمي)', 'section' => 'quals', 'cycle' => 'once', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'org_unit_id' => ['column' => 'U+AP:AT', 'label' => 'جهة العمل (المستويات التنظيمية)', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'org_unit', 'counts' => true, 'identity' => false],
        'current_work_unit_id' => ['column' => 'AV:AW', 'label' => 'جهة العمل الحالية (إن اختلفت)', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'org_unit', 'counts' => false, 'identity' => false],
        'position_level_id' => ['column' => 'AX', 'label' => 'المستوى التنظيمي (المنصب)', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'position_level', 'counts' => true, 'identity' => false],
        'job_title_id' => ['column' => 'AY', 'label' => 'المسمى الوظيفي (التخصص)', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'job_title', 'counts' => true, 'identity' => false],
        'work_area_address' => ['column' => 'AZ', 'label' => 'عنوان منطقة العمل', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'text', 'max' => 500, 'counts' => true, 'identity' => false],
        'work_nature_id' => ['column' => 'BA', 'label' => 'طبيعة العمل', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'work_nature', 'counts' => true, 'identity' => false],
        'employee_status_id' => ['column' => 'BB', 'label' => 'حالة الموظف', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'employee_status', 'counts' => true, 'identity' => false],
        'job_status_id' => ['column' => 'BC', 'label' => 'الحالة الوظيفية', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'job_status', 'counts' => true, 'identity' => false],
        'work_schedule_id' => ['column' => 'BD', 'label' => 'نوع الدوام', 'section' => 'org', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'work_schedule', 'counts' => true, 'identity' => false],
        'base_salary' => ['column' => 'BE', 'label' => 'الراتب الأساسي', 'section' => 'salary', 'cycle' => 'monthly', 'type' => 'money', 'counts' => true, 'identity' => false],
        'salary_eligible' => ['column' => 'BF', 'label' => 'مستحق الراتب', 'section' => 'salary', 'cycle' => 'monthly', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'marital_status_id' => ['column' => 'BI', 'label' => 'الحالة الاجتماعية', 'section' => 'family', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'marital_status', 'counts' => true, 'identity' => false],
        'wives_count' => ['column' => 'BJ', 'label' => 'عدد الزوجات', 'section' => 'family', 'cycle' => 'monthly', 'type' => 'int', 'min' => 0, 'max' => 10, 'counts' => false, 'identity' => false],
        'wife_nationality' => ['column' => 'BK', 'label' => 'جنسية الزوجة', 'section' => 'family', 'cycle' => 'monthly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'children_count' => ['column' => 'BL', 'label' => 'عدد الأولاد', 'section' => 'family', 'cycle' => 'monthly', 'type' => 'int', 'min' => 0, 'max' => 50, 'counts' => true, 'identity' => false],
        'residence_governorate' => ['column' => 'BM', 'label' => 'محافظة الإقامة الحالية', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'text', 'max' => 100, 'counts' => true, 'identity' => false],
        'residence_city' => ['column' => 'BN', 'label' => 'مدينة / بلدة الإقامة الحالية', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'text', 'max' => 100, 'counts' => true, 'identity' => false],
        'residence_district' => ['column' => 'BO', 'label' => 'حي الإقامة الحالية', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'residence_address' => ['column' => 'BP', 'label' => 'العنوان التفصيلي للإقامة الحالية', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'text', 'max' => 500, 'counts' => true, 'identity' => false],
        'housing_type_id' => ['column' => 'BQ', 'label' => 'نوع السكن الحالي', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'list', 'list' => 'housing_type', 'counts' => true, 'identity' => false],
        'rent_amount' => ['column' => 'BR', 'label' => 'قيمة الإيجار', 'section' => 'home', 'cycle' => 'monthly', 'type' => 'money', 'counts' => false, 'identity' => false],
        'preaches_now' => ['column' => 'BS', 'label' => 'هل يخطب حالياً', 'section' => 'quran', 'cycle' => 'monthly', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'preaching_mosque' => ['column' => 'BT', 'label' => 'المسجد الذي يخطب فيه', 'section' => 'quran', 'cycle' => 'monthly', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'teaches_now' => ['column' => 'BU', 'label' => 'هل يدرّس حالياً', 'section' => 'quran', 'cycle' => 'monthly', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'teaching_place' => ['column' => 'BV', 'label' => 'مكان التدريس الحالي', 'section' => 'quran', 'cycle' => 'monthly', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'notes' => ['column' => 'BW', 'label' => 'ملاحظات', 'section' => 'notes', 'cycle' => 'monthly', 'type' => 'longtext', 'max' => 2000, 'counts' => false, 'identity' => false],
        'phone' => ['column' => 'BX', 'label' => 'رقم الهاتف', 'section' => 'contact', 'cycle' => 'quarterly', 'type' => 'phone', 'counts' => true, 'identity' => false],
        'telegram' => ['column' => 'BY', 'label' => 'معرف التلجرام', 'section' => 'contact', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'email' => ['column' => 'BZ', 'label' => 'البريد الإلكتروني', 'section' => 'contact', 'cycle' => 'quarterly', 'type' => 'email', 'counts' => false, 'identity' => false],
        'emergency_contact' => ['column' => 'CA', 'label' => 'جهة اتصال الطوارئ', 'section' => 'contact', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'emergency_phone' => ['column' => 'CB', 'label' => 'هاتف الطوارئ', 'section' => 'contact', 'cycle' => 'quarterly', 'type' => 'phone', 'counts' => true, 'identity' => false],
        'org_stage_id' => ['column' => 'CC', 'label' => 'المرحلة التنظيمية', 'section' => 'org', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'org_stage', 'counts' => true, 'identity' => false],
        'org_stage_level_id' => ['column' => 'CD', 'label' => 'مستوى المرحلة التنظيمية', 'section' => 'org', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'org_stage_level', 'counts' => true, 'identity' => false],
        'assignment_date' => ['column' => 'CE', 'label' => 'تاريخ التكليف', 'section' => 'contract', 'cycle' => 'quarterly', 'type' => 'date', 'counts' => false, 'identity' => false],
        'appointment_date' => ['column' => 'CF', 'label' => 'تاريخ التعيين', 'section' => 'contract', 'cycle' => 'quarterly', 'type' => 'date', 'counts' => false, 'identity' => false],
        'health_status_id' => ['column' => 'CG', 'label' => 'الحالة الصحية', 'section' => 'health', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'health_status', 'counts' => true, 'identity' => false],
        'injury_type' => ['column' => 'CH', 'label' => 'نوع الإصابة', 'section' => 'health', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 255, 'counts' => false, 'identity' => false],
        'illness_type' => ['column' => 'CI', 'label' => 'نوع المرض', 'section' => 'health', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 255, 'counts' => false, 'identity' => false],
        'has_external_link' => ['column' => 'CJ', 'label' => 'الارتباط الخارجي', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'external_entity_type_id' => ['column' => 'CK', 'label' => 'نوع الجهة الأخرى', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'external_entity_type', 'counts' => false, 'identity' => false],
        'external_entity_name' => ['column' => 'CL', 'label' => 'اسم الجهة الأخرى', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'external_link_type_id' => ['column' => 'CM', 'label' => 'نوع الارتباط', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'external_link_type', 'counts' => false, 'identity' => false],
        'external_schedule_id' => ['column' => 'CN', 'label' => 'نظام الدوام بالجهة الأخرى', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'list', 'list' => 'external_schedule', 'counts' => false, 'identity' => false],
        'external_title' => ['column' => 'CO', 'label' => 'المسمى بالجهة الأخرى', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'external_salary' => ['column' => 'CP', 'label' => 'الراتب من الجهة الأخرى', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'money', 'counts' => false, 'identity' => false],
        'external_start_date' => ['column' => 'CQ', 'label' => 'تاريخ بدء الارتباط', 'section' => 'external', 'cycle' => 'quarterly', 'type' => 'date', 'counts' => false, 'identity' => false],
        'has_weapon' => ['column' => 'CR', 'label' => 'لديه سلاح', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'weapon_type' => ['column' => 'CS', 'label' => 'نوع السلاح', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'weapon_number' => ['column' => 'CT', 'label' => 'رقم السلاح', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'weapon2_type' => ['column' => 'CU', 'label' => 'نوع السلاح 2', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'weapon2_number' => ['column' => 'CV', 'label' => 'رقم السلاح 2', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'logistics_issued' => ['column' => 'CW', 'label' => 'اللوجستيات المسلمة', 'section' => 'arms', 'cycle' => 'quarterly', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'contract_type_id' => ['column' => 'CX', 'label' => 'نوع العقد', 'section' => 'contract', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'contract_type', 'counts' => true, 'identity' => false],
        'contract_signed_on' => ['column' => 'CY', 'label' => 'تاريخ إمضاء العقد', 'section' => 'contract', 'cycle' => 'semi_annual', 'type' => 'date', 'counts' => true, 'identity' => false],
        'contract_starts_on' => ['column' => 'CZ', 'label' => 'تاريخ بداية العقد', 'section' => 'contract', 'cycle' => 'semi_annual', 'type' => 'date', 'counts' => true, 'identity' => false],
        'contract_ends_on' => ['column' => 'DA', 'label' => 'تاريخ انتهاء العقد', 'section' => 'contract', 'cycle' => 'semi_annual', 'type' => 'date', 'counts' => true, 'identity' => false],
        'sham_cash_account' => ['column' => 'DC', 'label' => 'رقم حساب الشام كاش', 'section' => 'salary', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 50, 'counts' => true, 'identity' => false],
        'education_level_id' => ['column' => 'DD', 'label' => 'التحصيل العلمي', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'education_level', 'counts' => true, 'identity' => false],
        'education_major' => ['column' => 'DE', 'label' => 'التخصص', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'current_study_id' => ['column' => 'DF', 'label' => 'الدراسة الحالية', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'current_study', 'counts' => true, 'identity' => false],
        'current_study_major' => ['column' => 'DG', 'label' => 'اختصاص الدراسة الحالية', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'study_year_id' => ['column' => 'DH', 'label' => 'السنة الدراسية الحالية', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'study_year', 'counts' => false, 'identity' => false],
        'university' => ['column' => 'DI', 'label' => 'الجامعة / المعهد', 'section' => 'study', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'quran_licensed' => ['column' => 'DJ', 'label' => 'مجاز في القرآن', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'quran_readings' => ['column' => 'DK', 'label' => 'نوع القراءات', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 150, 'counts' => false, 'identity' => false],
        'quran_parts' => ['column' => 'DL', 'label' => 'عدد الأجزاء المحفوظة', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'int', 'min' => 0, 'max' => 30, 'counts' => true, 'identity' => false],
        'preaching_level_id' => ['column' => 'DM', 'label' => 'القدرة على الخطابة (المستوى)', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'ability_level', 'counts' => true, 'identity' => false],
        'preaching_years' => ['column' => 'DN', 'label' => 'سنوات الخبرة في الخطابة', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'int', 'min' => 0, 'max' => 80, 'counts' => false, 'identity' => false],
        'teaching_level_id' => ['column' => 'DO', 'label' => 'القدرة على التدريس (المستوى)', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'ability_level', 'counts' => true, 'identity' => false],
        'teaching_years' => ['column' => 'DP', 'label' => 'سنوات الخبرة في التدريس', 'section' => 'quran', 'cycle' => 'semi_annual', 'type' => 'int', 'min' => 0, 'max' => 80, 'counts' => false, 'identity' => false],
        'owns_car' => ['column' => 'DQ', 'label' => 'يمتلك سيارة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'car_ownership_id' => ['column' => 'DR', 'label' => 'ملكية السيارة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'vehicle_ownership', 'counts' => false, 'identity' => false],
        'car_number' => ['column' => 'DS', 'label' => 'رقم السيارة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 50, 'counts' => false, 'identity' => false],
        'car_fuel' => ['column' => 'DT', 'label' => 'وقود السيارة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 50, 'counts' => false, 'identity' => false],
        'owns_motorbike' => ['column' => 'DU', 'label' => 'يمتلك دراجة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'motorbike_ownership_id' => ['column' => 'DV', 'label' => 'ملكية الدراجة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'list', 'list' => 'vehicle_ownership', 'counts' => false, 'identity' => false],
        'motorbike_number' => ['column' => 'DW', 'label' => 'رقم الدراجة', 'section' => 'vehicles', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 50, 'counts' => false, 'identity' => false],
        'suit_size' => ['column' => 'DX', 'label' => 'مقاس البدلة', 'section' => 'arms', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 20, 'counts' => true, 'identity' => false],
        'shoe_size' => ['column' => 'DY', 'label' => 'مقاس الحذاء', 'section' => 'arms', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 20, 'counts' => true, 'identity' => false],
        'cap_size' => ['column' => 'DZ', 'label' => 'مقاس الطاقية', 'section' => 'arms', 'cycle' => 'semi_annual', 'type' => 'text', 'max' => 20, 'counts' => true, 'identity' => false],
        'weight_kg' => ['column' => 'EA', 'label' => 'الوزن (كغ)', 'section' => 'personal', 'cycle' => 'semi_annual', 'type' => 'decimal', 'min' => 0, 'max' => 400, 'precision' => 5, 'scale' => 1, 'counts' => true, 'identity' => false],
        'military_rank' => ['column' => 'EC', 'label' => 'الرتبة العسكرية', 'section' => 'org', 'cycle' => 'annual', 'type' => 'text', 'max' => 100, 'counts' => false, 'identity' => false],
        'guardian_name' => ['column' => 'ED', 'label' => 'اسم الوصي', 'section' => 'guardian', 'cycle' => 'annual', 'type' => 'text', 'max' => 150, 'counts' => true, 'identity' => false],
        'guardian_relation_id' => ['column' => 'EE', 'label' => 'صلة القرابة بالوصي', 'section' => 'guardian', 'cycle' => 'annual', 'type' => 'list', 'list' => 'guardian_relation', 'counts' => true, 'identity' => false],
        'guardian_national_number' => ['column' => 'EF', 'label' => 'الرقم الوطني للوصي', 'section' => 'guardian', 'cycle' => 'annual', 'type' => 'digits', 'min' => 5, 'max' => 20, 'counts' => false, 'identity' => false],
        'guardian_phone' => ['column' => 'EG', 'label' => 'هاتف الوصي', 'section' => 'guardian', 'cycle' => 'annual', 'type' => 'phone', 'counts' => true, 'identity' => false],
        'guardian_address' => ['column' => 'EH', 'label' => 'عنوان الوصي', 'section' => 'guardian', 'cycle' => 'annual', 'type' => 'text', 'max' => 500, 'counts' => false, 'identity' => false],
        'did_umrah' => ['column' => 'EQ', 'label' => 'هل اعتمر', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'umrah_count' => ['column' => 'ER', 'label' => 'عدد مرات العمرة', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'int', 'min' => 0, 'max' => 100, 'counts' => false, 'identity' => false],
        'last_umrah_on' => ['column' => 'ES', 'label' => 'تاريخ آخر عمرة', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'date', 'past' => true, 'counts' => false, 'identity' => false],
        'did_hajj' => ['column' => 'ET', 'label' => 'هل أدى فريضة الحج', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'bool', 'counts' => true, 'identity' => false],
        'hajj_count' => ['column' => 'EU', 'label' => 'عدد مرات الحج', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'int', 'min' => 0, 'max' => 100, 'counts' => false, 'identity' => false],
        'last_hajj_on' => ['column' => 'EV', 'label' => 'تاريخ آخر حج', 'section' => 'pilgrimage', 'cycle' => 'annual', 'type' => 'date', 'past' => true, 'counts' => false, 'identity' => false],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::FIELDS);
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::FIELDS[$key];
    }

    /** @return list<string> the six fields that identify the person and are locked (D-034) */
    public static function identityKeys(): array
    {
        return array_keys(array_filter(self::FIELDS, fn (array $f) => $f['identity']));
    }
}
