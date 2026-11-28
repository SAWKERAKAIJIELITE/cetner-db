<?php

namespace Tests\Feature\Documents;

use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** D-049: the employees' documents — upload, review, replacement, removal, download and completeness. */
class DocumentTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
    }

    private function upload(string $employeeId, string $kind, ?UploadedFile $file = null, array $extra = [])
    {
        return $this->post(self::API."/employees/{$employeeId}/documents", array_merge([
            'kind' => $kind,
            'file' => $file ?? UploadedFile::fake()->image('صورة.jpg', 40, 40),
        ], $extra), ['Accept' => 'application/json']);
    }

    private static function pdf(string $name = 'هوية.pdf', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF.$extra);
    }

    public function test_the_kinds_are_the_workbooks_thirteen_and_the_conduct_records(): void
    {
        $kinds = $this->actingAsStaff($this->staffUser())->getJson(self::API.'/document-kinds')->assertOk()->assertJsonCount(14, 'data')->json('data');

        $this->assertSame('identity_copy', $kinds[0]['key']);
        $this->assertSame('رابط الهوية', $kinds[0]['workbook_field']);
        $this->assertSame('AL', $kinds[0]['workbook_column']);
        $this->assertSame('conduct_document', $kinds[13]['key']);
        $this->assertTrue($kinds[13]['on_conduct_record']);
        $this->assertSame(['identity_copy', 'security_form', 'portrait', 'family_statement', 'practical_certificate'],
            collect($kinds)->where('counts_for_completeness', true)->pluck('key')->all());
    }

    public function test_data_entrys_upload_waits_for_a_reviewer_and_then_counts_for_completeness(): void
    {
        $employee = $this->employeeFile();
        $before = (float) $employee->completeness;
        $entry = $this->staffUser();

        $id = $this->actingAsStaff($entry)->upload($employee->id, 'portrait')->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.state', 'pending')
            ->assertJsonPath('data.mime', 'image/jpeg')->assertJsonPath('data.original_name', 'صورة.jpg')
            ->assertJsonPath('data.can_withdraw', true)->assertJsonPath('data.can_decide', false)
            ->json('data.id');

        $document = EmployeeDocument::query()->findOrFail($id);
        Storage::disk('documents')->assertExists($document->path);
        $this->assertSame("documents/{$employee->id}/{$id}.jpg", $document->path);
        $this->assertSame($before, (float) $employee->refresh()->completeness);

        // Data entry may not decide; the queue and the badges show it.
        $this->postJson(self::API."/documents/{$id}/approve")->assertForbidden();
        $this->getJson(self::API.'/badges')->assertJsonPath('data.mine.pending', 1);
        $this->getJson(self::API."/employees/{$employee->id}/documents")->assertOk()
            ->assertJsonPath('data.attached', 0)->assertJsonPath('data.total', 13)
            ->assertJsonPath('data.slots.5.kind.key', 'portrait')->assertJsonPath('data.slots.5.current', null)
            ->assertJsonPath('data.slots.5.pending.0.id', $id);

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/badges')->assertJsonPath('data.reviews.documents', 1)->assertJsonPath('data.reviews.total', 1);
        $this->getJson(self::API.'/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_decide', true)
            ->assertJsonPath('data.0.employee.id', $employee->id);

        // The supervisor reviews but does not upload.
        $this->upload($employee->id, 'cv', self::pdf())->assertForbidden();

        $this->postJson(self::API."/documents/{$id}/approve", ['note' => 'واضحة'])->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.state', 'current')->assertJsonPath('data.self_approved', false);

        $this->assertSame(round(6 * 100 / 73, 2), (float) $employee->refresh()->completeness);
        $this->assertNotContains('portrait', $employee->missing_fields['annual'] ?? []);
        $this->getJson(self::API."/employees/{$employee->id}")->assertJsonPath('data.computed.documents_attached', 1)
            ->assertJsonPath('data.completeness_filled', 6);
        $this->assertDatabaseHas('audit_events', ['event' => 'documents.uploaded', 'actor_id' => $entry->id]);
        $this->assertDatabaseHas('audit_events', ['event' => 'documents.approved', 'actor_id' => $supervisor->id]);
    }

    public function test_a_reviewers_upload_replaces_the_current_file_and_the_old_one_stays_in_the_history(): void
    {
        $employee = $this->employeeFile();
        $admin = $this->admin();

        $first = $this->actingAsStaff($admin)->upload($employee->id, 'identity_copy', self::pdf('old.pdf'))->assertCreated()
            ->assertJsonPath('data.state', 'current')->assertJsonPath('data.self_approved', true)->json('data.id');

        // The same content again is refused.
        $this->upload($employee->id, 'identity_copy', self::pdf('again.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertCount(1, Storage::disk('documents')->allFiles());

        $second = $this->upload($employee->id, 'identity_copy', self::pdf('new.pdf', '% v2'))->assertCreated()->json('data.id');

        $this->getJson(self::API."/documents/{$first}")->assertJsonPath('data.state', 'superseded')
            ->assertJsonPath('data.superseded_by_id', $second)->assertJsonPath('data.content_available', true);
        $this->getJson(self::API."/employees/{$employee->id}/documents")->assertJsonPath('data.attached', 1)
            ->assertJsonPath('data.slots.0.current.id', $second);
        $this->getJson(self::API."/employees/{$employee->id}/documents/history")->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)->assertJsonPath('data.1.id', $first);

        // A superseded file is still readable.
        $this->get(self::API."/documents/{$first}/download")->assertOk();
        $this->assertSame(1, EmployeeDocument::query()->current()->where('employee_id', $employee->id)->count());
    }

    public function test_a_refused_upload_goes_back_with_its_reason_and_its_content_is_not_kept(): void
    {
        $employee = $this->employeeFile(['first_name' => 'سامر']);
        $entry = $this->staffUser();
        $id = $this->actingAsStaff($entry)->upload($employee->id, 'family_statement')->assertCreated()->json('data.id');
        $path = EmployeeDocument::query()->findOrFail($id)->path;

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $this->postJson(self::API."/documents/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson(self::API."/documents/{$id}/reject", ['reason' => 'الصورة غير واضحة'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.content_available', false)
            ->assertJsonPath('data.review_note', 'الصورة غير واضحة');
        $this->postJson(self::API."/documents/{$id}/approve")->assertUnprocessable();

        Storage::disk('documents')->assertMissing($path);
        $this->get(self::API."/documents/{$id}/download")->assertStatus(410);
        $this->assertDatabaseHas('employee_documents', ['id' => $id, 'status' => 'rejected']);

        $this->actingAsStaff($entry)->getJson(self::API.'/notifications')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.details.area', 'documents')->assertJsonPath('data.0.details.change_set_id', $id)
            ->assertJsonPath('data.0.details.reason', 'الصورة غير واضحة');
    }

    public function test_the_uploader_alone_takes_back_a_waiting_upload(): void
    {
        $employee = $this->employeeFile();
        $entry = $this->staffUser();
        $id = $this->actingAsStaff($entry)->upload($employee->id, 'cv', self::pdf())->assertCreated()->json('data.id');
        $this->upload($employee->id, 'cv', self::pdf('cv-again.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->actingAsStaff($this->staffUser())->postJson(self::API."/documents/{$id}/withdraw")->assertForbidden();
        $this->actingAsStaff($entry)->postJson(self::API."/documents/{$id}/withdraw")->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')->assertJsonPath('data.content_available', false);
        Storage::disk('documents')->assertMissing(EmployeeDocument::query()->findOrFail($id)->path);
        $this->assertDatabaseHas('audit_events', ['event' => 'documents.withdrawn']);

        // Now the same file may be sent again.
        $this->upload($employee->id, 'cv', self::pdf('cv-again.pdf'))->assertCreated();
    }

    public function test_the_type_is_read_from_the_content_and_the_size_is_limited(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());

        $this->upload($employee->id, 'cv', UploadedFile::fake()->createWithContent('cv.pdf', '<html><script>alert(1)</script></html>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($employee->id, 'cv', UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($employee->id, 'nothing')->assertUnprocessable()->assertJsonValidationErrors('kind');

        // A PDF named like an image is kept as the PDF it is.
        $this->upload($employee->id, 'cv', self::pdf('cv.png'))->assertCreated()
            ->assertJsonPath('data.mime', 'application/pdf')->assertJsonPath('data.original_name', 'cv.pdf');
        // Nothing refused was kept.
        $this->assertCount(1, Storage::disk('documents')->allFiles());
    }

    public function test_the_administrator_alone_removes_a_current_document_with_a_reason(): void
    {
        $employee = $this->employeeFile();
        $admin = $this->admin();
        $id = $this->actingAsStaff($admin)->upload($employee->id, 'security_form', self::pdf())->assertCreated()->json('data.id');
        $this->assertSame(round(6 * 100 / 73, 2), (float) $employee->refresh()->completeness);

        $this->actingAsStaff($this->staffUser())->deleteJson(self::API."/documents/{$id}", ['reason' => 'ملف موظف آخر'])->assertForbidden();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->deleteJson(self::API."/documents/{$id}", ['reason' => 'ملف موظف آخر'])->assertForbidden();

        $this->actingAsStaff($admin)->deleteJson(self::API."/documents/{$id}")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->deleteJson(self::API."/documents/{$id}", ['reason' => 'ملف موظف آخر'])->assertOk()
            ->assertJsonPath('data.state', 'removed')->assertJsonPath('data.removal_reason', 'ملف موظف آخر')
            ->assertJsonPath('data.content_available', false);
        $this->deleteJson(self::API."/documents/{$id}", ['reason' => 'مرة ثانية'])->assertUnprocessable();

        $this->assertSame(round(5 * 100 / 73, 2), (float) $employee->refresh()->completeness);
        $this->assertContains('security_form', $employee->missing_fields['once']);
        $this->assertSame([], Storage::disk('documents')->allFiles());
        $this->assertDatabaseHas('audit_events', ['event' => 'documents.removed', 'actor_id' => $admin->id]);
    }

    public function test_a_download_is_served_safely_and_logged_as_access(): void
    {
        $employee = $this->employeeFile();
        $id = $this->actingAsStaff($this->admin())->upload($employee->id, 'cv', self::pdf('سيرة ذاتية.pdf'))->assertCreated()->json('data.id');
        $reader = $this->staffUser(StaffRole::Supervisor);

        $response = $this->actingAsStaff($reader)->get(self::API."/documents/{$id}/download")->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame(self::PDF, $response->streamedContent());

        $inline = $this->get(self::API."/documents/{$id}/download?inline=1")->assertOk();
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));

        $this->assertSame(2, DB::table('audit_events')->where('event', 'documents.downloaded')->where('actor_id', $reader->id)->where('category', 'security')->count());
        $this->assertSame('security', AuditTrail::categoryOf('documents.downloaded'));

        Role::query()->where('slug', 'supervisor')->first()->permissions()->detach(Permission::query()->where('slug', 'documents.view')->value('id'));
        $this->actingAsStaff($reader->refresh())->get(self::API."/documents/{$id}/download")->assertForbidden();
    }

    public function test_a_conduct_records_document_is_on_its_record(): void
    {
        $employee = $this->employeeFile();
        $other = $this->employeeFile();
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/conduct', ['employee_id' => $employee->id, 'values' => [
            'occurred_on' => '2026-05-01', 'conduct_type_id' => $this->itemId('conduct_type', 'thanks'),
        ]])->assertCreated();
        $record = (string) DB::table('employee_conduct_records')->where('employee_id', $employee->id)->value('id');

        $this->actingAsStaff($this->staffUser());
        $this->upload($employee->id, 'conduct_document', self::pdf())->assertUnprocessable()->assertJsonValidationErrors('conduct_record_id');
        $this->upload($employee->id, 'cv', self::pdf(), ['conduct_record_id' => $record])->assertUnprocessable()->assertJsonValidationErrors('conduct_record_id');
        $this->upload($other->id, 'conduct_document', self::pdf(), ['conduct_record_id' => $record])->assertUnprocessable()->assertJsonValidationErrors('conduct_record_id');

        $id = $this->upload($employee->id, 'conduct_document', self::pdf(), ['conduct_record_id' => $record])->assertCreated()
            ->assertJsonPath('data.conduct_record_id', $record)->json('data.id');
        $this->getJson(self::API."/records/conduct/{$record}")->assertJsonPath('data.document', ['current' => null, 'pending' => 1]);

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/documents/{$id}/approve")->assertOk();
        $this->getJson(self::API."/records/conduct/{$record}")->assertJsonPath('data.document.current.id', $id)->assertJsonPath('data.document.pending', 0);
        $this->getJson(self::API."/employees/{$employee->id}/documents")->assertJsonPath('data.attached', 0)->assertJsonPath('data.conduct.0.id', $id);

        // Not on the other records.
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => ['amount' => 100]])->assertCreated();
        $this->assertArrayNotHasKey('document', $this->getJson(self::API.'/records/debts')->json('data.0'));
    }

    public function test_a_document_counts_as_the_field_guide_says(): void
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin())->upload($employee->id, 'cv', self::pdf())->assertCreated();
        $this->assertSame(round(5 * 100 / 73, 2), (float) $employee->refresh()->completeness);

        $this->putJson(self::API.'/field-definitions/cv', ['counts_for_completeness' => true])->assertOk()->assertJsonPath('data.type', 'document');
        $this->assertSame(round(6 * 100 / 74, 2), (float) $employee->refresh()->completeness);
        $this->getJson(self::API.'/document-kinds')->assertJsonPath('data.10.key', 'cv')->assertJsonPath('data.10.counts_for_completeness', true);
        // The documents are not fields of the file.
        $this->assertArrayNotHasKey('cv', $this->getJson(self::API."/employees/{$employee->id}")->json('data.fields'));
    }

    public function test_an_upload_is_never_deleted_nor_changed(): void
    {
        $employee = $this->employeeFile();
        $id = $this->actingAsStaff($this->admin())->upload($employee->id, 'cv', self::pdf())->assertCreated()->json('data.id');

        foreach ([
            fn () => DB::table('employee_documents')->where('id', $id)->delete(),
            fn () => DB::table('employee_documents')->where('id', $id)->update(['path' => 'elsewhere.pdf']),
            fn () => DB::table('employee_documents')->where('id', $id)->update(['status' => 'rejected', 'review_note' => 'x']),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The change went through.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('employee_documents', $e->getMessage());
            }
        }
    }
}
