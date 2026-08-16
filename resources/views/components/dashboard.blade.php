<?php

use App\Enums\ExpenseStatus;
use App\Enums\SettlementPaymentStatus;
use App\Exceptions\DomainException;
use App\Models\AllocationRule;
use App\Models\Expense;
use App\Models\House;
use App\Models\SettlementPayment;
use App\Models\User;
use App\Services\Availability\AvailabilityService;
use App\Services\Expense\ExpenseCategoryService;
use App\Services\Expense\ExpenseService;
use App\Services\House\HouseAccessService;
use App\Services\House\HouseMemberService;
use App\Services\House\HouseService;
use App\Services\Monthly\MonthlySettlementService;
use App\Services\Settlement\OverallOwingService;
use App\Services\Settlement\SettlementPaymentService;
use App\Services\Settlement\SettlementService;
use App\Support\Money;
use Illuminate\Support\Carbon;
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

    public string $paymentToUserId = '';

    public string $paymentAmount = '';

    public string $paymentNote = '';

    public string $paymentMonth = '';

    public function mount(HouseService $houses): void
    {
        $this->month = now()->format('Y-m');
        $this->paymentMonth = $this->month;
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
        if ($this->paymentMonth === '' || ! preg_match('/^\d{4}-\d{2}$/', $this->paymentMonth)) {
            $this->paymentMonth = $this->month;
        }
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
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return app(AvailabilityService::class)->listForHouse($this->house, Auth::user(), $start, $end);
    }

    /**
     * Availability periods grouped by member for the selected month.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *     user_id: int,
     *     name: string,
     *     is_me: bool,
     *     available_days: int,
     *     periods: \Illuminate\Support\Collection<int, array{start: string, end: string, status: string}>
     * }>
     */
    #[Computed]
    public function availabilityByMember()
    {
        if ($this->house === null) {
            return collect();
        }

        [$year, $month] = $this->yearMonth();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
        $calculator = app(\App\Services\Availability\AvailableDaysCalculator::class);
        $meId = (int) Auth::id();

        return $this->availability
            ->groupBy('user_id')
            ->map(function ($periods, $userId) use ($calculator, $start, $end, $meId) {
                $userId = (int) $userId;
                $first = $periods->first();

                return [
                    'user_id' => $userId,
                    'name' => $first?->user?->name ?? ('User #'.$userId),
                    'is_me' => $userId === $meId,
                    'available_days' => $calculator->daysForUser($this->house, $userId, $start, $end),
                    'periods' => $periods
                        ->sortBy('start_date')
                        ->values()
                        ->map(fn ($period) => [
                            'start' => $period->start_date?->toDateString() ?? '',
                            'end' => $period->end_date?->toDateString() ?? 'open',
                            'status' => $period->status->value,
                        ]),
                ];
            })
            ->sortBy([
                fn ($row) => $row['is_me'] ? 0 : 1,
                fn ($row) => strtolower($row['name']),
            ])
            ->values();
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
     * Confirmed expense totals by category for the selected month (matches settlement month filter).
     *
     * @return array{
     *     total: string,
     *     slices: \Illuminate\Support\Collection<int, array{
     *         name: string,
     *         amount: string,
     *         percent: string,
     *         color: string,
     *         path: string|null,
     *         full_circle: bool
     *     }>
     * }
     */
    #[Computed]
    public function categorySpend(): array
    {
        if ($this->house === null) {
            return ['total' => '0.00', 'slices' => collect()];
        }

        [$year, $month] = $this->yearMonth();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $expenses = Expense::query()
            ->where('house_id', $this->house->id)
            ->where('status', ExpenseStatus::Confirmed)
            ->whereDate('expense_date', '>=', $start)
            ->whereDate('expense_date', '<=', $end)
            ->with('category')
            ->get();

        $totals = [];
        foreach ($expenses as $expense) {
            $key = (int) $expense->expense_category_id;
            if (! isset($totals[$key])) {
                $totals[$key] = [
                    'name' => $expense->category?->name ?? 'Uncategorized',
                    'amount' => '0.00',
                ];
            }
            $totals[$key]['amount'] = Money::add($totals[$key]['amount'], Money::of($expense->amount));
        }

        uasort($totals, fn (array $a, array $b) => Money::compare($b['amount'], $a['amount']));

        $total = '0.00';
        foreach ($totals as $row) {
            $total = Money::add($total, $row['amount']);
        }

        $palette = ['#7ea6c8', '#9bc07e', '#f0b35d', '#d98c8c', '#44546a', '#8eba85', '#d6b656', '#24364b'];
        $cx = 50.0;
        $cy = 50.0;
        $radius = 40.0;
        $angle = -90.0;
        $index = 0;
        $slices = collect();

        foreach ($totals as $row) {
            $share = Money::compare($total, '0.00') === 0
                ? 0.0
                : (float) Money::div(Money::mul($row['amount'], '100'), $total, 8);
            $percent = number_format($share, 1, '.', '');
            $sliceAngle = ($share / 100) * 360;
            $color = $palette[$index % count($palette)];
            $fullCircle = $share >= 99.999;
            $path = null;

            if (! $fullCircle && $sliceAngle > 0.001) {
                $startAngle = $angle;
                $endAngle = $angle + $sliceAngle;
                $path = $this->pieSlicePath($cx, $cy, $radius, $startAngle, $endAngle);
                $angle = $endAngle;
            }

            $slices->push([
                'name' => $row['name'],
                'amount' => $row['amount'],
                'percent' => $percent,
                'color' => $color,
                'path' => $path,
                'full_circle' => $fullCircle,
            ]);
            $index++;
        }

        return [
            'total' => $total,
            'slices' => $slices,
        ];
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

    #[Computed]
    public function overallOwing()
    {
        if ($this->house === null) {
            return null;
        }

        return app(OverallOwingService::class)->forHouse($this->house, Auth::user());
    }

    #[Computed]
    public function settlementPayments()
    {
        if ($this->house === null) {
            return collect();
        }

        return app(SettlementPaymentService::class)->listForHouse($this->house, Auth::user());
    }

    #[Computed]
    public function pendingSettlementPayments()
    {
        return $this->settlementPayments
            ->where('status', SettlementPaymentStatus::Pending)
            ->values();
    }

    /**
     * Logged-in user's lifetime position across all months.
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
    public function myOverallPosition()
    {
        if ($this->overallOwing === null) {
            return null;
        }

        $userId = (int) Auth::id();
        $plan = $this->overallOwing;

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

    public function recordSettlementPayment(): void
    {
        $this->flash = '';

        $validated = $this->validate([
            'paymentToUserId' => ['required', 'integer'],
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentMonth' => ['required', 'date_format:Y-m'],
            'paymentNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            [$year, $monthNumber] = array_map('intval', explode('-', $validated['paymentMonth']));

            app(SettlementPaymentService::class)->record($this->house, Auth::user(), [
                'to_user_id' => (int) $validated['paymentToUserId'],
                'amount' => $validated['paymentAmount'],
                'year' => $year,
                'month' => $monthNumber,
                'note' => $validated['paymentNote'] ?? null,
            ]);
            $this->paymentToUserId = '';
            $this->paymentAmount = '';
            $this->paymentNote = '';
            $this->paymentMonth = $this->month;
            $this->flash = 'Payment recorded for '.$validated['paymentMonth'].'. Waiting for the recipient to confirm.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('paymentAmount', $e->getMessage());
        } catch (ValidationException $e) {
            $this->addError('paymentAmount', collect($e->errors())->flatten()->first() ?? 'Validation failed.');
        }
    }

    public function confirmSettlementPayment(int $paymentId): void
    {
        $this->flash = '';

        try {
            $payment = SettlementPayment::query()->findOrFail($paymentId);
            app(SettlementPaymentService::class)->confirm($payment, Auth::user());
            $this->flash = 'Payment confirmed. Overall owing has been updated.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function rejectSettlementPayment(int $paymentId): void
    {
        $this->flash = '';

        try {
            $payment = SettlementPayment::query()->findOrFail($paymentId);
            app(SettlementPaymentService::class)->reject($payment, Auth::user());
            $this->flash = 'Payment rejected.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
        }
    }

    public function cancelSettlementPayment(int $paymentId): void
    {
        $this->flash = '';

        try {
            $payment = SettlementPayment::query()->findOrFail($paymentId);
            app(SettlementPaymentService::class)->cancel($payment, Auth::user());
            $this->flash = 'Payment cancelled.';
            $this->refreshComputed();
        } catch (DomainException $e) {
            $this->addError('action', $e->getMessage());
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
            $this->availabilityByMember,
            $this->categories,
            $this->categorySpend,
            $this->myPosition,
            $this->overallOwing,
            $this->myOverallPosition,
            $this->settlementPayments,
            $this->pendingSettlementPayments,
        );
    }

    private function pieSlicePath(float $cx, float $cy, float $radius, float $startAngle, float $endAngle): string
    {
        [$x1, $y1] = $this->polarPoint($cx, $cy, $radius, $startAngle);
        [$x2, $y2] = $this->polarPoint($cx, $cy, $radius, $endAngle);
        $largeArc = ($endAngle - $startAngle) > 180 ? 1 : 0;

        return sprintf(
            'M %.4f %.4f L %.4f %.4f A %.4f %.4f 0 %d 1 %.4f %.4f Z',
            $cx,
            $cy,
            $x1,
            $y1,
            $radius,
            $radius,
            $largeArc,
            $x2,
            $y2,
        );
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function polarPoint(float $cx, float $cy, float $radius, float $angleDegrees): array
    {
        $radians = deg2rad($angleDegrees);

        return [
            $cx + ($radius * cos($radians)),
            $cy + ($radius * sin($radians)),
        ];
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
                $categorySpend = $this->categorySpend;
                $availabilityByMember = $this->availabilityByMember;
                $overall = $this->overallOwing;
                $meOverall = $this->myOverallPosition;
                $pendingPayments = $this->pendingSettlementPayments;
                $allPayments = $this->settlementPayments;
            @endphp

            <div class="panel panel-headed">
                <div class="panel-head">
                    <div>
                        <h2>Total owing · all months</h2>
                        <p class="muted" style="margin:.25rem 0 0;">
                            Expense nets minus confirmed settlement payments
                        </p>
                    </div>
                    @if ($overall)
                        <span class="badge">{{ $overall->totalExpenses }} {{ $currency }} total spent</span>
                    @endif
                </div>
                <div class="panel-body">
                    @if (! $overall || ($overall->balances->isEmpty() && $allPayments->isEmpty()))
                        <p class="muted">No confirmed expenses yet — nothing to settle overall.</p>
                    @else
                        @if ($meOverall && $meOverall['balance'])
                            <div class="stat-grid" style="margin-bottom:1rem;">
                                <div class="stat-card {{ $meOverall['balance']->isDebtor() ? 'stat-amber' : 'stat-mint' }}">
                                    <h3>You still owe</h3>
                                    <div class="stat-value">{{ $meOverall['you_owe_total'] }}</div>
                                    <span style="font-size:.9rem;">{{ $currency }} after confirmed payments</span>
                                </div>
                                <div class="stat-card {{ $meOverall['balance']->isCreditor() ? 'stat-mint' : 'stat-amber' }}">
                                    <h3>Still owed to you</h3>
                                    <div class="stat-value">{{ $meOverall['owed_to_you_total'] }}</div>
                                    <span style="font-size:.9rem;">{{ $currency }} after confirmed payments</span>
                                </div>
                            </div>
                        @endif

                        <div class="split" style="margin:0 0 1.25rem;">
                            <div>
                                <h3 style="margin-top:0;">Lifetime balances</h3>
                                <p class="muted" style="margin:0 0 .75rem; font-size:.9rem;">
                                    Paid / Share are from expenses. Net also includes confirmed payments.
                                </p>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Member</th>
                                            <th>Paid</th>
                                            <th>Share</th>
                                            <th>Net</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($overall->balances as $balance)
                                            @php
                                                $balanceUser = $membersByUser->get($balance->userId)?->user;
                                            @endphp
                                            <tr>
                                                <td>
                                                    {{ $balanceUser?->name ?? ('User #'.$balance->userId) }}
                                                    @if ($balance->userId === (int) auth()->id())
                                                        <span class="badge">You</span>
                                                    @endif
                                                </td>
                                                <td>{{ $balance->actualPaid }}</td>
                                                <td>{{ $balance->responsibility }}</td>
                                                <td class="{{ $balance->isCreditor() ? 'positive' : ($balance->isDebtor() ? 'negative' : '') }}">
                                                    {{ $balance->balance }}
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="4" class="muted">No balances yet.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            <div>
                                <h3 style="margin-top:0;">Who pays whom (overall)</h3>
                                <table>
                                    <thead>
                                        <tr>
                                            <th>From</th>
                                            <th>To</th>
                                            <th>Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($overall->transfers as $transfer)
                                            @php
                                                $fromUser = $membersByUser->get($transfer->fromUserId)?->user;
                                                $toUser = $membersByUser->get($transfer->toUserId)?->user;
                                            @endphp
                                            <tr>
                                                <td>{{ $fromUser?->name ?? ('#'.$transfer->fromUserId) }}</td>
                                                <td>{{ $toUser?->name ?? ('#'.$transfer->toUserId) }}</td>
                                                <td>{{ $transfer->amount }} {{ $currency }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="muted">Everyone is settled overall.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="split" style="margin:0;">
                        <div class="me-col" style="margin:0;">
                            <h3 style="margin-top:0;">Record a payment</h3>
                            <p class="muted" style="margin:0 0 .75rem;">
                                If you paid someone, record it here. It reduces owing only after they confirm.
                            </p>
                            <form wire:submit="recordSettlementPayment">
                                <label for="paymentToUserId">Paid to</label>
                                <select id="paymentToUserId" wire:model="paymentToUserId">
                                    <option value="">Select member</option>
                                    @foreach ($this->members as $member)
                                        @if ((int) $member->user_id !== (int) auth()->id())
                                            <option value="{{ $member->user_id }}">{{ $member->user?->name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('paymentToUserId') <div class="error">{{ $message }}</div> @enderror

                                <label for="paymentMonth">For month</label>
                                <input id="paymentMonth" type="month" wire:model="paymentMonth">
                                @error('paymentMonth') <div class="error">{{ $message }}</div> @enderror
                                <p class="muted" style="margin:-.5rem 0 .85rem; font-size:.85rem;">
                                    This payment reduces that month’s settlement after confirmation.
                                </p>

                                <label for="paymentAmount">Amount ({{ $currency }})</label>
                                <input id="paymentAmount" type="number" step="0.01" min="0.01" wire:model="paymentAmount" placeholder="3000.00">
                                @error('paymentAmount') <div class="error">{{ $message }}</div> @enderror

                                <label for="paymentNote">Note (optional)</label>
                                <input id="paymentNote" type="text" wire:model="paymentNote" placeholder="JazzCash / bank transfer">

                                <button class="btn" type="submit">Record payment</button>
                            </form>
                        </div>

                        <div class="me-col" style="margin:0;">
                            <h3 style="margin-top:0;">Pending confirmations</h3>
                            @if ($pendingPayments->isEmpty())
                                <p class="muted">No payments waiting for confirmation.</p>
                            @else
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Payment</th>
                                            <th>Amount</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($pendingPayments as $payment)
                                            <tr wire:key="pending-pay-{{ $payment->id }}">
                                                <td>
                                                    <strong>{{ $payment->fromUser?->name }}</strong>
                                                    <span class="muted">→</span>
                                                    <strong>{{ $payment->toUser?->name }}</strong>
                                                    <div class="muted" style="font-size:.85rem;">
                                                        For {{ $payment->forMonthLabel() }}
                                                        @if ($payment->note)
                                                            · {{ $payment->note }}
                                                        @endif
                                                    </div>
                                                </td>
                                                <td>{{ $payment->amount }} {{ $currency }}</td>
                                                <td style="white-space:nowrap;">
                                                    @if ((int) $payment->to_user_id === (int) auth()->id())
                                                        <button class="btn btn-sm" type="button" wire:click="confirmSettlementPayment({{ $payment->id }})">Confirm</button>
                                                        <button class="btn btn-secondary btn-sm" type="button" wire:click="rejectSettlementPayment({{ $payment->id }})">Reject</button>
                                                    @elseif ((int) $payment->from_user_id === (int) auth()->id() || $this->isOwner)
                                                        <span class="badge badge-draft">awaiting confirm</span>
                                                        <button class="btn btn-secondary btn-sm" type="button" wire:click="cancelSettlementPayment({{ $payment->id }})">Cancel</button>
                                                    @else
                                                        <span class="badge badge-draft">pending</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif

                            @php
                                $meId = (int) auth()->id();
                                $confirmedPayments = $allPayments
                                    ->where('status', SettlementPaymentStatus::Confirmed)
                                    ->filter(fn ($payment) => (int) $payment->from_user_id === $meId
                                        || (int) $payment->to_user_id === $meId)
                                    ->take(5);
                            @endphp
                            @if ($confirmedPayments->isNotEmpty())
                                <h3 style="margin-top:1.25rem;">Your recent confirmed</h3>
                                <table>
                                    <tbody>
                                        @foreach ($confirmedPayments as $payment)
                                            <tr>
                                                <td>
                                                    {{ $payment->fromUser?->name }}
                                                    <span class="muted">→</span>
                                                    {{ $payment->toUser?->name }}
                                                    <div class="muted" style="font-size:.85rem;">{{ $payment->forMonthLabel() }}</div>
                                                </td>
                                                <td class="positive">{{ $payment->amount }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="overview-board">
                <div class="panel panel-headed">
                    <div class="panel-head">
                        <div>
                            <h2>{{ $this->house->name }} · {{ $month }}</h2>
                            <p class="muted" style="margin:.25rem 0 0;">House balances</p>
                        </div>
                        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                            <span class="badge {{ $monthStatus === 'closed' ? 'badge-closed' : '' }}">{{ $monthStatus }}</span>
                            @if ($this->isOwner)
                                @if ($monthStatus === 'closed')
                                    <button class="btn btn-secondary btn-sm" type="button" wire:click="reopenMonth">Reopen</button>
                                @else
                                    <button class="btn btn-sm" type="button" wire:click="closeMonth" style="background:#fff;color:#24364b;">Close month</button>
                                @endif
                            @endif
                        </div>
                    </div>
                    <div class="panel-body">
                        <p class="muted" style="margin:0 0 .75rem; font-size:.9rem;">
                            Balances include confirmed settlement payments tagged to this month.
                        </p>
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
                </div>

                <div class="overview-side">
                    <div class="stat-grid">
                        <div class="stat-card stat-mint">
                            <h3>Actual</h3>
                            <div class="stat-value">{{ $plan->totalExpenses }}</div>
                            <span style="font-size:.9rem;">{{ $currency }} · confirmed</span>
                        </div>
                        <div class="stat-card stat-amber">
                            <h3>Your net</h3>
                            <div class="stat-value">
                                {{ $me && $me['balance'] ? $me['balance']->balance : '0.00' }}
                            </div>
                            <span style="font-size:.9rem;">{{ $currency }} · this month</span>
                        </div>
                    </div>

                    <div class="panel">
                        <h2 style="margin-bottom:.35rem;">Spending by category</h2>
                        <p class="muted" style="margin:0 0 1rem;">Top categories for {{ $month }}</p>

                        @if ($categorySpend['slices']->isEmpty())
                            <p class="muted">No confirmed expenses this month.</p>
                        @else
                            <div class="pie-layout">
                                <svg class="pie-chart" viewBox="0 0 100 100" role="img" aria-label="Expense share by category">
                                    @foreach ($categorySpend['slices'] as $slice)
                                        @if ($slice['full_circle'])
                                            <circle cx="50" cy="50" r="40" fill="{{ $slice['color'] }}">
                                                <title>{{ $slice['name'] }}: {{ $slice['amount'] }} ({{ $slice['percent'] }}%)</title>
                                            </circle>
                                        @elseif ($slice['path'])
                                            <path d="{{ $slice['path'] }}" fill="{{ $slice['color'] }}">
                                                <title>{{ $slice['name'] }}: {{ $slice['amount'] }} ({{ $slice['percent'] }}%)</title>
                                            </path>
                                        @endif
                                    @endforeach
                                    <circle cx="50" cy="50" r="22" fill="#fff"></circle>
                                    <text x="50" y="48" text-anchor="middle" class="pie-center-label">Total</text>
                                    <text x="50" y="58" text-anchor="middle" class="pie-center-value">{{ $categorySpend['total'] }}</text>
                                </svg>

                                <ul class="pie-legend">
                                    @foreach ($categorySpend['slices'] as $slice)
                                        <li>
                                            <div class="pie-legend-meta">
                                                <strong>{{ $slice['name'] }}</strong>
                                                <span class="muted">{{ $slice['percent'] }}%</span>
                                            </div>
                                            <div class="pie-bar-track">
                                                <span class="pie-bar" style="width: {{ max((float) $slice['percent'], 4) }}%; background: {{ $slice['color'] }};"></span>
                                            </div>
                                            <span class="muted" style="font-size:.9rem;">{{ $slice['amount'] }} {{ $currency }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="panel panel-headed">
                <div class="panel-head">
                    <div>
                        <h2>Member availability · {{ $month }}</h2>
                        <p class="muted" style="margin:.25rem 0 0;">Who was present and on which dates</p>
                    </div>
                    <button class="btn btn-sm" type="button" wire:click="setTab('availability')" style="background:#fff;color:#24364b;">
                        Manage dates
                    </button>
                </div>
                <div class="panel-body">
                    @if ($availabilityByMember->isEmpty())
                        <p class="muted" style="margin:0;">No availability recorded for this month yet.</p>
                    @else
                        <div class="availability-grid">
                            @foreach ($availabilityByMember as $member)
                                <div class="availability-card {{ $member['is_me'] ? 'is-me' : '' }}" wire:key="avail-member-{{ $member['user_id'] }}">
                                    <div class="availability-card-head">
                                        <div>
                                            <strong>{{ $member['name'] }}</strong>
                                            @if ($member['is_me'])
                                                <span class="badge">You</span>
                                            @endif
                                        </div>
                                        <span class="muted">{{ $member['available_days'] }} available day{{ $member['available_days'] === 1 ? '' : 's' }}</span>
                                    </div>
                                    <ul class="availability-dates">
                                        @foreach ($member['periods'] as $period)
                                            <li>
                                                <span class="badge {{ $period['status'] === 'available' ? 'badge-confirmed' : 'badge-closed' }}">
                                                    {{ $period['status'] }}
                                                </span>
                                                <span>
                                                    {{ $period['start'] }}
                                                    <span class="muted">→</span>
                                                    {{ $period['end'] }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

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

            <div class="panel panel-headed">
                <div class="panel-head">
                    <h2>Settlements</h2>
                    <p class="muted" style="margin:0;">Who pays whom to settle the month</p>
                </div>
                <div class="panel-body">
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
