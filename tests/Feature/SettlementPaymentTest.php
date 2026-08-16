<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Enums\HouseMemberRole;
use App\Enums\SettlementPaymentStatus;
use App\Exceptions\DomainException;
use App\Models\HouseMember;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseService;
use App\Services\Settlement\OverallOwingService;
use App\Services\Settlement\SettlementPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettlementPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payment_does_not_change_overall_owing(): void
    {
        $setup = $this->seedOwingHouse();

        app(SettlementPaymentService::class)->record($setup['house'], $setup['member'], [
            'to_user_id' => $setup['owner']->id,
            'amount' => '3000.00',
            'year' => 2026,
            'month' => 8,
            'note' => 'Partial',
        ]);

        $plan = app(OverallOwingService::class)->forHouse($setup['house'], $setup['owner']);
        $memberBalance = $plan->balances->firstWhere('userId', $setup['member']->id);

        $this->assertSame('-5000.00', $memberBalance->balance);
        $this->assertSame('5000.00', $plan->transfers->first()->amount);
    }

    public function test_confirmed_payment_reduces_overall_owing(): void
    {
        $setup = $this->seedOwingHouse();
        $payments = app(SettlementPaymentService::class);

        $payment = $payments->record($setup['house'], $setup['member'], [
            'to_user_id' => $setup['owner']->id,
            'amount' => '3000.00',
            'year' => 2026,
            'month' => 8,
        ]);

        $payments->confirm($payment, $setup['owner']);

        $plan = app(OverallOwingService::class)->forHouse($setup['house'], $setup['owner']);
        $memberBalance = $plan->balances->firstWhere('userId', $setup['member']->id);
        $ownerBalance = $plan->balances->firstWhere('userId', $setup['owner']->id);

        $this->assertSame('-2000.00', $memberBalance->balance);
        $this->assertSame('2000.00', $ownerBalance->balance);
        $this->assertCount(1, $plan->transfers);
        $this->assertSame('2000.00', $plan->transfers->first()->amount);
        $this->assertSame(SettlementPaymentStatus::Confirmed, $payment->fresh()->status);

        $monthPlan = app(\App\Services\Settlement\SettlementService::class)->forMonth(
            $setup['house'],
            $setup['owner'],
            2026,
            8
        );
        $this->assertSame('2000.00', $monthPlan->transfers->first()->amount);
        $this->assertSame(
            '-2000.00',
            $monthPlan->balances->firstWhere('userId', $setup['member']->id)->balance
        );

        $otherMonth = app(\App\Services\Settlement\SettlementService::class)->forMonth(
            $setup['house'],
            $setup['owner'],
            2026,
            9
        );
        $this->assertTrue($otherMonth->transfers->isEmpty());
    }

    public function test_payment_for_one_month_does_not_change_another_month(): void
    {
        $setup = $this->seedOwingHouse();
        $payments = app(SettlementPaymentService::class);

        $payment = $payments->record($setup['house'], $setup['member'], [
            'to_user_id' => $setup['owner']->id,
            'amount' => '3000.00',
            'year' => 2026,
            'month' => 8,
        ]);
        $payments->confirm($payment, $setup['owner']);

        $august = app(\App\Services\Settlement\SettlementService::class)->forMonth(
            $setup['house'],
            $setup['owner'],
            2026,
            8
        );
        $this->assertSame('2000.00', $august->transfers->first()->amount);

        // Pure expense summary for August still shows full debt before payment layer.
        $raw = app(\App\Services\Monthly\MonthlySettlementService::class)->summarize(
            $setup['house'],
            $setup['owner'],
            2026,
            8
        );
        $this->assertSame(
            '-5000.00',
            $raw->balances->firstWhere('userId', $setup['member']->id)->balance
        );
    }

    public function test_overpayment_stays_between_payer_and_payee(): void
    {
        $masood = User::factory()->create(['name' => 'Masood']);
        $maqsood = User::factory()->create(['name' => 'Maqsood']);
        $fakhar = User::factory()->create(['name' => 'Fakhar']);

        $house = app(HouseService::class)->create($maqsood, ['name' => 'Family House', 'currency' => 'PKR']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        foreach ([$masood, $fakhar] as $user) {
            HouseMember::factory()->create([
                'house_id' => $house->id,
                'user_id' => $user->id,
                'role' => HouseMemberRole::Member,
                'joined_at' => '2026-08-01 00:00:00',
            ]);
        }

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $maqsood->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        foreach ([$masood->id, $maqsood->id, $fakhar->id] as $userId) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'status' => AvailabilityStatus::Available,
                'created_by' => $maqsood->id,
            ]);
        }

        $category = app(ExpenseCategoryService::class)->create($house, $maqsood, [
            'name' => 'Shared',
            'code' => 'shared',
        ]);
        app(AllocationRuleService::class)->create($category, $maqsood, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $expenses = app(ExpenseService::class);
        $expense = $expenses->create($house, $maqsood, [
            'expense_category_id' => $category->id,
            'title' => 'August shared',
            'amount' => '9000.00',
            'expense_date' => '2026-08-20',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $maqsood->id,
        ]);
        $expenses->confirm($expense, $maqsood);

        // Masood owes Maqsood 3000, Fakhar owes Maqsood 3000.
        // Masood overpays Maqsood 5000 → 2000 credit should be Maqsood → Masood, not Fakhar.
        $payments = app(SettlementPaymentService::class);
        $payment = $payments->record($house, $masood, [
            'to_user_id' => $maqsood->id,
            'amount' => '5000.00',
            'year' => 2026,
            'month' => 8,
        ]);
        $payments->confirm($payment, $maqsood);

        $plan = app(OverallOwingService::class)->forHouse($house, $maqsood);

        $this->assertTrue(
            $plan->transfers->contains(
                fn ($t) => $t->fromUserId === $maqsood->id
                    && $t->toUserId === $masood->id
                    && $t->amount === '2000.00'
            )
        );
        $this->assertTrue(
            $plan->transfers->contains(
                fn ($t) => $t->fromUserId === $fakhar->id
                    && $t->toUserId === $maqsood->id
                    && $t->amount === '3000.00'
            )
        );
        $this->assertFalse(
            $plan->transfers->contains(
                fn ($t) => $t->fromUserId === $fakhar->id && $t->toUserId === $masood->id
            )
        );
        $this->assertSame('-3000.00', $plan->balances->firstWhere('userId', $fakhar->id)->balance);
        $this->assertSame('2000.00', $plan->balances->firstWhere('userId', $masood->id)->balance);
    }

    public function test_only_recipient_can_confirm_payment(): void
    {
        $setup = $this->seedOwingHouse();
        $payments = app(SettlementPaymentService::class);

        $payment = $payments->record($setup['house'], $setup['member'], [
            'to_user_id' => $setup['owner']->id,
            'amount' => '1000.00',
            'year' => 2026,
            'month' => 8,
        ]);

        $this->expectException(DomainException::class);
        $payments->confirm($payment, $setup['member']);
    }

    public function test_dashboard_can_record_and_confirm_payment(): void
    {
        $setup = $this->seedOwingHouse();

        $this->actingAs($setup['member']);

        Livewire::test('dashboard')
            ->set('houseId', $setup['house']->id)
            ->set('month', '2026-08')
            ->set('paymentMonth', '2026-08')
            ->set('paymentToUserId', (string) $setup['owner']->id)
            ->set('paymentAmount', '3000')
            ->set('paymentNote', 'Bank transfer')
            ->call('recordSettlementPayment')
            ->assertSee('Waiting for the recipient')
            ->assertSee('3000.00');

        $paymentId = \App\Models\SettlementPayment::query()->firstOrFail()->id;

        $this->actingAs($setup['owner']);

        Livewire::test('dashboard')
            ->set('houseId', $setup['house']->id)
            ->set('month', '2026-08')
            ->call('confirmSettlementPayment', $paymentId)
            ->assertSee('Payment confirmed')
            ->assertSee('2000.00');
    }

    /**
     * Owner paid 10000 fixed 50/50 → member owes 5000.
     *
     * @return array{
     *     house: \App\Models\House,
     *     owner: User,
     *     member: User
     * }
     */
    private function seedOwingHouse(): array
    {
        $owner = User::factory()->create(['name' => 'Masood']);
        $member = User::factory()->create(['name' => 'Maqsood']);

        $house = app(HouseService::class)->create($owner, ['name' => 'Family House', 'currency' => 'PKR']);
        MemberAvailabilityPeriod::query()->where('house_id', $house->id)->delete();

        HouseMember::factory()->create([
            'house_id' => $house->id,
            'user_id' => $member->id,
            'role' => HouseMemberRole::Member,
            'joined_at' => '2026-08-01 00:00:00',
        ]);

        HouseMember::query()
            ->where('house_id', $house->id)
            ->where('user_id', $owner->id)
            ->update(['joined_at' => '2026-08-01 00:00:00']);

        foreach ([$owner->id, $member->id] as $userId) {
            MemberAvailabilityPeriod::factory()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => '2026-08-01',
                'end_date' => '2026-08-31',
                'status' => AvailabilityStatus::Available,
                'created_by' => $owner->id,
            ]);
        }

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Shared',
            'code' => 'shared',
        ]);

        app(AllocationRuleService::class)->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $expenses = app(ExpenseService::class);
        $expense = $expenses->create($house, $owner, [
            'expense_category_id' => $category->id,
            'title' => 'August shared',
            'amount' => '10000.00',
            'expense_date' => '2026-08-20',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $owner->id,
        ]);
        $expenses->confirm($expense, $owner);

        return [
            'house' => $house->fresh(),
            'owner' => $owner,
            'member' => $member,
        ];
    }
}
