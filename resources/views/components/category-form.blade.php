<?php

use App\Exceptions\DomainException;
use App\Models\House;
use App\Services\Allocation\AllocationRuleService;
use App\Services\Expense\ExpenseCategoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public int $houseId;

    public string $name = '';

    public string $code = '';

    public string $rule_type = 'per_day';

    public string $apply_to = 'all_members';

    public string $hybrid_mode = 'percentage';

    public string $fixed_percentage = '10';

    public string $fixed_amount = '2000.00';

    public string $successMessage = '';

    public function mount(int $houseId): void
    {
        $this->houseId = $houseId;
    }

    public function save(ExpenseCategoryService $categories, AllocationRuleService $rules): void
    {
        $this->successMessage = '';

        $ruleset = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'rule_type' => ['required', 'in:per_day,fixed,hybrid'],
        ];

        if ($this->rule_type !== 'per_day') {
            $ruleset['apply_to'] = ['required', 'in:all_members,active_members,full_period_members'];
        }

        if ($this->rule_type === 'hybrid') {
            $ruleset['hybrid_mode'] = ['required', 'in:percentage,amount_remainder'];

            if ($this->hybrid_mode === 'amount_remainder') {
                $ruleset['fixed_amount'] = ['required', 'numeric', 'gt:0'];
            } else {
                $ruleset['fixed_percentage'] = ['required', 'numeric', 'min:1', 'max:99'];
            }
        }

        $validated = $this->validate($ruleset);

        $house = House::query()->findOrFail($this->houseId);

        try {
            DB::transaction(function () use ($categories, $rules, $house, $validated) {
                $category = $categories->create($house, Auth::user(), [
                    'name' => $validated['name'],
                    'code' => $validated['code'] ?: null,
                ]);

                $configuration = match ($validated['rule_type']) {
                    'per_day' => [],
                    'fixed' => ['apply_to' => $validated['apply_to']],
                    'hybrid' => $this->hybridConfiguration($validated),
                };

                $rules->create($category, Auth::user(), [
                    'rule_type' => $validated['rule_type'],
                    'configuration' => $configuration,
                    'effective_from' => now()->startOfYear()->toDateString(),
                ]);
            });
        } catch (DomainException $e) {
            $this->addError('name', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->reset(['name', 'code']);
        $this->rule_type = 'per_day';
        $this->apply_to = 'all_members';
        $this->hybrid_mode = 'percentage';
        $this->fixed_percentage = '10';
        $this->fixed_amount = '2000.00';
        $this->successMessage = 'Category and allocation rule created.';
        $this->dispatch('data-updated');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{mode: string, components: list<array<string, mixed>>}
     */
    private function hybridConfiguration(array $validated): array
    {
        $mode = $validated['hybrid_mode'];

        if ($mode === 'amount_remainder') {
            return [
                'mode' => 'amount_remainder',
                'components' => [
                    [
                        'type' => 'fixed',
                        'amount' => $validated['fixed_amount'],
                        'apply_to' => $validated['apply_to'],
                    ],
                    [
                        'type' => 'per_day',
                        'share' => 'remainder',
                    ],
                ],
            ];
        }

        $fixedPct = (int) $validated['fixed_percentage'];

        return [
            'mode' => 'percentage',
            'components' => [
                [
                    'type' => 'fixed',
                    'percentage' => $fixedPct,
                    'apply_to' => $validated['apply_to'],
                ],
                [
                    'type' => 'per_day',
                    'percentage' => 100 - $fixedPct,
                ],
            ],
        ];
    }
};
?>

<div class="panel">
    <h2>Add category + rule</h2>
    <p class="muted">Owner only. Creates a category with its first rule version.</p>

    @if ($successMessage)
        <p class="success">{{ $successMessage }}</p>
    @endif

    <form wire:submit="save">
        <div class="grid-2">
            <div>
                <label for="cat_name">Name</label>
                <input id="cat_name" type="text" wire:model="name" placeholder="Electricity">
                @error('name') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="cat_code">Code (optional)</label>
                <input id="cat_code" type="text" wire:model="code" placeholder="electricity">
                @error('code') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        <label for="rule_type">Rule type</label>
        <select id="rule_type" wire:model.live="rule_type">
            <option value="per_day">Per day</option>
            <option value="fixed">Fixed equal</option>
            <option value="hybrid">Hybrid (fixed + per day)</option>
        </select>
        @error('rule_type') <div class="error">{{ $message }}</div> @enderror

        @if ($rule_type !== 'per_day')
            <label for="apply_to">Fixed apply to</label>
            <select id="apply_to" wire:model="apply_to">
                <option value="all_members">All available members (≥1 day)</option>
                <option value="active_members">Active members (≥1 day)</option>
                <option value="full_period_members">Full-period members only</option>
            </select>
            @error('apply_to') <div class="error">{{ $message }}</div> @enderror
        @endif

        @if ($rule_type === 'hybrid')
            <label for="hybrid_mode">Hybrid split</label>
            <select id="hybrid_mode" wire:model.live="hybrid_mode">
                <option value="percentage">Percentage (fixed % + remainder %)</option>
                <option value="amount_remainder">Fixed amount + remainder per day</option>
            </select>
            @error('hybrid_mode') <div class="error">{{ $message }}</div> @enderror

            @if ($hybrid_mode === 'percentage')
                <label for="fixed_percentage">Fixed percentage</label>
                <input id="fixed_percentage" type="number" min="1" max="99" wire:model="fixed_percentage">
                <p class="muted" style="margin-top:-.5rem;">Remainder percentage is allocated per day.</p>
                @error('fixed_percentage') <div class="error">{{ $message }}</div> @enderror
            @else
                <label for="fixed_amount">Fixed amount</label>
                <input id="fixed_amount" type="number" step="0.01" min="0.01" wire:model="fixed_amount">
                <p class="muted" style="margin-top:-.5rem;">Expense minus this amount is allocated per day.</p>
                @error('fixed_amount') <div class="error">{{ $message }}</div> @enderror
            @endif
        @endif

        <button class="btn" type="submit">Create category</button>
    </form>
</div>
