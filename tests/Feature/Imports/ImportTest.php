<?php

namespace Tests\Feature\Imports;

use App\Modules\Employees\Models\Employee;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeAttendance;
use App\Modules\Records\Models\EmployeeMovement;
use App\Support\BusinessDay;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkbookBuilder;
use Tests\TestCase;
use ZipArchive;

/** D-053: importing the owner's workbook — the employees and the four registers, each if present. */
class ImportTest extends TestCase
{
    private const MOVEMENT_HEADERS = ['#', 'التاريخ', 'الرقم الذاتي', 'اسم الموظف', 'جهة العمل', 'المسمى الوظيفي', 'نوع الحركة', 'الوصف', 'المبلغ', 'من تاريخ', 'إلى تاريخ', 'عدد الأيام', 'ملاحظات', 'الحالة', 'السنة', 'الشهر'];

    private const ATTENDANCE_HEADERS = ['#', 'السنة', 'الشهر', 'الرقم الذاتي', 'اسم الموظف', 'أيام الدوام المقررة', 'أيام الدوام الفعلية (الحضور)', 'أيام الغياب', 'نسبة الغياب', 'المهارات الاستثنائية'];

    protected function setUp(): void
    {
        parent::setUp();

        // The structure the workbook's units name.
        $this->actingAsStaff($this->admin());
        $top = $this->postJson(self::API.'/settings/org-units', ['name_ar' => 'القيادة'])->json('data.id');
        $this->postJson(self::API.'/settings/org-units', ['name_ar' => 'الإدارة', 'parent_id' => $top])->assertCreated();
    }

    /** @return array<string, mixed> a good row of the employees' sheet */
    private function person(string $number, array $over = []): array
    {
        static $n = 0;
        $families = ['الحسن', 'الخطيب', 'النجار', 'الحداد', 'العطار', 'السمان', 'الشامي', 'الحلبي', 'الحمصي', 'الدمشقي', 'الادلبي', 'الرفاعي', 'الزعبي', 'المصري'];
        $family = $families[$n++ % count($families)];

        return array_merge([
            'الرقم الذاتي' => $number, 'الاسم الثلاثي' => 'يُحسب', 'الاسم' => 'سامر', 'اسم الأب' => 'علي', 'العائلة' => $family,
            'الجنس' => 'ذكر', 'تاريخ الميلاد' => '15/03/1990', 'الرقم الوطني' => (string) (30000000000 + (int) $number),
            'المستوى التنظيمي الأول' => 'القيادة', 'المستوى التنظيمي الثاني' => 'الادارة', 'الحالة الوظيفية' => 'متعاقد دوام',
            'حالة الموظف' => 'فعال', 'رقم الهاتف' => '0991234567', 'الراتب الأساسي' => 500000, 'لديه سلاح' => 'لا',
        ], $over);
    }

    private function upload(WorkbookBuilder $book, string $duplicates = 'skip', ?string $name = null)
    {
        return $this->post(self::API.'/imports', ['file' => $book->upload($name ?? 'workbook-'.uniqid().'.xlsx'), 'duplicates' => $duplicates], ['Accept' => 'application/json']);
    }

    private function rows(string $import, string $sheet): array
    {
        return collect($this->getJson(self::API."/imports/{$import}/rows?sheet={$sheet}&per_page=100")->assertOk()->json('data'))->keyBy('line')->all();
    }

    private static function messages(array $row, ?string $level = null): string
    {
        return implode(' | ', array_column(array_filter($row['issues'], fn ($i) => $level === null || $i['level'] === $level), 'message'));
    }

