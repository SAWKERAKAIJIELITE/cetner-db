<?php

namespace Tests\Feature\Reports;

use App\Modules\Identity\Enums\StaffRole;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/** D-050 and D-052: the per-person forms (screen and PDF) and the Excel exports. */
class FormAndExportTest extends TestCase
{
    /** @return list<list<mixed>> every row of the first sheet */
    private function readXlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    private function rowOf(array $section, string $key): ?array
    {
        return collect($section['rows'])->firstWhere('key', $key);
    }

    public function test_the_forms_show_the_approved_file_as_the_workbook_prints_it(): void
    {
        $employee = $this->employeeFile(['birth_date' => '1990-05-01', 'gender_id' => $this->itemId('gender', 'male'), 'base_salary' => '1250000', 'has_weapon' => true]);
        $this->actingAsStaff($this->staffUser());
        // A change waiting for review is not printed.
        $this->patchJson(self::API."/employees/{$employee->id}", ['values' => ['nickname' => 'أبو محمد']])->assertCreated();

        $this->getJson(self::API."/employees/{$employee->id}/forms")->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.key', 'status')->assertJsonPath('data.2.takes_year', true);

        $basic = $this->getJson(self::API."/employees/{$employee->id}/forms/basic")->assertOk()->json('data');
        $this->assertSame('استمارة المعلومات الأساسية', $basic['title']);
        $this->assertSame(['التعريف', 'البيانات الشخصية', 'الحالة الاجتماعية', 'التواصل', 'الإقامة الحالية والسكن', 'معلومات الوصي', 'العمرة والحج'], array_column($basic['sections'], 'title'));
        $this->assertSame('أحمد محمد الخطيب', $this->rowOf($basic['sections'][0], 'full_name')['display']);
        $this->assertNull($this->rowOf($basic['sections'][0], 'nickname')['display']);
        $this->assertSame('ذكر', $this->rowOf($basic['sections'][1], 'gender_id')['display']);
        $this->assertNotNull($this->rowOf($basic['sections'][1], 'age')['display']);
        $this->assertSame([], $basic['tables']);

        $detailed = $this->getJson(self::API."/employees/{$employee->id}/forms/detailed")->assertOk()->json('data');
        $salary = collect($detailed['sections'])->firstWhere('key', 'salary');
        $this->assertSame('1,250,000.00', $this->rowOf($salary, 'base_salary')['display']);
        $this->assertSame('لا', $this->rowOf($salary, 'has_debts')['display']);
        $documents = collect($detailed['sections'])->firstWhere('key', 'documents');
        $this->assertCount(14, $documents['rows']);
        $this->assertSame('غير مرفقة', $this->rowOf($documents, 'documents.portrait')['display']);
        $this->assertSame('0 من 13', $this->rowOf($documents, 'documents_attached')['display']);
        $this->assertSame('نعم', $this->rowOf(collect($detailed['sections'])->firstWhere('key', 'arms'), 'has_weapon')['display']);
        $this->assertTrue($this->rowOf(collect($detailed['sections'])->firstWhere('key', 'quality'), 'missing_fields')['wide']);

        $this->getJson(self::API."/employees/{$employee->id}/forms/nothing")->assertNotFound();
    }

