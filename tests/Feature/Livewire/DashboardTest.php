<?php

namespace Tests\Feature\Livewire;

use App\Models\User;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesFamilyHouse;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesFamilyHouse;
    use RefreshDatabase;

    public function test_dashboard_shows_create_house_when_user_has_none(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('dashboard')
            ->assertSee('Create a house')
            ->assertSee('Dashboard');
    }

    public function test_user_can_create_house_from_dashboard_child(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('create-house')
            ->set('name', 'Family House')
            ->set('currency', 'PKR')
            ->call('save')
            ->assertDispatched('house-created');

        $this->assertDatabaseHas('houses', [
            'name' => 'Family House',
            'owner_id' => $user->id,
        ]);
    }

    public function test_dashboard_shows_month_overview_for_existing_house(): void
    {
        $user = User::factory()->create();
        $house = app(HouseService::class)->create($user, ['name' => 'Family House']);

        $this->actingAs($user);

        Livewire::test('dashboard')
            ->assertSet('houseId', $house->id)
            ->assertSee('Family House')
            ->assertSee('Your settlement')
            ->assertSee('House balances')
            ->assertSee('Settlements');
    }

    public function test_overview_shows_personal_settlement_for_logged_in_user(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();
        $expenses = app(\App\Services\Expense\ExpenseService::class);

        $expense = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['categories']['electricity']->id,
            'title' => 'August Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['users']['A']->id,
        ]);
        $expenses->confirm($expense, $setup['owner']);

        $this->actingAs($setup['users']['B']);

        Livewire::test('dashboard')
            ->set('houseId', $setup['house']->id)
            ->set('month', '2026-08')
            ->assertSee('Your settlement')
            ->assertSee('You pay')
            ->assertSee('Others pay you')
            ->assertSee('A')
            ->assertSee('5900.00');
    }

    public function test_overview_shows_category_spend_pie_for_confirmed_expenses(): void
    {
        $setup = $this->createFamilyHouseWithAugustAvailability();
        $expenses = app(\App\Services\Expense\ExpenseService::class);

        $electricity = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['categories']['electricity']->id,
            'title' => 'August Electricity',
            'amount' => '20000.00',
            'expense_date' => '2026-08-30',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['users']['A']->id,
        ]);
        $expenses->confirm($electricity, $setup['owner']);

        $water = $expenses->create($setup['house'], $setup['owner'], [
            'expense_category_id' => $setup['categories']['water']->id,
            'title' => 'August Water',
            'amount' => '10000.00',
            'expense_date' => '2026-08-28',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-30',
            'paid_by' => $setup['users']['B']->id,
        ]);
        $expenses->confirm($water, $setup['owner']);

        $this->actingAs($setup['owner']);

        Livewire::test('dashboard')
            ->set('houseId', $setup['house']->id)
            ->set('month', '2026-08')
            ->assertSee('Spending by category')
            ->assertSee('Electricity')
            ->assertSee('Water')
            ->assertSee('20000.00')
            ->assertSee('10000.00')
            ->assertSee('66.7%')
            ->assertSee('33.3%');
    }

    public function test_categories_tab_lists_categories_and_rules(): void
    {
        $user = User::factory()->create();
        $house = app(HouseService::class)->create($user, ['name' => 'Family House']);

        $category = app(\App\Services\Expense\ExpenseCategoryService::class)->create($house, $user, [
            'name' => 'Electricity',
            'code' => 'electricity',
        ]);
        app(\App\Services\Allocation\AllocationRuleService::class)->create($category, $user, [
            'rule_type' => 'hybrid',
            'configuration' => [
                'components' => [
                    ['type' => 'fixed', 'percentage' => 10, 'apply_to' => 'all_members'],
                    ['type' => 'per_day', 'percentage' => 90],
                ],
            ],
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($user);

        Livewire::test('dashboard')
            ->call('setTab', 'categories')
            ->assertSee('Categories & rules')
            ->assertSee('Electricity')
            ->assertSee('hybrid')
            ->assertSee('10% fixed')
            ->assertSee('90% per_day')
            ->assertSee('v1');
    }

    public function test_guest_is_redirected_from_dashboard_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
