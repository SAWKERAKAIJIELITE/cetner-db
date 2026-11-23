<?php

namespace App\Modules\Settings\Support;

/**
 * The managed lists of «الإعدادات» (D-024): each has its own table, and the workbook's items. An item given as
 * [label, code] is a system item (D-025): the code's logic refers to it, it can be renamed but not archived.
 *
 * «نعم / لا» is not a list: those fields are booleans. «دورة التحديث» is not a list either: the cycles are
 * the system's (Step 3).
 */
final class ListCatalogue
{
    /** key => [table, label, used by, items] */
    public const LISTS = [
        'gender' => ['genders', 'الجنس', 'الجنس', [['ذكر', 'male'], ['أنثى', 'female']]],
        'blood_type' => ['blood_types', 'فصيلة الدم', 'فصيلة الدم', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']],
        'marital_status' => ['marital_statuses', 'الحالة الاجتماعية', 'الحالة الاجتماعية', [['أعزب', 'single'], 'متزوج', 'مطلق', 'أرمل']],
        'education_level' => ['education_levels', 'التحصيل العلمي', 'الدراسة الأولى، الدراسة الثانية، التحصيل العلمي',
            ['دكتوراه', 'ماجستير', 'بكالوريوس', 'دبلوم', 'معهد متوسط', 'ثانوية', 'إعدادية', 'ابتدائية']],
        'current_study' => ['current_studies', 'الدراسة الحالية', 'الدراسة الحالية',
            ['طالب دكتوراه', 'طالب ماجستير', 'طالب بكالوريوس', 'طالب دبلوم', 'طالب معهد', ['لا', 'none']]],
        'study_year' => ['study_years', 'السنة الدراسية', 'السنة الدراسية الحالية',
            ['السنة الأولى', 'السنة الثانية', 'السنة الثالثة', 'السنة الرابعة', 'السنة الخامسة', 'السنة السادسة', 'سنة التخرج']],
        'position_level' => ['position_levels', 'المستوى التنظيمي (المنصب)', 'المستوى التنظيمي (المنصب)',
            ['رئيس فرع', 'معاون رئيس فرع', 'رئيس قسم', 'مدير مكتب', 'موجه', 'عامل', 'غير ذلك']],
        'job_title' => ['job_titles', 'المسمى الوظيفي', 'المسمى الوظيفي (التخصص)', []],
        'employee_status' => ['employee_statuses', 'حالة الموظف', 'حالة الموظف', [['فعال', 'active'], ['غير فعال', 'inactive']]],
        'job_status' => ['job_statuses', 'الحالة الوظيفية', 'الحالة الوظيفية',
            ['متعاقد دوام', 'متعاقد نوبات', 'مثبت عسكري', 'قيد النقل', ['مسرح', 'discharged'], 'فار', 'متقاعد']],
        'org_stage' => ['org_stages', 'المرحلة التنظيمية', 'المرحلة التنظيمية', ['خريج كلية حربية', 'طالب كلية حربية', 'تمهيدي', 'غير منظم']],
        'org_stage_level' => ['org_stage_levels', 'مستوى المرحلة التنظيمية', 'مستوى المرحلة التنظيمية',
            ['المستوى الأول', 'المستوى الثاني', 'المستوى الثالث', 'المستوى الرابع', 'المستوى الخامس']],
        'contract_type' => ['contract_types', 'نوع العقد', 'نوع العقد', ['عقد سنوي', 'عقد مؤقت', 'عقد مفتوح المدة', 'عقد تكليف', 'بدون عقد']],
        'work_schedule' => ['work_schedules', 'نوع الدوام', 'نوع الدوام', ['إداري', 'مغادرات', 'مناوبات', 'مهام', 'إداري وميداني']],
        'health_status' => ['health_statuses', 'الحالة الصحية', 'الحالة الصحية',
            [['سليم', 'healthy'], ['مصاب', 'injured'], ['مرض مزمن', 'chronic_illness']]],
        'ability_level' => ['ability_levels', 'مستوى القدرة (خطابة / تدريس)', 'القدرة على الخطابة، القدرة على التدريس',
            ['ممتاز', 'جيد جداً', 'جيد', 'متوسط', 'ضعيف', 'لا يوجد']],
        'housing_type' => ['housing_types', 'نوع السكن', 'نوع السكن الحالي',
            ['سكن حكومي', 'سكن منظمات', 'سكن مع الاهل', ['ايجار', 'rented'], 'ملك']],
        'vehicle_ownership' => ['vehicle_ownerships', 'ملكية المركبة', 'ملكية السيارة، ملكية الدراجة', ['للدولة', 'خاصة', 'مستعارة']],
        'guardian_relation' => ['guardian_relations', 'صلة القرابة بالوصي', 'صلة القرابة بالوصي',
            ['الأب', 'الأم', 'الأخ', 'الأخت', 'الزوجة', 'الابن', 'العم', 'الخال', 'أخرى']],
        'external_entity_type' => ['external_entity_types', 'نوع الجهة الأخرى', 'نوع الجهة الأخرى',
            ['جهة حكومية', 'جهة عسكرية / أمنية', 'منظمة / جمعية', 'قطاع خاص', 'مؤسسة تعليمية', 'مسجد / جهة دينية', 'عمل حر']],
        'external_link_type' => ['external_link_types', 'نوع الارتباط', 'نوع الارتباط',
            ['وظيفة دائمة', 'عقد مؤقت', 'دوام جزئي', 'عمل حر', 'تطوع', 'استشاري / مستقل']],
        'external_schedule' => ['external_schedules', 'نظام الدوام بالجهة الأخرى', 'نظام الدوام بالجهة الأخرى',
            ['دوام كامل', 'دوام جزئي', 'نوبات', 'عن بعد', 'غير منتظم']],
        'movement_type' => ['movement_types', 'نوع الحركة', 'سجل الحركات',
            ['إجازة داخلية', 'إجازة خارجية', 'إجازة مرضية', 'إجازة طارئة', ['مكافأة', 'reward'], ['خصم', 'deduction'],
                'نقل داخلي', 'تغيير حالة', 'ترقية', 'دورة تدريبية', 'تكليف', 'تعيين', 'تثبيت', 'استقالة', 'تسريح']],
        'conduct_type' => ['conduct_types', 'نوع السجل السلوكي', 'سجل السلوك الوظيفي',
            ['شكوى', 'إنذار', 'تنبيه', 'عقوبة', 'مخالفة', 'شكر وتقدير', 'تنويه']],
        'conduct_status' => ['conduct_statuses', 'حالة المعالجة', 'سجل السلوك الوظيفي', ['قيد التحقيق', 'صدر القرار', 'منفذ', 'محفوظة']],
        'debt_type' => ['debt_types', 'نوع الدين', 'سجل الديون', ['سلفة على الراتب', 'قرض شخصي', 'دين لجهة رسمية', 'دين تجاري', 'أخرى']],
        'work_nature' => ['work_natures', 'طبيعة العمل', 'طبيعة العمل', ['توجيهي', 'إداري']],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LISTS);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::LISTS);
    }

    public static function table(string $key): string
    {
        return self::LISTS[$key][0];
    }

    public static function keyOfTable(string $table): ?string
    {
        foreach (self::LISTS as $key => $list) {
            if ($list[0] === $table) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, string> code => label, of one list */
    public static function systemItems(string $key): array
    {
        $codes = [];

        foreach (self::LISTS[$key][3] ?? [] as $item) {
            if (is_array($item)) {
                $codes[$item[1]] = $item[0];
            }
        }

        return $codes;
    }
}