    public function test_the_status_report_takes_a_year_and_the_latest_records(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin());
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '2025-06-01', 'movement_type_id' => $this->itemId('movement_type', 'leave_internal'), 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-03',
        ]])->assertCreated();
        $this->postJson(self::API.'/records/attendance', ['employee_id' => $employee->id, 'values' => ['year' => 2025, 'month' => 6, 'planned_days' => 20, 'attended_days' => 15]])->assertCreated();

        $form = $this->getJson(self::API."/employees/{$employee->id}/forms/status?year=2025")->assertOk()->json('data');
        $this->assertSame(2025, $form['year']);
        $year = collect($form['sections'])->firstWhere('key', 'year');
        $this->assertSame('1', $this->rowOf($year, 'year.leaves')['display']);
        $this->assertSame('3', $this->rowOf($year, 'year.leave_days')['display']);
        $this->assertSame('18', $this->rowOf($year, 'year.leave_balance')['display']);
        $this->assertSame('25.00%', $this->rowOf($year, 'year.absence_rate')['display']);
        $this->assertSame(['movements', 'conduct', 'attendance'], array_column($form['tables'], 'key'));
        $this->assertSame('إجازة داخلية', $form['tables'][0]['rows'][0]['type']);
        $this->assertSame([], $form['tables'][1]['rows']);
        $this->assertSame('2025-06', $form['tables'][2]['rows'][0]['month']);

        // Another year: nothing of 2025.
        $this->getJson(self::API."/employees/{$employee->id}/forms/status?year=2024")->assertOk();
        $this->getJson(self::API."/employees/{$employee->id}/forms/status?year=1990")->assertJsonValidationErrors('year');
    }

    public function test_a_form_prints_as_a_pdf_and_is_logged(): void
    {
        $employee = $this->employeeFile();
        $reader = $this->staffUser();

        foreach (['basic', 'detailed', 'status'] as $form) {
            $response = $this->actingAsStaff($reader)->get(self::API."/employees/{$employee->id}/forms/{$form}/pdf")->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString("{$form}-{$employee->personal_number}.pdf", $response->headers->get('Content-Disposition'));
        }

        $this->assertSame(3, DB::table('audit_events')->where('event', 'reports.form_printed')->where('actor_id', $reader->id)->count());
    }

    public function test_only_an_approved_file_has_forms(): void
    {
        $this->actingAsStaff($this->staffUser());
        $pending = $this->postJson(self::API.'/employees', ['values' => [
            'personal_number' => '777', 'national_number' => '12345678901', 'first_name' => 'سامر', 'father_name' => 'علي', 'family_name' => 'حسن',
        ]])->assertCreated()->json('data.employee.id');

        $this->getJson(self::API."/employees/{$pending}/forms/basic")->assertUnprocessable()->assertJsonValidationErrors('employee');
        $this->get(self::API."/employees/{$pending}/forms/basic/pdf", ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_the_employees_export_has_every_field_as_people_read_it(): void
    {
        $married = $this->itemId('marital_status', 'متزوج');
        $first = $this->employeeFile(['first_name' => 'زياد', 'marital_status_id' => $married, 'base_salary' => '1500', 'notes' => '=HYPERLINK("http://x")']);
        $this->employeeFile(['first_name' => 'باسل', 'gender_id' => $this->itemId('gender', 'female')]);
        $manager = $this->staffUser(StaffRole::GeneralManager);

        $response = $this->actingAsStaff($manager)->get(self::API.'/exports/employees?gender_id='.$this->itemId('gender', 'female'))->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertCount(2, $this->readXlsx($response->streamedContent()));

        $rows = $this->readXlsx($this->get(self::API.'/exports/employees?sort=name')->streamedContent());
        $headers = $rows[0];
        $this->assertSame(['الرقم الذاتي', 'الاسم الثلاثي'], array_slice($headers, 0, 2));
        $this->assertContains('الحالة الاجتماعية', $headers);
        $this->assertContains('نسبة اكتمال البيانات', $headers);
        // The number and the name, the 124 other fields, eight formulas, the four last reviews, «تاريخ التقديم».
        $this->assertCount(2 + 124 + 8 + 4 + 1, $headers);

        $row = array_combine($headers, $rows[2]);
        $this->assertSame($first->personal_number, $row['الرقم الذاتي']);
        $this->assertSame('متزوج', $row['الحالة الاجتماعية']);
        $this->assertEquals(1500, $row['الراتب الأساسي']);
        // A formula typed as a value stays a value.
        $this->assertSame('=HYPERLINK("http://x")', $row['ملاحظات']);

        // Each export in the log, with its filters and its number of rows.
        $events = DB::table('audit_events')->where('event', 'exports.downloaded')->where('actor_id', $manager->id)->get();
        $contexts = $events->map(fn ($e) => json_decode($e->context, true))->sortBy('rows')->values();
        $this->assertSame(['employees', 'employees'], $contexts->pluck('kind')->all());
        $this->assertSame([1, 2], $contexts->pluck('rows')->all());
        $this->assertEquals(['gender_id' => $this->itemId('gender', 'female')], $contexts[0]['filters']);
        $this->assertSame(['operations'], $events->pluck('category')->unique()->values()->all());
    }

    public function test_a_register_and_the_movements_summary_export_with_their_filters(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin());
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => ['occurred_on' => '2026-02-01', 'movement_type_id' => $this->itemId('movement_type', 'reward'), 'amount' => 250]])->assertCreated();
        $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => ['occurred_on' => '2025-02-01', 'movement_type_id' => $this->itemId('movement_type', 'reward'), 'amount' => 100]])->assertCreated();
        $this->postJson(self::API.'/records/attendance', ['employee_id' => $employee->id, 'values' => ['year' => 2026, 'month' => 2, 'planned_days' => 20, 'attended_days' => 19]])->assertCreated();

        $rows = $this->readXlsx($this->get(self::API.'/exports/records/movements?year=2026')->assertOk()->streamedContent());
        $this->assertCount(2, $rows);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame('مكافأة', $row['نوع الحركة']);
        $this->assertEquals(250, $row['المبلغ']);
        $this->assertSame('2026-02-01', $row['التاريخ']);
        $this->assertSame('معتمد', $row['حالة الاعتماد']);

        $attendance = $this->readXlsx($this->get(self::API.'/exports/records/attendance')->streamedContent());
        $this->assertEquals(5, array_combine($attendance[0], $attendance[1])['نسبة الغياب']);
        $this->get(self::API.'/exports/records/nothing')->assertNotFound();

        $summary = $this->readXlsx($this->get(self::API.'/exports/movements-summary?year=2026')->assertOk()->streamedContent());
        $row = array_combine($summary[0], $summary[1]);
        $this->assertEquals(250, $row['إجمالي المكافآت']);
        $this->assertSame('إيجابي', $row['تقييم']);

        // Data entry does not export.
        $this->actingAsStaff($this->staffUser())->get(self::API.'/exports/employees')->assertForbidden();
        $this->get(self::API.'/exports/records/movements')->assertForbidden();
    }
}
