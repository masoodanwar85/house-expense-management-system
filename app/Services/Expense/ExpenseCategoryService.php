<?php

namespace App\Services\Expense;

use App\Exceptions\DomainException;
use App\Models\ExpenseCategory;
use App\Models\House;
use App\Models\User;
use App\Services\House\HouseAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryService
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    /**
     * @return Collection<int, ExpenseCategory>
     */
    public function list(House $house, User $actor, bool $activeOnly = false): Collection
    {
        $this->access->assertMember($house, $actor);

        return ExpenseCategory::query()
            ->where('house_id', $house->id)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     code?: string,
     *     is_active?: bool,
     *     sort_order?: int
     * }  $data
     */
    public function create(House $house, User $actor, array $data): ExpenseCategory
    {
        $this->access->assertOwner($house, $actor);

        $code = Str::lower($data['code'] ?? Str::slug($data['name'], '_'));

        if (ExpenseCategory::query()->where('house_id', $house->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => ['A category with this code already exists in the house.'],
            ]);
        }

        return ExpenseCategory::query()->create([
            'house_id' => $house->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'code' => $code,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * @param  array{
     *     name?: string,
     *     description?: string|null,
     *     is_active?: bool,
     *     sort_order?: int
     * }  $data
     */
    public function update(ExpenseCategory $category, User $actor, array $data): ExpenseCategory
    {
        $house = $category->house;
        $this->access->assertOwner($house, $actor);

        // Code is immutable after creation to keep historical references stable.
        if (array_key_exists('code', $data)) {
            throw DomainException::because('Category code cannot be changed after creation.');
        }

        if (array_key_exists('name', $data)) {
            $category->name = $data['name'];
        }

        if (array_key_exists('description', $data)) {
            $category->description = $data['description'];
        }

        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('sort_order', $data)) {
            $category->sort_order = (int) $data['sort_order'];
        }

        $category->save();

        return $category->refresh();
    }
}