    public function test_every_row_is_staged_with_what_is_wrong_in_it(): void
    {
        $stored = $this->employeeFile(['first_name' => 'ماهر', 'father_name' => 'سعيد', 'family_name' => 'العلي', 'birth_date' => '1985-01-01', 'phone' => '0933000000']);

        $book = (new WorkbookBuilder)->employees([
            $this->person('1001', ['رابط الهوية' => 'https://drive/x', 'آخر مراجعة - الدورة الشهرية' => '2026-10-01']),
            $this->person('1002', ['الجنس' => 'ذكرر', 'المستوى التنظيمي الثاني' => 'الإدارة الوهمية', 'الرقم الوطني' => '123',
                'تاريخ بداية العقد' => '2026-05-01', 'تاريخ انتهاء العقد' => '2026-01-01', 'لديه سلاح' => 'ربما']),
            $this->person('1003'),
            $this->person('1003'),
            $this->person('1004', ['الرقم الوطني' => $stored->national_number]),
            $this->person('1005', ['الاسم' => 'ماهر', 'اسم الأب' => 'سعيد', 'العائلة' => 'العلي', 'تاريخ الميلاد' => '1985-01-01']),
            $this->person('1006', ['الحالة الوظيفية' => 'مسرح', 'رقم الهاتف' => 991234567]),
            $this->person($stored->personal_number, ['الاسم' => 'ماهر', 'اسم الأب' => 'سعيد', 'العائلة' => 'العلي', 'تاريخ الميلاد' => '1985-01-01',
                'الرقم الوطني' => null, 'رقم الهاتف' => '0944111111']),
            [],
            $this->person('1007', ['الاسم' => '', 'آخر مراجعة - الدورة الشهرية' => '2099-01-01']),
        ], ['عمود لا يعرفه النظام'])
            ->sheet('سجل الحركات', 5, self::MOVEMENT_HEADERS, [
                ['التاريخ' => '2026-03-01', 'الرقم الذاتي' => '1001', 'اسم الموظف' => 'سامر علي الحسن', 'نوع الحركة' => 'إجازة داخلية', 'من تاريخ' => '2026-03-01', 'إلى تاريخ' => '2026-03-05', 'عدد الأيام' => 4],
                ['التاريخ' => '2026-03-02', 'الرقم الذاتي' => '9999', 'نوع الحركة' => 'مكافأة', 'المبلغ' => 100],
                ['التاريخ' => '2026-03-02', 'الرقم الذاتي' => '1002', 'نوع الحركة' => 'مكافأة', 'المبلغ' => 100],
                ['التاريخ' => '2026-03-03', 'الرقم الذاتي' => '1001', 'نوع الحركة' => 'مكافاة', 'المبلغ' => 100],
                ['التاريخ' => '2026-03-03', 'الرقم الذاتي' => '1001', 'نوع الحركة' => 'مكافاة', 'المبلغ' => 100],
                ['التاريخ' => '2026-03-04', 'الرقم الذاتي' => '1001', 'نوع الحركة' => 'ترفيع', 'المبلغ' => 'كثير'],
                ['التاريخ' => '2026-03-05', 'الرقم الذاتي' => '1001', 'اسم الموظف' => 'شخص آخر', 'نوع الحركة' => 'تسريح'],
                // The workbook's empty lines: only its formulas.
                ['#' => ['formula' => '=IF(B13="","",ROW()-5)'], 'اسم الموظف' => ['formula' => '=IF(C13="","",1)'], 'السنة' => ['formula' => '=YEAR(B13)']],
            ])
            ->sheet('سجل الدوام الشهري', 5, self::ATTENDANCE_HEADERS, [
                ['السنة' => 2026, 'الشهر' => 3, 'الرقم الذاتي' => '1001', 'أيام الدوام المقررة' => 20, 'أيام الدوام الفعلية (الحضور)' => 25],
                ['السنة' => 2026, 'الشهر' => 2, 'الرقم الذاتي' => $stored->personal_number, 'أيام الدوام المقررة' => 20, 'أيام الدوام الفعلية (الحضور)' => 19],
            ]);

        $entry = $this->staffUser();
        $import = $this->actingAsStaff($entry)->upload($book)->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.duplicates', 'skip')
            ->assertJsonPath('data.can_cancel', true)->assertJsonPath('data.can_approve', false)
            ->json('data');

        $this->assertSame(['employees', 'movements', 'attendance'], array_column($import['sheets'], 'sheet'));
        $columns = collect($import['sheets'][0]['columns'])->keyBy('title');
        $this->assertSame(4, $import['sheets'][0]['header_row']);
        $this->assertSame('matched', $columns['الرقم الوطني']['status']);
        $this->assertSame('field:national_number', $columns['الرقم الوطني']['target']);
        $this->assertSame('computed', $columns['الاسم الثلاثي']['status']);
        $this->assertSame('link', $columns['رابط الهوية']['status']);
        $this->assertSame('unmatched', $columns['عمود لا يعرفه النظام']['status']);
        $this->assertSame('org_level:2', $columns['المستوى التنظيمي الثاني']['target']);

        $e = $this->rows($import['id'], 'employees');
        $this->assertSame([5, 6, 7, 8, 9, 10, 11, 12, 14], array_keys($e));

        $this->assertSame('valid', $e[5]['status']);
        $this->assertSame('create', $e[5]['action']);
        $this->assertStringContainsString('الروابط لا تُستورد', self::messages($e[5], 'info'));

        $this->assertSame('invalid', $e[6]['status']);
        $errors = self::messages($e[6], 'error');
        $this->assertStringContainsString('«ذكرر» ليست من قائمة «الجنس»', $errors);
        $this->assertStringContainsString('«القيادة / الإدارة الوهمية» ليست في الهيكلية التنظيمية', $errors);
        $this->assertStringContainsString('الرقم الوطني', $errors);
        $this->assertStringContainsString('تاريخ انتهاء العقد قبل تاريخ بدايته', $errors);
        $this->assertStringContainsString('«ربما» ليست «نعم» أو «لا»', $errors);

        $this->assertSame('invalid', $e[7]['status']);
        $this->assertStringContainsString('مكرر في الملف (الأسطر 7، 8)', self::messages($e[8], 'error'));
        $this->assertStringContainsString('لموظف آخر محفوظ', self::messages($e[9], 'error'));
        $this->assertSame('review', $e[10]['status']);
        $this->assertStringContainsString('قد يكون مكرراً للموظف '.$stored->personal_number, self::messages($e[10], 'warning'));
        $this->assertSame('review', $e[11]['status']);
        $this->assertStringContainsString('دون تاريخ التسريح', self::messages($e[11]));
        $this->assertStringContainsString('ربما حُذف الصفر الأول', self::messages($e[11]));

        // The stored employee: left as it is, with what differs.
        $this->assertSame('excluded', $e[12]['status']);
        $this->assertSame('skip', $e[12]['action']);
        $this->assertStringContainsString('«رقم الهاتف»: المحفوظ «0933000000» وفي الملف «0944111111»', self::messages($e[12], 'warning'));

        $this->assertSame('invalid', $e[14]['status']);
        $this->assertStringContainsString('ينقصه «الاسم»', self::messages($e[14], 'error'));
        $this->assertStringContainsString('في المستقبل', self::messages($e[14], 'error'));

        $m = $this->rows($import['id'], 'movements');
        $this->assertSame([6, 7, 8, 9, 10, 11, 12], array_keys($m));
        $this->assertSame('review', $m[6]['status']);
        $this->assertStringContainsString('عدد الأيام في الملف 4 والمحسوب من الفترة 5', self::messages($m[6]));
        $this->assertStringContainsString('ليس لموظف محفوظ', self::messages($m[7], 'error'));
        $this->assertStringContainsString('ملف الموظف 1002 في السطر 6 من ورقة الموظفين غير صالح', self::messages($m[8], 'error'));
        $this->assertSame('valid', $m[9]['status']);
        $this->assertSame('review', $m[10]['status']);
        $this->assertStringContainsString('مطابق للسطر 9', self::messages($m[10]));
        $this->assertStringContainsString('«ترفيع» ليست من قائمة «نوع الحركة»', self::messages($m[11], 'error'));
        $this->assertStringContainsString('المبلغ', self::messages($m[11], 'error'));
        $this->assertStringContainsString('يختلف عن ملفه', self::messages($m[12], 'warning'));
        $this->assertStringContainsString('لا تغيّر ملف الموظف', self::messages($m[12], 'info'));

        $a = $this->rows($import['id'], 'attendance');
        $this->assertSame('invalid', $a[6]['status']);
        $this->assertSame('valid', $a[7]['status']);

        $this->assertSame(['total' => 18, 'valid' => 3, 'review' => 5, 'invalid' => 9, 'excluded' => 1],
            $this->getJson(self::API."/imports/{$import['id']}")->json('data.counts'));

        // Not approvable while a row is invalid; nothing is written meanwhile.
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/imports/{$import['id']}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('import');
        $this->assertSame(0, Employee::query()->where('personal_number', '1001')->count());
        $this->getJson(self::API."/imports/{$import['id']}/rows?status=invalid")->assertJsonCount(9, 'data');
    }

