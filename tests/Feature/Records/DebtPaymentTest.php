<?php

namespace Tests\Feature\Records;

use App\Modules\Identity\Enums\StaffRole;
use App\Modules\Records\Models\EmployeeDebt;
use App\Modules\Records\Models\EmployeeDebtPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** D-045 (owner, 25 Nov): each payment of a debt is an entry of its own, reviewed, never edited away. */
class DebtPaymentTest extends TestCase
{
    private function debt(array $values = []): EmployeeDebt
    {
        $employee = $this->employeeFile();
        $this->actingAsStaff($this->admin())->postJson(self::API.'/records/debts', ['employee_id' => $employee->id, 'values' => $values + [
            'creditor' => 'الجمعية', 'amount' => 200000, 'paid_amount' => 5000, 'monthly_installment' => 50000, 'due_on' => '2100-01-01',
        ]])->assertCreated();

        return EmployeeDebt::query()->latest('created_at')->firstOrFail();
    }

    private function pay(EmployeeDebt $debt, float $amount, array $extra = []): TestResponse
    {
        return $this->postJson(self::API.'/records/payments', $extra + ['values' => [
            'debt_id' => $debt->id, 'paid_on' => '2026-11-01', 'amount' => $amount, 'payment_method_id' => $this->itemId('payment_method', 'اقتطاع من الراتب'),
        ]]);
    }

    public function test_a_payment_waits_for_review_and_then_is_the_debts(): void
    {
        $debt = $this->debt();

        // The employee is the debt's: neither the id nor the number is needed.
        $set = $this->actingAsStaff($this->staffUser())->pay($debt, 50000, ['note' => 'قسط تشرين الثاني'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.items.0.employee.id', $debt->employee_id)
            ->assertJsonFragment(['field' => 'debt_id', 'new_display' => 'الجمعية (200000.00)'])
            ->json('data.id');
        $this->assertSame('195000.00', $debt->refresh()->getAttribute('remaining_amount'), 'not paid until approved');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor))->postJson(self::API."/record-changes/{$set}/approve")->assertOk();

        $debt->refresh();
        $this->assertSame('50000.00', $debt->getAttribute('payments_total'));
        $this->assertSame('145000.00', $debt->getAttribute('remaining_amount'));
        $this->getJson(self::API."/records/debts/{$debt->id}")->assertJsonPath('data.computed.paid_total', '55000.00')
            ->assertJsonPath('data.computed.installments_left', 3);
        $this->getJson(self::API."/records/payments?debt_id={$debt->id}")->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.fields.payment_method_id.display', 'اقتطاع من الراتب');
        $this->getJson(self::API."/employees/{$debt->employee_id}")->assertJsonPath('data.computed.total_remaining', '145000.00')
            ->assertJsonPath('data.computed.open_debts', 1)->assertJsonPath('data.computed.overdue_debts', 0);
    }

    public function test_a_payment_pays_at_most_what_is_left(): void
    {
        $debt = $this->debt();
        $this->actingAsStaff($this->staffUser());

        $this->pay($debt, 195001)->assertUnprocessable()->assertJsonValidationErrors(['values.amount' => __('records.payment_over_remaining', ['remaining' => '195000.00'])]);

        // Two waiting payments that together pay too much: the second approved is refused.
        $first = $this->pay($debt, 150000)->json('data.id');
        $this->actingAsStaff($this->staffUser());
        $second = $this->pay($debt, 100000)->json('data.id');

        $this->actingAsStaff($this->staffUser(StaffRole::Supervisor));
        $this->postJson(self::API."/record-changes/{$first}/approve")->assertOk();
        $this->postJson(self::API."/record-changes/{$second}/approve")->assertUnprocessable()
            ->assertJsonValidationErrors(['change_set' => __('records.payments_over_debt')]);
        $this->assertSame('45000.00', $debt->refresh()->getAttribute('remaining_amount'));

        // Nor is the debt corrected below what was paid of it.
        $this->actingAsStaff($this->admin())->patchJson(self::API."/records/debts/{$debt->id}", ['values' => ['amount' => 100000]])->assertUnprocessable()
            ->assertJsonValidationErrors(['values.amount' => __('records.debt_under_payments', ['paid' => '155000.00'])]);
    }

    public function test_a_payment_is_never_edited_only_removed_and_a_paid_debt_keeps_its_payments(): void
    {
        $debt = $this->debt();
        $this->actingAsStaff($this->admin())->pay($debt, 20000)->assertCreated()->assertJsonPath('data.status', 'approved');
        $payment = EmployeeDebtPayment::query()->sole();

        $this->patchJson(self::API."/records/payments/{$payment->id}", ['values' => ['amount' => 1]])->assertUnprocessable()
            ->assertJsonValidationErrors(['record' => __('records.payment_not_corrected')]);
        $this->deleteJson(self::API."/records/debts/{$debt->id}", ['reason' => 'خطأ'])->assertUnprocessable()
            ->assertJsonValidationErrors(['record' => __('records.debt_has_payments')]);

        $this->deleteJson(self::API."/records/payments/{$payment->id}", ['reason' => 'دفعة مكررة'])->assertCreated();
        $this->assertSame('0.00', $debt->refresh()->getAttribute('payments_total'));
        $this->getJson(self::API.'/records/payments?deleted=1')->assertJsonCount(1, 'data');
        $this->deleteJson(self::API."/records/debts/{$debt->id}", ['reason' => 'خطأ'])->assertCreated();

        foreach ([
            fn () => DB::table('employee_debt_payments')->where('id', $payment->id)->update(['amount' => 1]),
            fn () => DB::table('employee_debts')->where('id', $debt->id)->update(['payments_total' => 0]),
        ] as $change) {
            try {
                DB::transaction($change);
                $this->fail('the database accepted it');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_payment_is_of_a_kept_debt_of_its_employee(): void
    {
        $debt = $this->debt();
        $other = $this->employeeFile();
        $this->actingAsStaff($this->staffUser());

        $this->pay($debt, 1000, ['employee_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors(['values.debt_id' => __('records.debt_unknown')]);
        $this->postJson(self::API.'/records/payments', ['values' => ['debt_id' => '0195a000-0000-7000-8000-000000000000', 'paid_on' => '2026-11-01', 'amount' => 1, 'payment_method_id' => $this->itemId('payment_method', 'سداد نقدي/إشعار بنكي')]])
            ->assertUnprocessable();

        try {
            DB::transaction(fn () => DB::table('employee_debt_payments')->insert([
                'id' => '0195a000-0000-7000-8000-000000000001', 'employee_id' => $other->id, 'debt_id' => $debt->id, 'paid_on' => '2026-11-01',
                'amount' => 1, 'payment_method_id' => $this->itemId('payment_method', 'سداد نقدي/إشعار بنكي'),
                'created_by' => $this->admin()->id, 'approved_by' => $this->admin()->id, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('a payment of another employee\'s debt');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
