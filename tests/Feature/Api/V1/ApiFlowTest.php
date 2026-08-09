<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_register_login_and_me_use_success_envelope(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'device_name' => 'phpunit',
        ]);

        $register->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'owner@example.com')
            ->assertJsonStructure(['success', 'data' => ['user', 'token']]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.com',
            'password' => 'Password1!',
            'device_name' => 'phpunit',
        ]);

        $login->assertOk()->assertJsonPath('success', true);

        Sanctum::actingAs(User::query()->where('email', 'owner@example.com')->firstOrFail());

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'owner@example.com');
    }

    public function test_validation_errors_use_api_envelope(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'x',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed')
            ->assertJsonStructure(['errors']);
    }

    public function test_full_house_expense_and_settlement_flow(): void
    {
        $owner = User::factory()->create(['name' => 'A', 'email' => 'a@example.com']);
        $member = User::factory()->create(['name' => 'B', 'email' => 'b@example.com']);

        Sanctum::actingAs($owner);

        $houseId = $this->postJson('/api/v1/houses', [
            'name' => 'Family House',
            'currency' => 'PKR',
        ])->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/houses/{$houseId}/members", [
            'user_id' => $member->id,
            'joined_at' => '2026-08-01 00:00:00',
        ])->assertCreated()
            ->assertJsonPath('data.user_id', $member->id);

        // Replace default open-ended availability with controlled August periods.
        // Owner already has an open period from house create — close it conceptually by
        // creating member availability for B and leaving owner period (still fine for confirm
        // if we set expense period within membership). For deterministic hybrid math,
        // create B availability and use a fixed rule instead for this API smoke test.

        $this->postJson("/api/v1/houses/{$houseId}/members/{$member->id}/availability", [
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'available',
        ])->assertCreated();

        $categoryId = $this->postJson("/api/v1/houses/{$houseId}/categories", [
            'name' => 'Security',
            'code' => 'security',
        ])->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/categories/{$categoryId}/rules", [
            'rule_type' => 'fixed',
            'configuration' => ['apply_to' => 'all_members'],
            'effective_from' => '2026-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.rule_type', 'fixed');

        $expenseId = $this->postJson("/api/v1/houses/{$houseId}/expenses", [
            'expense_category_id' => $categoryId,
            'title' => 'August Security',
            'amount' => '4000.00',
            'expense_date' => '2026-08-31',
            'period_start_date' => '2026-08-01',
            'period_end_date' => '2026-08-31',
            'paid_by' => $owner->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->postJson("/api/v1/expenses/{$expenseId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $allocations = $this->getJson("/api/v1/expenses/{$expenseId}/allocations")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $allocations);
        $this->assertEquals('2000.00', $allocations[0]['amount']);
        $this->assertEquals('2000.00', $allocations[1]['amount']);

        $settlement = $this->getJson("/api/v1/houses/{$houseId}/settlement?month=2026-08")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_expenses', '4000.00')
            ->json('data');

        $this->assertNotEmpty($settlement['transfers']);
        $this->assertCount(1, $settlement['transfers']);
        $this->assertEquals('2000.00', $settlement['transfers'][0]['amount']);
        $this->assertEquals($member->id, $settlement['transfers'][0]['from_user_id']);
        $this->assertEquals($owner->id, $settlement['transfers'][0]['to_user_id']);

        $this->getJson("/api/v1/houses/{$houseId}/months/2026-08")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonStructure(['data' => ['balances', 'transfers', 'total_expenses']]);

        $this->postJson("/api/v1/houses/{$houseId}/months/2026-08/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->putJson("/api/v1/expenses/{$expenseId}", [
            'amount' => '5000.00',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Month 2026-08 is closed and cannot be modified.');

        $this->postJson("/api/v1/houses/{$houseId}/months/2026-08/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_non_member_cannot_view_house(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($owner);
        $houseId = $this->postJson('/api/v1/houses', ['name' => 'Private'])->json('data.id');

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/houses/{$houseId}")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not an active member of this house.');
    }
}