    public function test_an_approved_import_writes_the_files_their_reviews_and_the_registers(): void
    {
        $book = (new WorkbookBuilder)->employees([
            $this->person('2001', ['آخر مراجعة - الدورة الشهرية' => '2026-10-15', 'تاريخ بداية العقد' => '2026-01-01', 'تاريخ انتهاء العقد' => '2027-01-01']),
            $this->person('2002', ['الحالة الوظيفية' => 'متعاقد نوبات']),
        ])
            ->sheet('سجل الحركات', 5, self::MOVEMENT_HEADERS, [
                ['التاريخ' => '2026-03-01', 'الرقم الذاتي' => '2001', 'نوع الحركة' => 'إجازة داخلية', 'من تاريخ' => '2026-03-01', 'إلى تاريخ' => '2026-03-03'],
                ['التاريخ' => '2026-04-01', 'الرقم الذاتي' => '2002', 'نوع الحركة' => 'تسريح'],
            ])
            ->sheet('سجل الدوام الشهري', 5, self::ATTENDANCE_HEADERS, [
                ['السنة' => 2026, 'الشهر' => 3, 'الرقم الذاتي' => '2001', 'أيام الدوام المقررة' => 22, 'أيام الدوام الفعلية (الحضور)' => 20],
            ]);

        $entry = $this->staffUser();
        $import = $this->actingAsStaff($entry)->upload($book)->assertCreated()->json('data.id');
        $this->getJson(self::API.'/badges')->assertJsonPath('data.mine.pending', 1);

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/badges')->assertJsonPath('data.reviews.imports', 1);
        $this->postJson(self::API."/imports/{$import}/approve", ['note' => 'راجعت المعاينة'])->assertOk()->assertJsonPath('data.status', 'applying');

        // Written after the response.
        $done = $this->getJson(self::API."/imports/{$import}")->assertOk()->assertJsonPath('data.status', 'approved')->json('data');
        $this->assertEquals(['files_created' => 2, 'files_updated' => 0, 'reviews' => 1, 'records' => ['movements' => ['added' => 2], 'attendance' => ['added' => 1]]], $done['applied']);

        $first = Employee::query()->where('personal_number', '2001')->firstOrFail();
        $this->assertTrue($first->isActive());
        $this->assertSame('1990-03-15', $first->birth_date->format('Y-m-d'));
        $this->assertSame('0991234567', $first->phone);
        $this->assertNotNull($first->org_unit_id);
        // Not locked: the administrator checks an imported identity first.
        $this->assertNull($first->identity_locked_at);
        $this->assertSame(1, DB::table('employee_change_sets')->where('employee_id', $first->id)->where('import_id', $import)
            ->where('kind', 'create')->where('submitted_by', $entry->id)->where('reviewed_by', $supervisor->id)->where('self_approved', false)->count());
        $this->assertSame('2026-10-15', (string) DB::table('employee_reviews')->where('employee_id', $first->id)->where('cycle', 'monthly')->value('reviewed_on'));

        // A movement is history: taken, but the file stays the workbook's.
        $second = Employee::query()->where('personal_number', '2002')->firstOrFail();
        $discharge = EmployeeMovement::query()->where('employee_id', $second->id)->firstOrFail();
        $this->assertNotNull($discharge->applied_to_file_at);
        $this->assertNull($discharge->file_change_set_id);
        $this->assertNull($second->discharge_date);
        $this->assertSame(1, DB::table('record_change_sets')->where('record_type', 'attendance')->where('import_id', $import)->count());
        $this->assertSame(3, (int) EmployeeMovement::query()->where('employee_id', $first->id)->value('days'));
        $this->assertSame(1, EmployeeAttendance::query()->where('employee_id', $first->id)->count());

        // The rows know what they made; the uploader is told; it is all in the log.
        $this->assertSame($first->id, $this->rows($import, 'employees')[5]['employee_id']);
        $this->assertNotNull($this->rows($import, 'attendance')[6]['record_id']);
        $this->actingAsStaff($entry)->getJson(self::API.'/notifications')->assertJsonPath('data.0.kind', 'import_finished')
            ->assertJsonPath('data.0.details.status', 'approved');
        foreach (['imports.uploaded', 'imports.approved', 'imports.applied'] as $event) {
            $this->assertDatabaseHas('audit_events', ['event' => $event]);
        }

        // The same file again would double everything.
        $this->assertSame(2, Employee::query()->whereIn('personal_number', ['2001', '2002'])->count());
    }

