<?php

namespace App\Services\Allocation;

use App\Enums\AllocationRuleType;
use App\Exceptions\DomainException;
use App\Models\AllocationRule;
use App\Models\Expense;
use App\Models\House;
use App\Services\Allocation\DTO\AllocationContext;
use App\Services\Allocation\DTO\UserAllocationResult;
use App\Services\Availability\AvailableDaysCalculator;
use App\Services\House\HouseMemberService;
use App\Support\Money;
use Illuminate\Support\Carbon;

class AllocationEngine
{
    public function __construct(
        private readonly PerDayAllocator $perDayAllocator,
        private readonly FixedAllocator $fixedAllocator,
        private readonly HybridAllocator $hybridAllocator,
        private readonly AllocationRuleResolver $ruleResolver,
        private readonly HouseMemberService $memberService,
        private readonly AvailableDaysCalculator $daysCalculator,
    ) {}

    /**
     * @return list<UserAllocationResult>
     */
    public function allocate(AllocationContext $context, AllocationRule $rule): array
    {
        $amount = Money::of($context->amount);

        $result = match ($rule->rule_type) {
            AllocationRuleType::PerDay => $this->wrapSingleComponent(
                $context,
                $this->perDayAllocator->allocate($amount, $context, $rule->configuration),
                'per_day'
            ),
            AllocationRuleType::Fixed => $this->wrapSingleComponent(
                $context,
                $this->fixedAllocator->allocate($amount, $context, $rule->configuration),
                'fixed'
            ),
            AllocationRuleType::Hybrid => $this->hybridAllocator->allocateDetailed(
                $amount,
                $context,
                $rule->configuration
            ),
        };

        $this->assertTotalsMatch($amount, $result['amounts']);

        $output = [];

        foreach ($context->memberUserIds as $userId) {
            $output[] = new UserAllocationResult(
                userId: $userId,
                amount: $result['amounts'][$userId] ?? '0.00',
                components: $result['components'][$userId] ?? [],
                availabilityDays: $context->daysFor($userId),
            );
        }

        return $output;
    }

    /**
     * Build context from house + period and allocate using the resolved rule for an expense.
     *
     * @return list<UserAllocationResult>
     */
    public function allocateExpense(Expense $expense, ?AllocationRule $rule = null): array
    {
        $expense->loadMissing(['house', 'category']);
        $rule ??= $this->ruleResolver->resolveForExpense($expense);

        $context = $this->buildContext(
            $expense->house,
            Money::of((string) $expense->amount),
            $expense->coverageStart(),
            $expense->coverageEnd(),
        );

        return $this->allocate($context, $rule);
    }

    public function buildContext(
        House $house,
        string $amount,
        Carbon|string $periodStart,
        Carbon|string $periodEnd,
    ): AllocationContext {
        $periodStart = Carbon::parse($periodStart)->startOfDay();
        $periodEnd = Carbon::parse($periodEnd)->startOfDay();

        $members = $this->memberService->membersOverlapping($house, $periodStart, $periodEnd);
        $userIds = $members->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        if ($userIds === []) {
            throw DomainException::because('No house members overlap the expense period.');
        }

        $days = $this->daysCalculator->daysForUsers($house, $userIds, $periodStart, $periodEnd);
        $periodLength = $this->daysCalculator->periodLength($periodStart, $periodEnd);

        return new AllocationContext(
            amount: Money::of($amount),
            memberUserIds: $userIds,
            availableDaysByUser: $days,
            periodLengthDays: $periodLength,
        );
    }

    /**
     * @param  array<int, string>  $amounts
     * @return array{amounts: array<int, string>, components: array<int, array<string, string>>}
     */
    private function wrapSingleComponent(AllocationContext $context, array $amounts, string $name): array
    {
        $components = [];

        foreach ($context->memberUserIds as $userId) {
            $components[$userId] = [$name => $amounts[$userId] ?? '0.00'];
        }

        return [
            'amounts' => $amounts,
            'components' => $components,
        ];
    }

    /**
     * @param  array<int, string>  $amounts
     */
    private function assertTotalsMatch(string $expected, array $amounts): void
    {
        $sum = '0.00';

        foreach ($amounts as $amount) {
            $sum = Money::add($sum, $amount);
        }

        if (Money::compare($sum, Money::of($expected)) !== 0) {
            throw DomainException::because(
                "Allocation total {$sum} does not equal expense amount {$expected}."
            );
        }
    }
}
