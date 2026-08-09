<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\House\HouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_update_category(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Home']);

        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Electricity',
            'description' => 'Power bill',
            'sort_order' => 1,
        ]);

        $this->assertEquals('electricity', $category->code);
        $this->assertTrue($category->is_active);

        $updated = app(ExpenseCategoryService::class)->update($category, $owner, [
            'name' => 'Electric Bill',
            'is_active' => false,
        ]);

        $this->assertEquals('Electric Bill', $updated->name);
        $this->assertFalse($updated->is_active);
        $this->assertEquals('electricity', $updated->code);
    }

    public function test_category_code_is_immutable(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Home']);
        $category = app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Water',
        ]);

        $this->expectException(DomainException::class);

        app(ExpenseCategoryService::class)->update($category, $owner, [
            'code' => 'h2o',
        ]);
    }

    public function test_duplicate_category_code_rejected(): void
    {
        $owner = User::factory()->create();
        $house = app(HouseService::class)->create($owner, ['name' => 'Home']);

        app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Gas',
            'code' => 'gas',
        ]);

        $this->expectException(ValidationException::class);

        app(ExpenseCategoryService::class)->create($house, $owner, [
            'name' => 'Gas Again',
            'code' => 'gas',
        ]);
    }
}
