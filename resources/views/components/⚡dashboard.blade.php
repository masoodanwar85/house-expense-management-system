<?php

use App\Exceptions\DomainException;
use App\Models\AllocationRule;
use App\Models\Expense;
use App\Models\House;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseAccessService;
use App\Services\House\HouseMemberService;
use App\Services\House\HouseService;
use App\Services\Monthly\MonthlySettlementService;
use App\Services\Settlement\SettlementService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?int $houseId = null;

    public string $month = '';

    public string $tab = 'overview';

    public string $memberEmail = '';

    public string $flash = '';

    public function mount(HouseService $houses): void
    {
        $this->month = now()->format('Y-m');
        $list = $houses->listForUser(Auth::user());
        $this->houseId = $list->first()?->id;
    }

    #[On('house-created')]
    public function onHouseCreated(int $houseId): void
    {
        $this->houseId = $houseId;
        $this->tab = 'overview';
        $this->flash = 'House created.';
        $this->refreshComputed();
    }

    #[On('data-updated')]
    public function onDataUpdated(): void
    {
        $this->refreshComputed();
    }

    public function updatedHouseId(): void
    {
        $this->tab = 'overview';
        $this->flash = '';
        $this->refreshComputed();
    }

    public function updatedMonth(): void
    {
        $this->refreshComputed();
    }

    #[Computed]
    public function houses()
    {
        return app(HouseService::class)->listForUser(Auth::user());
    }

    #[Computed]
    public function house(): ?House
    {
        if ($this->houseId === null) {
            return null;
        }

        try {
            return app(HouseService::class)->get(
                House::query()->findOrFail($this->houseId),
                Auth::user()
            );
        } catch (DomainException) {
            return null;
        }
    }

    #[Computed]
    public function isOwner(): bool
    {
        if ($this->house === null) {
            return false;
        }

        return app(HouseAccessService::class)->isOwner($this->house, Auth::user());
    }

    #[Computed]
    public function settlement()
    {
        if ($this->house === null) {
            return null;
        }

        [$year, $month] = $this->yearMonth();

        return app(SettlementService::class)->forMonth($this->house, Auth::user(), $year, $month);
    }

    #[Computed]
    public function expenses()
    {
        if ($this->house === null) {
            return collect();
        }

        return app(ExpenseService::class)->list($this->house, Auth::user(), $this->month);
    }

    #[Computed]
    public function members()
    {
        if ($this->house === null) {
            return collect();
        }

        return app(HouseMemberService::class)->list($this->house, Auth::user());
    }

    #[Computed]
    public function availability()
    {
        if ($this->house === null) {
            return collect();
        }

        [$year, $month] = $this->yearMonth();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = \Illuminate\Support\Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return app(AvailabilityService::class)->listForHouse($this->house, Auth::user(), $start, $end);
    }

    #[Computed]
    public function categories()
    {
        if ($this->house === null) {
            return collect();
        }

        return app(ExpenseCategoryService::class)
            ->list($this->house, Auth::user())
            ->load(['allocationRules' => fn ($query) => $query->orderBy('version')]);
    }

    /**
     * Logged-in user's personal settlement position for the selected month.
     *
     * @return array{
     *     balance: \App\Services\Monthly\DTO\UserBalance|null,
     *     you_owe: \Illuminate\Support\Collection,
     *     owed_to_you: \Illuminate\Support\Collection,
     *     you_owe_total: string,
     *     owed_to_you_total: string
     * }|null
     */
    #[Computed]
    public function myPosition()
    {
        if ($this->settlement === null) {
            return null;
        }

        $userId = (int) Auth::id();
        $plan = $this->settlement;

        $youOwe = $plan->transfers->where('fromUserId', $userId)->values();
        $owedToYou = $plan->transfers->where('toUserId', $userId)->values();

        $youOweTotal = '0.00';
        foreach ($youOwe as $transfer) {
            $youOweTotal = Money::add($youOweTotal, $transfer->amount);
        }

        $owedToYouTotal = '0.00';
        foreach ($owedToYou as $transfer) {
            $owedToYouTotal = Money::add($owedToYouTotal, $transfer->amount);
        }

        return [
            'balance' => $plan->balances->firstWhere('userId', $userId),
            'you_owe' => $youOwe,
            'owed_to_you' => $owedToYou,
            'you_owe_total' => $youOweTotal,
            'owed_to_you_total' => $owedToYouTotal,
        ];
    }

    public function describeRule(AllocationRule $rule): string
    {
        return match ($rule->rule_type->value) {
            'per_day' => '100% per day (by availability)',
            'fixed' => '100% fixed · '.($rule->configuration['apply_to'] ?? 'all_members'),
            'hybrid' => $this->describeHybridRule($rule->configuration ?? []),
            default => $rule->rule_type->value,
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function describeHybridRule(array $configuration): string
    {
        $mode = $configuration['mode'] ?? 'percentage';
        $parts = collect($configuration['components'] ?? [])->map(function (array $component) use ($mode) {
            $label = $component['type'] === 'fixed'
                ? 'fixed ('.($component['apply_to'] ?? 'all_members').')'
                : $component['type'];

            if (($component['share'] ?? null) === 'remainder' || ($mode === 'amount_remainder' && ! isset($component['amount']))) {
                return 'remainder '.$label;
            }

            if (isset($component['amount'])) {
                return $component['amount'].' '.$label;
            }

            return ($component['percentage'] ?? '?').'% '.$label;
        });

        $prefix = $mode === 'amount_remainder' ? 'amount · ' : '';

        return $prefix.$parts->implode(' + ');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->flash = '';
    }

    public function confirmExpense(int $expenseId): void
    {
        $this->flash = '';

        try {
            $expense = Expense::query()->findOrFail($expenseId);
            app(ExpenseService::class)->confirm($expense, Auth::user());
            $this->flash = 'Expense confirmed and allocations stored.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        } catch (ValidationException $e) {
            $this->addError('action', collect($e->errors())->flatten()->first() ?? 'Validation failed.');
        }
    }

    public function cancelExpense(int $expenseId): void
    {
        $this->flash = '';

        try {
            $expense = Expense::query()->findOrFail($expenseId);
            app(ExpenseService::class)->cancel($expense, Auth::user());
            $this->flash = 'Expense cancelled. You can reinstate it to draft if that was a mistake.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function reinstateExpense(int $expenseId): void
    {
        $this->flash = '';

        try {
            $expense = Expense::query()->findOrFail($expenseId);
            app(ExpenseService::class)->reinstate($expense, Auth::user());
            $this->flash = 'Expense reinstated as draft. Confirm it again to include it in settlement.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function closeMonth(): void
    {
        $this->flash = '';

        try {
            [$year, $month] = $this->yearMonth();
            app(MonthlySettlementService::class)->close($this->house, Auth::user(), $year, $month);
            $this->flash = 'Month closed.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function reopenMonth(): void
    {
        $this->flash = '';

        try {
            [$year, $month] = $this->yearMonth();
            app(MonthlySettlementService::class)->reopen($this->house, Auth::user(), $year, $month);
            $this->flash = 'Month reopened.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function addMember(): void
    {
        $this->flash = '';

        $validated = $this->validate([
            'memberEmail' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $validated['memberEmail'])->first();

        if ($user === null) {
            $this->addError('memberEmail', 'No user found with that email.');

            return;
        }

        try {
            app(HouseMemberService::class)->add($this->house, Auth::user(), [
                'user_id' => $user->id,
            ]);
            $this->memberEmail = '';
            $this->flash = "Added {$user->name} to the house.";
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('memberEmail', $e->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function yearMonth(): array
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $this->month, $matches)) {
            $this->month = now()->format('Y-m');
            preg_match('/^(\d{4})-(\d{2})$/', $this->month, $matches);
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    private function refreshComputed(): void
    {
        unset(
            $this->houses,
            $this->house,
            $this->isOwner,
            $this->settlement,
            $this->expenses,
            $this->members,
            $this->availability,
            $this->categories,
            $this->myPosition,
        );
    }
};
?>

<div class="stack">
    <div class="panel">
        <h1>Dashboard</h1>
        <p class="muted">Manage houses, confirm expenses, and settle the month.</p>

        <div class="toolbar">
            <div>
                <label for="houseId">House</label>
                <select id="houseId" wire:model.live="houseId">
                    @forelse ($this->houses as $item)
                        <option value="{{ $item->id }}">{{ $item->name }}</option>
                    @empty
                        <option value="">No houses yet</option>
                    @endforelse
                </select>
            </div>
            @if ($this->house)
                <div>
                    <label for="month">Month</label>
                    <input id="month" type="month" wire:model.live="month">
                </div>
            @endif
        </div>

        @if ($flash)
            <div class="flash">{{ $flash }}</div>
        @endif
        @error('action') <div class="error">{{ $message }}</div> @enderror
    </div>

    @if (! $this->house)
        <livewire:create-house />
    @else
        <div class="tabs">
            <button type="button" class="tab {{ $tab === 'overview' ? 'is-active' : '' }}" wire:click="setTab('overview')">Overview</button>
            <button type="button" class="tab {{ $tab === 'expenses' ? 'is-active' : '' }}" wire:click="setTab('expenses')">Expenses</button>
            <button type="button" class="tab {{ $tab === 'categories' ? 'is-active' : '' }}" wire:click="setTab('categories')">Categories</button>
            <button type="button" class="tab {{ $tab === 'availability' ? 'is-active' : '' }}" wire:click="setTab('availability')">Availability</button>
            <button type="button" class="tab {{ $tab === 'manage' ? 'is-active' : '' }}" wire:click="setTab('manage')">Manage</button>
        </div>

        @if ($tab === 'overview')
            @php
                $plan = $this->settlement;
                $monthStatus = $plan->summary?->status->value ?? 'open';
                $membersByUser = $this->members->keyBy('user_id');
                $me = $this->myPosition;
                $currency = $this->house->currency;
            @endphp

            <div class="panel me-panel">
                <div class="me-panel-head">
                    <div>
                        <h2>Your settlement · {{ $month }}</h2>
                        <p class="muted" style="margin:0;">
                            What you need to pay, and what others should pay you this month.
                        </p>
                    </div>
                    @if ($me && $me['balance'])
                        <div class="me-net {{ $me['balance']->isCreditor() ? 'positive' : ($me['balance']->isDebtor() ? 'negative' : '') }}">
                            <span class="muted" style="display:block; font-size:.85rem; font-weight:600;">Your net balance</span>
                            {{ $me['balance']->balance }} {{ $currency }}
                        </div>
                    @endif
                </div>

                @if ($me)
                    <div class="me-grid">
                        <div class="me-col">
                            <h3>You pay</h3>
                            @if ($me['you_owe']->isEmpty())
                                <p class="muted">You don’t owe anyone this month.</p>
                            @else
                                <p class="me-total negative">Total: {{ $me['you_owe_total'] }} {{ $currency }}</p>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Pay to</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($me['you_owe'] as $transfer)
                                            @php
                                                $toUser = $membersByUser->get($transfer->toUserId)?->user;
                                            @endphp
                                            <tr>
                                                <td>{{ $toUser?->name ?? ('User #'.$transfer->toUserId) }}</td>
                                                <td class="negative">{{ $transfer->amount }} {{ $currency }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>

                        <div class="me-col">
                            <h3>Others pay you</h3>
                            @if ($me['owed_to_you']->isEmpty())
                                <p class="muted">Nobody owes you this month.</p>
                            @else
                                <p class="me-total positive">Total: {{ $me['owed_to_you_total'] }} {{ $currency }}</p>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>From</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($me['owed_to_you'] as $transfer)
                                            @php
                                                $fromUser = $membersByUser->get($transfer->fromUserId)?->user;
                                            @endphp
                                            <tr>
                                                <td>{{ $fromUser?->name ?? ('User #'.$transfer->fromUserId) }}</td>
                                                <td class="positive">{{ $transfer->amount }} {{ $currency }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <div class="split">
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap;">
                        <div>
                            <h2>{{ $this->house->name }} · {{ $month }}</h2>
                            <p class="muted" style="margin:0;">
                                Total expenses:
                                <strong>{{ $plan->totalExpenses }} {{ $currency }}</strong>
                                ·
                                <span class="badge {{ $monthStatus === 'closed' ? 'badge-closed' : '' }}">
                                    {{ $monthStatus }}
                                </span>
                            </p>
                        </div>
                        @if ($this->isOwner)
                            <div style="display:flex; gap:.5rem;">
                                @if ($monthStatus === 'closed')
                                    <button class="btn btn-secondary btn-sm" type="button" wire:click="reopenMonth">Reopen month</button>
                                @else
                                    <button class="btn btn-sm" type="button" wire:click="closeMonth">Close month</button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <h3 style="margin-top:1.25rem;">House balances</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Days</th>
                                <th>Paid</th>
                                <th>Share</th>
                                <th>Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($plan->balances as $balance)
                                @php
                                    $balanceUser = $membersByUser->get($balance->userId)?->user;
                                @endphp
                                <tr>
                                    <td>{{ $balanceUser?->name ?? ('User #'.$balance->userId) }}</td>
                                    <td>{{ $balance->availabilityDays }}</td>
                                    <td>{{ $balance->actualPaid }}</td>
                                    <td>{{ $balance->responsibility }}</td>
                                    <td class="{{ $balance->isCreditor() ? 'positive' : ($balance->isDebtor() ? 'negative' : '') }}">
                                        {{ $balance->balance }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="muted">No confirmed expenses this month.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <h2>Settlements</h2>
                    <p class="muted">Who pays whom to settle the month.</p>
                    <table>
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>To</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($plan->transfers as $transfer)
                                @php
                                    $fromUser = $membersByUser->get($transfer->fromUserId)?->user;
                                    $toUser = $membersByUser->get($transfer->toUserId)?->user;
                                @endphp
                                <tr>
                                    <td>{{ $fromUser?->name ?? ('#'.$transfer->fromUserId) }}</td>
                                    <td>{{ $toUser?->name ?? ('#'.$transfer->toUserId) }}</td>
                                    <td>{{ $transfer->amount }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="muted">Everyone is settled.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($tab === 'expenses')
            <div class="split">
                <div class="panel">
                    <h2>Expenses · {{ $month }}</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->expenses as $expense)
                                <tr wire:key="expense-{{ $expense->id }}">
                                    <td>
                                        <strong>{{ $expense->title }}</strong>
                                        <div class="muted">{{ $expense->category?->name }} · {{ $expense->expense_date?->toDateString() }}</div>
                                    </td>
                                    <td>{{ $expense->amount }}</td>
                                    <td>
                                        <span class="badge badge-{{ $expense->status->value }}">{{ $expense->status->value }}</span>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        @if ($expense->status->value === 'draft')
                                            <button class="btn btn-sm" type="button" wire:click="confirmExpense({{ $expense->id }})">Confirm</button>
                                        @endif
                                        @if ($expense->status->value !== 'cancelled' && $this->isOwner)
                                            <button
                                                class="btn btn-secondary btn-sm"
                                                type="button"
                                                wire:click="cancelExpense({{ $expense->id }})"
                                                wire:confirm="Cancel this expense? You can reinstate it later as a draft."
                                            >Cancel</button>
                                        @endif
                                        @if ($expense->status->value === 'cancelled' && $this->isOwner)
                                            <button class="btn btn-sm" type="button" wire:click="reinstateExpense({{ $expense->id }})">Reinstate</button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">No expenses for this month yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <livewire:expense-form :house-id="$this->house->id" :key="'expense-form-'.$this->house->id" />
            </div>
        @endif

        @if ($tab === 'categories')
            <div class="split">
                <div class="panel">
                    <h2>Categories &amp; rules</h2>
                    <p class="muted">How each expense category is allocated. Historical rule versions are kept.</p>

                    @forelse ($this->categories as $category)
                        <div class="category-block" wire:key="category-{{ $category->id }}">
                            <div class="category-head">
                                <div>
                                    <strong>{{ $category->name }}</strong>
                                    <span class="muted"> · {{ $category->code }}</span>
                                </div>
                                <span class="badge {{ $category->is_active ? 'badge-confirmed' : 'badge-closed' }}">
                                    {{ $category->is_active ? 'active' : 'inactive' }}
                                </span>
                            </div>

                            @if ($category->description)
                                <p class="muted" style="margin:.35rem 0 .5rem;">{{ $category->description }}</p>
                            @endif

                            <table>
                                <thead>
                                    <tr>
                                        <th>Version</th>
                                        <th>Type</th>
                                        <th>Rule</th>
                                        <th>Effective</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($category->allocationRules as $rule)
                                        <tr>
                                            <td>v{{ $rule->version }}</td>
                                            <td><span class="badge">{{ $rule->rule_type->value }}</span></td>
                                            <td>{{ $this->describeRule($rule) }}</td>
                                            <td>
                                                {{ $rule->effective_from?->toDateString() }}
                                                →
                                                {{ $rule->effective_to?->toDateString() ?? 'open' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="muted">No allocation rules yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @empty
                        <p class="muted">No categories yet.{{ $this->isOwner ? ' Create one on the right.' : '' }}</p>
                    @endforelse
                </div>

                @if ($this->isOwner)
                    <livewire:category-form :house-id="$this->house->id" :key="'cat-form-'.$this->house->id" />
                @else
                    <div class="panel">
                        <h2>Add category</h2>
                        <p class="muted">Only the house owner can create categories and allocation rules.</p>
                    </div>
                @endif
            </div>
        @endif

        @if ($tab === 'availability')
            <div class="split">
                <div class="panel">
                    <h2>Availability · {{ $month }}</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($this->availability as $period)
                                <tr>
                                    <td>{{ $period->user?->name }}</td>
                                    <td>{{ $period->start_date?->toDateString() }}</td>
                                    <td>{{ $period->end_date?->toDateString() ?? 'open' }}</td>
                                    <td>{{ $period->status->value }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">No availability overlapping this month.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <livewire:availability-form :house-id="$this->house->id" :key="'avail-form-'.$this->house->id" />
            </div>
        @endif

        @if ($tab === 'manage')
            <div class="split">
                <div class="stack">
                    <div class="panel">
                        <h2>Members</h2>
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->members as $member)
                                    <tr>
                                        <td>{{ $member->user?->name }}</td>
                                        <td class="muted">{{ $member->user?->email }}</td>
                                        <td>{{ $member->role->value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if ($this->isOwner)
                            <form wire:submit="addMember" style="margin-top:1rem;">
                                <label for="memberEmail">Add member by email</label>
                                <div class="grid-2">
                                    <input id="memberEmail" type="email" wire:model="memberEmail" placeholder="roommate@example.com">
                                    <button class="btn" type="submit" style="height:fit-content; margin-top:1.55rem;">Add member</button>
                                </div>
                                @error('memberEmail') <div class="error">{{ $message }}</div> @enderror
                            </form>
                        @endif
                    </div>

                    <livewire:create-house />
                </div>

                <div class="panel">
                    <h2>House details</h2>
                    <p class="muted" style="margin:0;">
                        Currency: <strong>{{ $this->house->currency }}</strong><br>
                        Timezone: <strong>{{ $this->house->timezone }}</strong>
                    </p>
                    <p class="muted" style="margin-top:.75rem;">
                        Categories and allocation rules are under the <strong>Categories</strong> tab.
                    </p>
                </div>
            </div>
        @endif
    @endif
</div>
