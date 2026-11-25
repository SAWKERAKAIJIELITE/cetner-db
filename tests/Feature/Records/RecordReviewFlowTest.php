<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeMovement;
use App\Modules\Records\Models\RecordChangeSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** D-041: the records are entered, corrected and removed as the file is: reviewed, logged, never rewritten. */
class RecordReviewFlowTest extends TestCase
{
    private function reward(array $extra = []): array
    {
        return array_merge([
            'occurred_on' => '2026-03-10',
            'movement_type_id' => $this->itemId('movement_type', 'reward'),
            'description' => 'مكافأة تميز',
            'amount' => '١٥٠٠٠',
        ], $extra);
    }

    public function test_data_entry_adds_a_record_that_waits_for_the_supervisor(): void
    {
        $employee = $this->employeeFile();
        $entry = $this->staffUser();

        $set = $this->actingAsStaff($entry)->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => $this->reward(), 'note' => 'قرار 12'])
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'pending')
            ->assertJsonPath('data.record_type.value', 'movements')
            ->assertJsonPath('data.items.0.action.value', 'create')
            ->assertJsonPath('data.items.0.employee.id', $employee->id)
            ->assertJsonFragment(['field' => 'amount', 'label' => 'المبلغ', 'old_value' => null, 'old_display' => null, 'new_value' => '15000.00', 'new_display' => '15000.00'])
            ->assertJsonFragment(['field' => 'movement_type_id', 'new_display' => 'مكافأة'])
            ->json('data');

        $this->assertSame(0, EmployeeMovement::query()->count(), 'nothing is in the record before approval');
        $this->getJson(self::API.'/records/movements')->assertJsonCount(0, 'data');

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->getJson(self::API.'/record-changes')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items_count', 1)->assertJsonPath('data.0.can_decide', true)->assertJsonMissingPath('data.0.items');
        $this->postJson(self::API."/record-changes/{$set['id']}/approve")->assertOk()->assertJsonPath('data.status.value', 'approved');

        $record = EmployeeMovement::query()->sole();
        $this->assertSame($set['items'][0]['record_id'], $record->id, 'the id chosen at submission');
        $this->assertSame($entry->id, $record->created_by);
        $this->assertSame($supervisor->id, $record->approved_by);

        $this->getJson(self::API.'/records/movements?year=2026&month=3')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.full_name', 'أحمد محمد الخطيب')
            ->assertJsonPath('data.0.fields.amount.value', '15000.00')
            ->assertJsonPath('data.0.fields.movement_type_id.display', 'مكافأة')
            ->assertJsonPath('data.0.computed', ['year' => 2026, 'month' => 3])
            ->assertJsonPath('data.0.created_by.id', $entry->id);
        $this->getJson(self::API.'/records/movements?year=2025')->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('audit_events', ['event' => 'records.change_submitted']);
        $this->assertDatabaseHas('audit_events', ['event' => 'records.change_approved']);
    }

    public function test_a_correction_waits_beside_the_approved_value_and_the_supervisors_own_applies_at_once(): void
    {
        $record = $this->approvedMovement();
        $this->actingAsStaff($this->staffUser())->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['amount' => 20000]])
            ->assertCreated()->assertJsonPath('data.status.value', 'pending')
            ->assertJsonFragment(['field' => 'amount', 'old_value' => '15000.00', 'new_value' => '20000.00']);

        $this->getJson(self::API."/records/movements/{$record->id}")
            ->assertJsonPath('data.fields.amount.value', '15000.00')
            ->assertJsonPath('data.pending.0.action', 'update')
            ->assertJsonPath('data.pending.0.new_values', ['amount' => '20000.00']);

        // The supervisor corrects another field: at once, logged as self-approved.
        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['description' => 'مكافأة تميز سنوية']])
            ->assertCreated()->assertJsonPath('data.status.value', 'approved')->assertJsonPath('data.self_approved', true);
        $this->assertSame('مكافأة تميز سنوية', $record->refresh()->description);

        $this->getJson(self::API."/records/movements/{$record->id}/history")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action.value', 'create')->assertJsonPath('data.1.self_approved', true)
            ->assertJsonPath('data.1.fields.0.old_display', 'مكافأة تميز');
    }

    public function test_the_supervisor_neither_adds_nor_removes_records(): void
    {
        $record = $this->approvedMovement();
        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));

        $this->postJson(self::API.'/records/movements', ['employee_id' => $record->employee_id, 'values' => $this->reward()])->assertForbidden();
        $this->deleteJson(self::API."/records/movements/{$record->id}", ['note' => 'خطأ'])->assertForbidden();
        $this->postJson(self::API.'/records/movements/batch', ['changes' => [['action' => 'create', 'employee_id' => $record->employee_id, 'values' => $this->reward()]]])
            ->assertForbidden();

        $this->actingAsStaff($this->staffUser(StaffRole::GeneralManager));
        $this->getJson(self::API.'/records/movements')->assertOk();
        $this->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['amount' => 1]])->assertForbidden();
    }

    public function test_a_removal_needs_a_reason_and_marks_the_record_which_the_database_never_deletes(): void
    {
        $record = $this->approvedMovement();
        $entry = $this->staffUser();

        $this->actingAsStaff($entry)->deleteJson(self::API."/records/movements/{$record->id}")->assertUnprocessable()->assertJsonValidationErrors('note');
        $set = $this->deleteJson(self::API."/records/movements/{$record->id}", ['note' => 'أُدخلت مرتين'])->assertCreated()
            ->assertJsonPath('data.items.0.action.value', 'delete')
            ->assertJsonFragment(['field' => 'amount', 'old_value' => '15000.00', 'new_value' => null])
            ->json('data.id');
        $this->assertNull($record->refresh()->deleted_at, 'still there until approved');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/record-changes/{$set}/approve")->assertOk();
        $this->assertNotNull($record->refresh()->deleted_at);
        $this->assertSame($entry->id, $record->deleted_by);

        $this->getJson(self::API.'/records/movements')->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/records/movements?deleted=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.deleted', true);
        $this->actingAsStaff($entry)->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['amount' => 1]])->assertUnprocessable();

        foreach ([
            fn () => DB::table('employee_movements')->where('id', $record->id)->delete(),
            fn () => DB::table('employee_movements')->where('id', $record->id)->update(['amount' => 1]),
            fn () => DB::table('record_change_items')->delete(),
            fn () => DB::table('record_change_sets')->where('id', $set)->update(['status' => 'pending', 'reviewed_at' => null, 'reviewed_by' => null]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the history was rewritten');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nobody_approves_their_own_and_a_refusal_needs_a_reason(): void
    {
        $employee = $this->employeeFile();
        $entry = $this->staffUser(attributes: []);
        $set = $this->actingAsStaff($entry)->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => $this->reward()])->json('data.id');

        $this->postJson(self::API."/record-changes/{$set}/approve")->assertForbidden();

        $supervisor = $this->staffUser(StaffRole::Supervisor);
        $this->actingAsStaff($supervisor)->postJson(self::API."/record-changes/{$set}/reject")->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson(self::API."/record-changes/{$set}/withdraw")->assertForbidden();
        $this->postJson(self::API."/record-changes/{$set}/reject", ['note' => 'المبلغ غير صحيح'])->assertOk()->assertJsonPath('data.status.value', 'rejected');
        $this->postJson(self::API."/record-changes/{$set}/approve")->assertUnprocessable();

        $this->actingAsStaff($entry)->getJson(self::API.'/record-changes?status=rejected&mine=1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.review_note', 'المبلغ غير صحيح');

        $again = $this->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => $this->reward()])->json('data.id');
        $this->postJson(self::API."/record-changes/{$again}/withdraw")->assertOk()->assertJsonPath('data.status.value', 'withdrawn');
        $this->assertSame(0, EmployeeMovement::query()->count());
    }

    public function test_a_correction_against_values_that_changed_since_is_refused(): void
    {
        $record = $this->approvedMovement();
        $this->actingAsStaff($this->staffUser());
        $first = $this->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['amount' => 20000]])->json('data.id');
        $this->actingAsStaff($this->staffUser());
        $second = $this->patchJson(self::API."/records/movements/{$record->id}", ['values' => ['amount' => 25000, 'notes' => 'x']])->json('data.id');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $this->postJson(self::API."/record-changes/{$first}/approve")->assertOk();
        $this->postJson(self::API."/record-changes/{$second}/approve")->assertUnprocessable()
            ->assertJsonPath('errors.change_set.0', __('records.change_stale', ['positions' => '1']));
        $this->assertSame('20000.00', $record->refresh()->amount);
        $this->assertSame('pending', RecordChangeSet::query()->findOrFail($second)->status->value);
    }

    public function test_the_review_queue_finds_an_employees_changes(): void
    {
        $a = $this->employeeFile();
        $b = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());
        $this->postJson(self::API.'/records/movements', ['employee_id' => $a->id, 'values' => $this->reward()])->assertCreated();
        $this->postJson(self::API.'/records/conduct', ['employee_id' => $b->id, 'values' => [
            'occurred_on' => '2026-02-01', 'conduct_type_id' => $this->itemId('conduct_type', 'thanks'),
        ]])->assertCreated();

        $this->getJson(self::API."/record-changes?employee_id={$a->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.record_type.value', 'movements');
        $this->getJson(self::API.'/record-changes?record_type=conduct')->assertJsonCount(1, 'data');
    }

    private function approvedMovement(): EmployeeMovement
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/movements', ['employee_id' => $employee->id, 'values' => $this->reward()])
            ->assertCreated()->assertJsonPath('data.status.value', 'approved');

        return EmployeeMovement::query()->latest('created_at')->firstOrFail();
    }
}
