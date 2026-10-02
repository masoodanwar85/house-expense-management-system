<?php

use App\Exceptions\DomainException;
use App\Models\House;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $houseId;

    public string $expense_category_id = '';

    public string $title = '';

    public string $amount = '';

    public string $expense_date = '';

    public string $period_start_date = '';

    public string $period_end_date = '';

    /**
     * Month (Y-m) whose bounds are currently filled into the coverage fields.
     * A later expense-date change only replaces coverage while it still matches this month.
     */
    public string $coverageMonth = '';

    public string $successMessage = '';

    public function mount(int $houseId): void
    {
        $this->houseId = $houseId;
        $this->expense_date = now()->toDateString();
        $this->fillCoverageFromExpenseDate();
    }

    public function updatedExpenseDate(): void
    {
        if ($this->expense_date === '') {
            return;
        }

        if ($this->coverageMonth !== '' && ! $this->periodMatchesMonth($this->coverageMonth)) {
            return;
        }

        $this->fillCoverageFromExpenseDate();
    }

    #[Computed]
    public function categories()
    {
        $house = House::query()->findOrFail($this->houseId);

        return app(ExpenseCategoryService::class)->list($house, Auth::user(), activeOnly: true);
    }

    public function save(ExpenseService $expenses): void
    {
        $this->successMessage = '';

        $validated = $this->validate([
            'expense_category_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_date' => ['required', 'date'],
            'period_start_date' => ['nullable', 'date'],
            'period_end_date' => ['nullable', 'date', 'after_or_equal:period_start_date'],
        ]);

        if (($validated['period_start_date'] ?? '') === '') {
            $validated['period_start_date'] = null;
        }

        if (($validated['period_end_date'] ?? '') === '') {
            $validated['period_end_date'] = null;
        }

        if ($validated['period_start_date'] === null && $validated['period_end_date'] === null) {
            $expenseDate = Carbon::parse($validated['expense_date']);
            $validated['period_start_date'] = $expenseDate->copy()->startOfMonth()->toDateString();
            $validated['period_end_date'] = $expenseDate->copy()->endOfMonth()->toDateString();
        }

        try {
            $house = House::query()->findOrFail($this->houseId);
            $expenses->create($house, Auth::user(), $validated);
        } catch (DomainException $e) {
            $this->addError('title', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->reset(['title', 'amount']);
        $this->expense_date = now()->toDateString();
        $this->fillCoverageFromExpenseDate();
        $this->successMessage = 'Draft expense saved. Confirm it when ready.';
        unset($this->categories);
        $this->dispatch('data-updated');
    }

    private function fillCoverageFromExpenseDate(): void
    {
        $expenseDate = Carbon::parse($this->expense_date);
        $this->period_start_date = $expenseDate->copy()->startOfMonth()->toDateString();
        $this->period_end_date = $expenseDate->copy()->endOfMonth()->toDateString();
        $this->coverageMonth = $expenseDate->format('Y-m');
    }

    private function periodMatchesMonth(string $yearMonth): bool
    {
        $start = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth()->toDateString();
        $end = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->endOfMonth()->toDateString();

        return $this->period_start_date === $start && $this->period_end_date === $end;
    }
};
?>

<div class="panel">
    <h2>Add expense</h2>
    <p class="muted">Creates a draft. Confirm later to lock allocations.</p>

    @if ($successMessage)
        <p class="success">{{ $successMessage }}</p>
    @endif

    <form wire:submit="save">
        <label for="expense_category_id">Category</label>
        <select id="expense_category_id" wire:model="expense_category_id">
            <option value="">Select category</option>
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        @error('expense_category_id') <div class="error">{{ $message }}</div> @enderror

        <label for="title">Title</label>
        <input id="title" type="text" wire:model="title">
        @error('title') <div class="error">{{ $message }}</div> @enderror

        <div class="grid-2">
            <div>
                <label for="amount">Amount</label>
                <input id="amount" type="number" step="0.01" min="0.01" wire:model="amount">
                @error('amount') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="expense_date">Expense date</label>
                <input id="expense_date" type="date" wire:model.live="expense_date">
                @error('expense_date') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        <p class="muted">Per-day share uses who was present between these dates. It starts as the full month of the expense date.</p>
        <div class="grid-2">
            <div>
                <label for="period_start_date">Coverage start</label>
                <input id="period_start_date" type="date" wire:model="period_start_date">
                @error('period_start_date') <div class="error">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="period_end_date">Coverage end</label>
                <input id="period_end_date" type="date" wire:model="period_end_date">
                @error('period_end_date') <div class="error">{{ $message }}</div> @enderror
            </div>
        </div>

        <button class="btn" type="submit">Save draft</button>
    </form>
</div>