    public function test_the_same_file_is_not_imported_twice(): void
    {
        $path = (new WorkbookBuilder)->employees([$this->person('2101')])->path();
        $file = fn () => new UploadedFile($path, 'a.xlsx', null, null, true);

        $this->actingAsStaff($this->admin());
        $id = $this->post(self::API.'/imports', ['file' => $file()], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->post(self::API.'/imports', ['file' => $file()], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', 'هذا الملف نفسه مرفوع وبانتظار المراجعة.');

        // An administrator may approve their own import: it applies at once, as their own entries do.
        $this->postJson(self::API."/imports/{$id}/approve")->assertOk();
        $this->assertSame('approved', $this->getJson(self::API."/imports/{$id}")->json('data.status'));
        $this->post(self::API.'/imports', ['file' => $file()], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        $this->assertStringContainsString('استُورد من قبل', $this->post(self::API.'/imports', ['file' => $file()], ['Accept' => 'application/json'])->json('errors.file.0'));
    }

    public function test_update_corrects_stored_files_and_months_but_never_a_locked_identity(): void
    {
        $stored = $this->employeeFile(['first_name' => 'ماهر', 'father_name' => 'سعيد', 'family_name' => 'العلي', 'phone' => '0933000000']);
        $this->assertNotNull($stored->refresh()->identity_locked_at);
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/attendance', ['employee_id' => $stored->id, 'values' => ['year' => 2026, 'month' => 2, 'planned_days' => 20, 'attended_days' => 20]])->assertCreated();
        $row = fn (array $over) => array_merge(['الرقم الذاتي' => $stored->personal_number, 'الاسم' => 'ماهر', 'اسم الأب' => 'سعيد', 'العائلة' => 'العلي',
            'الرقم الوطني' => $stored->national_number], $over);

        $locked = $this->upload((new WorkbookBuilder)->employees([$row(['الاسم' => 'ماجد', 'رقم الهاتف' => '0944111111'])]), 'update')->assertCreated()->json('data.id');
        $e = $this->rows($locked, 'employees')[5];
        $this->assertSame(['invalid', 'update'], [$e['status'], $e['action']]);
        $this->assertStringContainsString('«الاسم» مثبت ولا يتغير: المحفوظ «ماهر» وفي الملف «ماجد»', self::messages($e, 'error'));
        $this->postJson(self::API."/imports/{$locked}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $book = (new WorkbookBuilder)->employees([$row(['رقم الهاتف' => '0944111111', 'آخر مراجعة - الدورة الشهرية' => '2026-11-01'])])
            ->sheet('سجل الدوام الشهري', 5, self::ATTENDANCE_HEADERS, [
                ['السنة' => 2026, 'الشهر' => 2, 'الرقم الذاتي' => $stored->personal_number, 'أيام الدوام المقررة' => 20, 'أيام الدوام الفعلية (الحضور)' => 18],
            ]);
        $id = $this->upload($book, 'update')->assertCreated()->json('data.id');
        $e = $this->rows($id, 'employees')[5];
        $this->assertSame(['valid', 'update'], [$e['status'], $e['action']]);
        $this->assertSame([['field' => 'phone', 'label' => 'رقم الهاتف', 'from' => '0933000000', 'to' => '0944111111']], $e['changes']);
        $a = $this->rows($id, 'attendance')[6];
        $this->assertSame('correct', $a['action']);
        $this->assertSame('20', $a['changes'][0]['from']);

        $this->postJson(self::API."/imports/{$id}/approve")->assertOk();
        $this->assertEquals(['files_created' => 0, 'files_updated' => 1, 'reviews' => 1, 'records' => ['attendance' => ['corrected' => 1]]],
            $this->getJson(self::API."/imports/{$id}")->json('data.applied'));
        $this->assertSame('0944111111', $stored->refresh()->phone);
        $this->assertEquals(18, EmployeeAttendance::query()->where('employee_id', $stored->id)->value('attended_days'));

        // The same values again: nothing to do.
        $again = $this->upload((new WorkbookBuilder)->employees([$row(['رقم الهاتف' => '0944111111'])]), 'update')->json('data.id');
        $this->assertStringContainsString('لا يختلف', self::messages($this->rows($again, 'employees')[5]));
    }

    public function test_a_workbook_is_refused_whole_when_it_cannot_be_read_as_the_owners(): void
    {
        $this->actingAsStaff($this->staffUser());

        $this->post(self::API.'/imports', ['file' => UploadedFile::fake()->createWithContent('x.xlsx', 'not a zip')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', __('imports.not_xlsx'));

        $macros = (new WorkbookBuilder)->employees([$this->person('3001')])->path();
        $zip = new ZipArchive;
        $zip->open($macros);
        $zip->addFromString('xl/vbaProject.bin', 'code');
        $zip->close();
        $this->post(self::API.'/imports', ['file' => new UploadedFile($macros, 'm.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', __('imports.has_macros'));

        $this->upload((new WorkbookBuilder)->sheet('ورقة أخرى', 1, ['أ', 'ب'], [['أ' => 1]]))->assertUnprocessable()
            ->assertJsonPath('errors.file.0', __('imports.no_known_sheet'));

        $missing = $this->upload((new WorkbookBuilder)->sheet('قاعدة بيانات الموظفين', 4, ['الرقم الذاتي', 'الاسم', 'اسم الأب'], [['الرقم الذاتي' => '1']]))
            ->assertUnprocessable()->json('errors.file.0');
        $this->assertStringContainsString('ينقصها «الرقم الوطني»، «العائلة»', $missing);

        $this->upload((new WorkbookBuilder)->employees([]))->assertUnprocessable()->assertJsonPath('errors.file.0', __('imports.nothing_to_import'));
        $this->assertSame(0, DB::table('imports')->count());
    }

    public function test_who_does_what_with_an_import(): void
    {
        $entry = $this->staffUser();
        $id = $this->actingAsStaff($entry)->upload((new WorkbookBuilder)->employees([$this->person('4001')]))->assertCreated()->json('data.id');

        // Data entry uploads but does not approve; the supervisor reviews but does not upload; the general manager sees nothing.
        $this->postJson(self::API."/imports/{$id}/approve")->assertForbidden();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->upload((new WorkbookBuilder)->employees([$this->person('4002')]))->assertForbidden();
        $this->postJson(self::API."/imports/{$id}/cancel")->assertForbidden();
        $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager))->getJson(self::API.'/imports')->assertForbidden();
        $this->actingAsStaff($this->staffUser())->postJson(self::API."/imports/{$id}/cancel")->assertForbidden();

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/imports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_reject', true);
        $this->postJson(self::API."/imports/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::API."/imports/{$id}/reject", ['reason' => 'أعد التحقق من الأرقام'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'أعد التحقق من الأرقام');
        $this->postJson(self::API."/imports/{$id}/approve")->assertUnprocessable();

        $this->actingAsStaff($entry)->getJson(self::API.'/notifications')->assertJsonPath('data.0.details.area', 'imports')
            ->assertJsonPath('data.0.details.change_set_id', $id);
        $this->getJson(self::API.'/imports?mine=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.status_label', 'مردود');
        $this->assertSame(0, Employee::query()->where('personal_number', '4001')->count());
    }

    public function test_a_row_refused_when_written_rolls_the_whole_import_back(): void
    {
        $entry = $this->staffUser();
        $book = (new WorkbookBuilder)->employees([$this->person('5001'), $this->person('5002')])
            ->sheet('سجل الدوام الشهري', 5, self::ATTENDANCE_HEADERS, [
                ['السنة' => 2026, 'الشهر' => 3, 'الرقم الذاتي' => '5001', 'أيام الدوام المقررة' => 20, 'أيام الدوام الفعلية (الحضور)' => 20],
            ]);
        $id = $this->actingAsStaff($entry)->upload($book)->assertCreated()->json('data.id');

        // Meanwhile, someone files 5002 by hand.
        $this->employeeFile(['personal_number' => '5002']);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/imports/{$id}/approve")->assertOk();
        $import = $this->getJson(self::API."/imports/{$id}")->assertJsonPath('data.status', 'failed')->json('data');
        $this->assertSame(['employees', 'قاعدة بيانات الموظفين', 6], [$import['failure']['sheet'], $import['failure']['sheet_label'], $import['failure']['line']]);
        $this->assertStringContainsString('الرقم الذاتي', $import['failure']['message']);

        // Nothing of it was kept.
        $this->assertSame(0, Employee::query()->where('personal_number', '5001')->count());
        $this->assertSame(0, DB::table('employee_change_sets')->where('import_id', $id)->count());
        $this->assertSame(0, DB::table('record_change_sets')->where('import_id', $id)->count());
        $this->actingAsStaff($entry)->getJson(self::API.'/notifications')->assertJsonPath('data.0.details.status', 'failed');
        $this->assertDatabaseHas('audit_events', ['event' => 'imports.failed']);
    }

    public function test_the_import_tables_keep_their_history(): void
    {
        $id = $this->actingAsStaff($this->admin())->upload((new WorkbookBuilder)->employees([$this->person('6001')]))->json('data.id');

        foreach ([
            fn () => DB::table('imports')->where('id', $id)->delete(),
            fn () => DB::table('imports')->where('id', $id)->update(['file_name' => 'other.xlsx']),
            fn () => DB::table('import_rows')->where('import_id', $id)->update(['status' => 'invalid']),
            fn () => DB::table('import_rows')->where('import_id', $id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The change went through.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('import', $e->getMessage());
            }
        }

        $this->assertSame(BusinessDay::today()->format('Y'), substr((string) DB::table('imports')->where('id', $id)->value('uploaded_at'), 0, 4));
    }
}
