<?php

namespace Tests\Feature\Api\V1;

use App\Models\Expense;
use App\Models\User;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseMemberService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_cannot_update_house_or_manage_categories(): void
    {
        [$owner, $member, $house] = $this->seedHouseWithMember();

        Sanctum::actingAs($member);

        $this->putJson("/api/v1/houses/{$house->id}", [
            'name' => 'Hijacked',
        ])->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Only the house owner can perform this action.');

        $this->postJson("/api/v1/houses/{$house->id}/categories", [
            'name' => 'Water',
            'code' => 'water',
        ])->assertForbidden();

        $this->postJson("/api/v1/houses/{$house->id}/months/2026-08/close")
            ->assertForbidden();
    }

    public function test_stranger_cannot_view_expense_or_allocations(): void
    {
        [$owner, $member, $house] = $this->seedHouseWithMember();
        $expense = $this->createConfirmedExpense($house, $owner);

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/expenses/{$expense->id}")
            ->assertForbidden();

        $this->getJson("/api/v1/expenses/{$expense->id}/allocations")
            ->assertForbidden();

        $this->getJson("/api/v1/houses/{$house->id}/settlement?month=2026-08")
            ->assertForbidden();
    }

    public function test_member_can_view_but_cannot_cancel_expense(): void
    {
        [$owner, $member, $house] = $this->seedHouseWithMember();
        $expense = $this->createConfirmedExpense($house, $owner);

        Sanctum::actingAs($member);

        $this->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $expense->id);

        $this->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the house owner can cancel expenses.');
    }

    public function test_member_cannot_edit_confirmed_expense(): void
    {
        [$owner, $member, $house] = $this->seedHouseWithMember();
        $expense = $this->createConfirmedExpense($house, $owner);

        Sanctum::actingAs($member);

        $this->putJson("/api/v1/expenses/{$expense->id}", [
            'amount' => '1.00',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only the house owner can edit confirmed expenses.');
    }

    public function test_member_cannot_create_rules_for_category(): void
    {
        [$owner, $member, $house] = $this->seedHouseWithMember();

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Gas',
            'code' => 'gas',
        ]);

        Sanctum::actingAs($member);

        $this->postJson("/api/v1/categories/{$category->id}/rules", [
            'rule_type' => 'per_day',
            'configuration' => [],
            'effective_from' => '2026-01-01',
        ])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/houses')
            ->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Models\House}
     */
    private function seedHouseWithMember(): array
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $member = User::factory()->create(['name' => 'Member']);
        $house = app(HouseService::class)->create($owner, ['name' => 'Family House']);

        app(HouseMemberService::class)->add($house, $owner, [
            'user_id' => $member->id,
            'joined_at' => '2026-08-01 00:00:00',
        ]);

        return [$owner, $member, $house->fresh()];
    }

    private function createConfirmedExpense(\App\Models\House $house, User $owner): Expense
    {
        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Security',
            'code' => 'security',
        ]);

        app(AllocationRuleService::class)->create($category, $owner, [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ]);

        $expense = app(ExpenseService::class)->create($house, $owner, [
            'expense_category_id' => $category->id,
            'title' => 'Security fee',
            'amount' => '4000.00',
            'expense_date' => '2026-08-15',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $owner->id,
        ]);

        return app(ExpenseService::class)->confirm($expense, $owner);
    }
}
