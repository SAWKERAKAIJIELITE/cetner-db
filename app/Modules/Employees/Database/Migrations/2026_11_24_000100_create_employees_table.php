<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The employee's file (D-032): one row per person with the 125 input fields of «دليل الحقول», holding the
 * approved values only; changes waiting for review are in employee_change_sets (D-033). Every list field is a
 * foreign key to its own list (D-024); the six organisational columns are one unit (D-026).
 * Written out from FieldCatalogue: a migration keeps doing what it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // pending: added, waiting for its first approval (its values are in the change set); active; rejected;
            // withdrawn (its author took it back).
            $table->string('file_status', 20)->default('pending');
            // Derived on every approved change (D-035): the name's search key, completeness, missing fields by cycle.
            $table->string('search_name', 500)->nullable();
            $table->decimal('completeness', 5, 2)->default(0);
            $table->jsonb('missing_fields')->default('{}');

            // B: الرمز الداخلي
            $table->string('internal_code', 50)->nullable();
            // C: الرقم الذاتي
            $table->string('personal_number', 50)->nullable();
            // E: الاسم
            $table->string('first_name', 100)->nullable();
            // F: اسم الأب
            $table->string('father_name', 100)->nullable();
            // G: العائلة
            $table->string('family_name', 100)->nullable();
            // H: اسم الأم
            $table->string('mother_name', 100)->nullable();
            // I: اللقب
            $table->string('nickname', 100)->nullable();
            // J: الجنس
            $table->foreignId('gender_id')->nullable()->constrained('genders')->restrictOnDelete();
            // K: الجنسية
            $table->string('nationality', 100)->nullable();
            // L: تاريخ الميلاد
            $table->date('birth_date')->nullable();
            // N: مكان الميلاد
            $table->string('birth_place', 150)->nullable();
            // O: الرقم الوطني
            $table->string('national_number', 20)->nullable();
            // P: رقم القيد
            $table->string('registry_number', 50)->nullable();
            // Q: مكان القيد
            $table->string('registry_place', 150)->nullable();
            // R: فصيلة الدم
            $table->foreignId('blood_type_id')->nullable()->constrained('blood_types')->restrictOnDelete();
            // S: العلامات الفارقة
            $table->string('distinguishing_marks', 500)->nullable();
            // T: الطول (سم)
            $table->decimal('height_cm', 5, 1)->nullable();
            // V: تاريخ التثبيت
            $table->date('confirmation_date')->nullable();
            // W: تاريخ التسريح
            $table->date('discharge_date')->nullable();
            // Y: الدراسة الأولى
            $table->foreignId('first_study_level_id')->nullable()->constrained('education_levels')->restrictOnDelete();
            // Z: اختصاص الدراسة الأولى
            $table->string('first_study_major', 150)->nullable();
            // AA: سنة تخرج الدراسة الأولى
            $table->smallInteger('first_study_graduation_year')->nullable();
            // AB: معدل تخرج الدراسة الأولى
            $table->decimal('first_study_grade', 5, 2)->nullable();
            // AC: بحث تخرج الدراسة الأولى
            $table->string('first_study_thesis', 500)->nullable();
            // AD: الدراسة الثانية
            $table->foreignId('second_study_level_id')->nullable()->constrained('education_levels')->restrictOnDelete();
            // AE: اختصاص الدراسة الثانية
            $table->string('second_study_major', 150)->nullable();
            // AF: سنة تخرج الدراسة الثانية
            $table->smallInteger('second_study_graduation_year')->nullable();
            // AG: معدل تخرج الدراسة الثانية
            $table->decimal('second_study_grade', 5, 2)->nullable();
            // AH: بحث تخرج الدراسة الثانية
            $table->string('second_study_thesis', 500)->nullable();
            // AI: سنة التخرج (التحصيل العلمي)
            $table->smallInteger('education_graduation_year')->nullable();
            // AJ: معدل التخرج (التحصيل العلمي)
            $table->decimal('education_grade', 5, 2)->nullable();
            // AK: عنوان بحث التخرج (التحصيل العلمي)
            $table->string('education_thesis', 500)->nullable();
            // U+AP:AT: جهة العمل (المستويات التنظيمية)
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            // AV:AW: جهة العمل الحالية (إن اختلفت)
            $table->foreignId('current_work_unit_id')->nullable()->constrained('org_units')->restrictOnDelete();
            // AX: المستوى التنظيمي (المنصب)
            $table->foreignId('position_level_id')->nullable()->constrained('position_levels')->restrictOnDelete();
            // AY: المسمى الوظيفي (التخصص)
            $table->foreignId('job_title_id')->nullable()->constrained('job_titles')->restrictOnDelete();
            // AZ: عنوان منطقة العمل
            $table->string('work_area_address', 500)->nullable();
            // BA: طبيعة العمل
            $table->foreignId('work_nature_id')->nullable()->constrained('work_natures')->restrictOnDelete();
            // BB: حالة الموظف
            $table->foreignId('employee_status_id')->nullable()->constrained('employee_statuses')->restrictOnDelete();
            // BC: الحالة الوظيفية
            $table->foreignId('job_status_id')->nullable()->constrained('job_statuses')->restrictOnDelete();
            // BD: نوع الدوام
            $table->foreignId('work_schedule_id')->nullable()->constrained('work_schedules')->restrictOnDelete();
            // BE: الراتب الأساسي
            $table->decimal('base_salary', 14, 2)->nullable();
            // BF: مستحق الراتب
            $table->boolean('salary_eligible')->nullable();
            // BI: الحالة الاجتماعية
            $table->foreignId('marital_status_id')->nullable()->constrained('marital_statuses')->restrictOnDelete();
            // BJ: عدد الزوجات
            $table->smallInteger('wives_count')->nullable();
            // BK: جنسية الزوجة
            $table->string('wife_nationality', 100)->nullable();
            // BL: عدد الأولاد
            $table->smallInteger('children_count')->nullable();
            // BM: محافظة الإقامة الحالية
            $table->string('residence_governorate', 100)->nullable();
            // BN: مدينة / بلدة الإقامة الحالية
            $table->string('residence_city', 100)->nullable();
            // BO: حي الإقامة الحالية
            $table->string('residence_district', 150)->nullable();
            // BP: العنوان التفصيلي للإقامة الحالية
            $table->string('residence_address', 500)->nullable();
            // BQ: نوع السكن الحالي
            $table->foreignId('housing_type_id')->nullable()->constrained('housing_types')->restrictOnDelete();
            // BR: قيمة الإيجار
            $table->decimal('rent_amount', 14, 2)->nullable();
            // BS: هل يخطب حالياً
            $table->boolean('preaches_now')->nullable();
            // BT: المسجد الذي يخطب فيه
            $table->string('preaching_mosque', 150)->nullable();
            // BU: هل يدرّس حالياً
            $table->boolean('teaches_now')->nullable();
            // BV: مكان التدريس الحالي
            $table->string('teaching_place', 150)->nullable();
            // BW: ملاحظات
            $table->text('notes')->nullable();
            // BX: رقم الهاتف
            $table->string('phone', 30)->nullable();
            // BY: معرف التلجرام
            $table->string('telegram', 100)->nullable();
            // BZ: البريد الإلكتروني
            $table->string('email', 150)->nullable();
            // CA: جهة اتصال الطوارئ
            $table->string('emergency_contact', 150)->nullable();
            // CB: هاتف الطوارئ
            $table->string('emergency_phone', 30)->nullable();
            // CC: المرحلة التنظيمية
            $table->foreignId('org_stage_id')->nullable()->constrained('org_stages')->restrictOnDelete();
            // CD: مستوى المرحلة التنظيمية
            $table->foreignId('org_stage_level_id')->nullable()->constrained('org_stage_levels')->restrictOnDelete();
            // CE: تاريخ التكليف
            $table->date('assignment_date')->nullable();
            // CF: تاريخ التعيين
            $table->date('appointment_date')->nullable();
            // CG: الحالة الصحية
            $table->foreignId('health_status_id')->nullable()->constrained('health_statuses')->restrictOnDelete();
            // CH: نوع الإصابة
            $table->string('injury_type', 255)->nullable();
            // CI: نوع المرض
            $table->string('illness_type', 255)->nullable();
            // CJ: الارتباط الخارجي
            $table->boolean('has_external_link')->nullable();
            // CK: نوع الجهة الأخرى
            $table->foreignId('external_entity_type_id')->nullable()->constrained('external_entity_types')->restrictOnDelete();
            // CL: اسم الجهة الأخرى
            $table->string('external_entity_name', 150)->nullable();
            // CM: نوع الارتباط
            $table->foreignId('external_link_type_id')->nullable()->constrained('external_link_types')->restrictOnDelete();
            // CN: نظام الدوام بالجهة الأخرى
            $table->foreignId('external_schedule_id')->nullable()->constrained('external_schedules')->restrictOnDelete();
            // CO: المسمى بالجهة الأخرى
            $table->string('external_title', 150)->nullable();
            // CP: الراتب من الجهة الأخرى
            $table->decimal('external_salary', 14, 2)->nullable();
            // CQ: تاريخ بدء الارتباط
            $table->date('external_start_date')->nullable();
            // CR: لديه سلاح
            $table->boolean('has_weapon')->nullable();
            // CS: نوع السلاح
            $table->string('weapon_type', 100)->nullable();
            // CT: رقم السلاح
            $table->string('weapon_number', 100)->nullable();
            // CU: نوع السلاح 2
            $table->string('weapon2_type', 100)->nullable();
            // CV: رقم السلاح 2
            $table->string('weapon2_number', 100)->nullable();
            // CW: اللوجستيات المسلمة
            $table->string('logistics_issued', 500)->nullable();
            // CX: نوع العقد
            $table->foreignId('contract_type_id')->nullable()->constrained('contract_types')->restrictOnDelete();
            // CY: تاريخ إمضاء العقد
            $table->date('contract_signed_on')->nullable();
            // CZ: تاريخ بداية العقد
            $table->date('contract_starts_on')->nullable();
            // DA: تاريخ انتهاء العقد
            $table->date('contract_ends_on')->nullable();
            // DC: رقم حساب الشام كاش
            $table->string('sham_cash_account', 50)->nullable();
            // DD: التحصيل العلمي
            $table->foreignId('education_level_id')->nullable()->constrained('education_levels')->restrictOnDelete();
            // DE: التخصص
            $table->string('education_major', 150)->nullable();
            // DF: الدراسة الحالية
            $table->foreignId('current_study_id')->nullable()->constrained('current_studies')->restrictOnDelete();
            // DG: اختصاص الدراسة الحالية
            $table->string('current_study_major', 150)->nullable();
            // DH: السنة الدراسية الحالية
            $table->foreignId('study_year_id')->nullable()->constrained('study_years')->restrictOnDelete();
            // DI: الجامعة / المعهد
            $table->string('university', 150)->nullable();
            // DJ: مجاز في القرآن
            $table->boolean('quran_licensed')->nullable();
            // DK: نوع القراءات
            $table->string('quran_readings', 150)->nullable();
            // DL: عدد الأجزاء المحفوظة
            $table->smallInteger('quran_parts')->nullable();
            // DM: القدرة على الخطابة (المستوى)
            $table->foreignId('preaching_level_id')->nullable()->constrained('ability_levels')->restrictOnDelete();
            // DN: سنوات الخبرة في الخطابة
            $table->smallInteger('preaching_years')->nullable();
            // DO: القدرة على التدريس (المستوى)
            $table->foreignId('teaching_level_id')->nullable()->constrained('ability_levels')->restrictOnDelete();
            // DP: سنوات الخبرة في التدريس
            $table->smallInteger('teaching_years')->nullable();
            // DQ: يمتلك سيارة
            $table->boolean('owns_car')->nullable();
            // DR: ملكية السيارة
            $table->foreignId('car_ownership_id')->nullable()->constrained('vehicle_ownerships')->restrictOnDelete();
            // DS: رقم السيارة
            $table->string('car_number', 50)->nullable();
            // DT: وقود السيارة
            $table->string('car_fuel', 50)->nullable();
            // DU: يمتلك دراجة
            $table->boolean('owns_motorbike')->nullable();
            // DV: ملكية الدراجة
            $table->foreignId('motorbike_ownership_id')->nullable()->constrained('vehicle_ownerships')->restrictOnDelete();
            // DW: رقم الدراجة
            $table->string('motorbike_number', 50)->nullable();
            // DX: مقاس البدلة
            $table->string('suit_size', 20)->nullable();
            // DY: مقاس الحذاء
            $table->string('shoe_size', 20)->nullable();
            // DZ: مقاس الطاقية
            $table->string('cap_size', 20)->nullable();
            // EA: الوزن (كغ)
            $table->decimal('weight_kg', 5, 1)->nullable();
            // EC: الرتبة العسكرية
            $table->string('military_rank', 100)->nullable();
            // ED: اسم الوصي
            $table->string('guardian_name', 150)->nullable();
            // EE: صلة القرابة بالوصي
            $table->foreignId('guardian_relation_id')->nullable()->constrained('guardian_relations')->restrictOnDelete();
            // EF: الرقم الوطني للوصي
            $table->string('guardian_national_number', 20)->nullable();
            // EG: هاتف الوصي
            $table->string('guardian_phone', 30)->nullable();
            // EH: عنوان الوصي
            $table->string('guardian_address', 500)->nullable();
            // EQ: هل اعتمر
            $table->boolean('did_umrah')->nullable();
            // ER: عدد مرات العمرة
            $table->smallInteger('umrah_count')->nullable();
            // ES: تاريخ آخر عمرة
            $table->date('last_umrah_on')->nullable();
            // ET: هل أدى فريضة الحج
            $table->boolean('did_hajj')->nullable();
            // EU: عدد مرات الحج
            $table->smallInteger('hajj_count')->nullable();
            // EV: تاريخ آخر حج
            $table->date('last_hajj_on')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('staff_users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();

            $table->index('file_status');
            $table->index('employee_status_id');
            $table->index('job_status_id');
            $table->index('org_unit_id');
            $table->index('contract_ends_on');
            $table->index('completeness');
        });

        DB::statement('CREATE UNIQUE INDEX employees_personal_number_unique ON employees (personal_number) WHERE personal_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX employees_national_number_unique ON employees (national_number) WHERE national_number IS NOT NULL');
        DB::statement('CREATE INDEX employees_search_name_trgm ON employees USING gin (search_name gin_trgm_ops)');

        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_file_status CHECK (file_status IN ('pending', 'active', 'rejected', 'withdrawn')),
                ADD CONSTRAINT employees_completeness_range CHECK (completeness BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_personal_number_not_blank CHECK (personal_number IS NULL OR btrim(personal_number) <> ''),
                ADD CONSTRAINT employees_first_name_format CHECK (first_name IS NULL OR (btrim(first_name) <> '' AND first_name !~ '[0-9٠-٩]')),
                ADD CONSTRAINT employees_father_name_format CHECK (father_name IS NULL OR (btrim(father_name) <> '' AND father_name !~ '[0-9٠-٩]')),
                ADD CONSTRAINT employees_family_name_format CHECK (family_name IS NULL OR (btrim(family_name) <> '' AND family_name !~ '[0-9٠-٩]')),
                ADD CONSTRAINT employees_mother_name_format CHECK (mother_name IS NULL OR (btrim(mother_name) <> '' AND mother_name !~ '[0-9٠-٩]')),
                ADD CONSTRAINT employees_birth_date_range CHECK (birth_date IS NULL OR birth_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_national_number_digits CHECK (national_number IS NULL OR national_number ~ '^[0-9]{5,20}$'),
                ADD CONSTRAINT employees_height_cm_range CHECK (height_cm IS NULL OR height_cm BETWEEN 0 AND 300),
                ADD CONSTRAINT employees_confirmation_date_range CHECK (confirmation_date IS NULL OR confirmation_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_discharge_date_range CHECK (discharge_date IS NULL OR discharge_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_first_study_graduation_year_range CHECK (first_study_graduation_year IS NULL OR first_study_graduation_year BETWEEN 1950 AND 2100),
                ADD CONSTRAINT employees_first_study_grade_range CHECK (first_study_grade IS NULL OR first_study_grade BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_second_study_graduation_year_range CHECK (second_study_graduation_year IS NULL OR second_study_graduation_year BETWEEN 1950 AND 2100),
                ADD CONSTRAINT employees_second_study_grade_range CHECK (second_study_grade IS NULL OR second_study_grade BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_education_graduation_year_range CHECK (education_graduation_year IS NULL OR education_graduation_year BETWEEN 1950 AND 2100),
                ADD CONSTRAINT employees_education_grade_range CHECK (education_grade IS NULL OR education_grade BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_base_salary_not_negative CHECK (base_salary IS NULL OR base_salary >= 0),
                ADD CONSTRAINT employees_wives_count_range CHECK (wives_count IS NULL OR wives_count BETWEEN 0 AND 10),
                ADD CONSTRAINT employees_children_count_range CHECK (children_count IS NULL OR children_count BETWEEN 0 AND 50),
                ADD CONSTRAINT employees_rent_amount_not_negative CHECK (rent_amount IS NULL OR rent_amount >= 0),
                ADD CONSTRAINT employees_phone_format CHECK (phone IS NULL OR phone ~ '^\+?[0-9]{6,20}$'),
                ADD CONSTRAINT employees_emergency_phone_format CHECK (emergency_phone IS NULL OR emergency_phone ~ '^\+?[0-9]{6,20}$'),
                ADD CONSTRAINT employees_assignment_date_range CHECK (assignment_date IS NULL OR assignment_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_appointment_date_range CHECK (appointment_date IS NULL OR appointment_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_external_salary_not_negative CHECK (external_salary IS NULL OR external_salary >= 0),
                ADD CONSTRAINT employees_external_start_date_range CHECK (external_start_date IS NULL OR external_start_date BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_contract_signed_on_range CHECK (contract_signed_on IS NULL OR contract_signed_on BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_contract_starts_on_range CHECK (contract_starts_on IS NULL OR contract_starts_on BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_contract_ends_on_range CHECK (contract_ends_on IS NULL OR contract_ends_on BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_quran_parts_range CHECK (quran_parts IS NULL OR quran_parts BETWEEN 0 AND 30),
                ADD CONSTRAINT employees_preaching_years_range CHECK (preaching_years IS NULL OR preaching_years BETWEEN 0 AND 80),
                ADD CONSTRAINT employees_teaching_years_range CHECK (teaching_years IS NULL OR teaching_years BETWEEN 0 AND 80),
                ADD CONSTRAINT employees_weight_kg_range CHECK (weight_kg IS NULL OR weight_kg BETWEEN 0 AND 400),
                ADD CONSTRAINT employees_guardian_national_number_digits CHECK (guardian_national_number IS NULL OR guardian_national_number ~ '^[0-9]{5,20}$'),
                ADD CONSTRAINT employees_guardian_phone_format CHECK (guardian_phone IS NULL OR guardian_phone ~ '^\+?[0-9]{6,20}$'),
                ADD CONSTRAINT employees_umrah_count_range CHECK (umrah_count IS NULL OR umrah_count BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_last_umrah_on_range CHECK (last_umrah_on IS NULL OR last_umrah_on BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_hajj_count_range CHECK (hajj_count IS NULL OR hajj_count BETWEEN 0 AND 100),
                ADD CONSTRAINT employees_last_hajj_on_range CHECK (last_hajj_on IS NULL OR last_hajj_on BETWEEN DATE '1900-01-01' AND DATE '2200-12-31'),
                ADD CONSTRAINT employees_contract_dates_order CHECK (contract_ends_on IS NULL OR contract_starts_on IS NULL OR contract_ends_on >= contract_starts_on),
                ADD CONSTRAINT employees_unapproved_file_is_empty CHECK (file_status = 'active' OR (first_name IS NULL AND national_number IS NULL AND personal_number IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
