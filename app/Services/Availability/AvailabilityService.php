<?php

namespace App\Services\Availability;

use App\Enums\AvailabilityStatus;
use App\Exceptions\DomainException;
use App\Models\House;
use App\Models\MemberAvailabilityPeriod;
use App\Models\User;
use App\Services\House\HouseAccessService;
use App\Services\Monthly\MonthLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AvailabilityService
{
    public function __construct(
        private readonly HouseAccessService $access,
        private readonly MonthLockService $monthLock,
        private readonly AvailableDaysCalculator $calculator,
    ) {}

    /**
     * @param  array{
     *     user_id?: int,
     *     start_date: string,
     *     end_date?: string|null,
     *     status?: string
     * }  $data
     */
    public function create(House $house, User $actor, array $data): MemberAvailabilityPeriod
    {
        $userId = (int) ($data['user_id'] ?? $actor->id);
        $target = User::query()->findOrFail($userId);

        if ($actor->id !== $userId) {
            $this->access->assertOwner($house, $actor);
        } else {
            $this->access->assertMember($house, $actor);
        }

        // Target must be a house member on the start date.
        if (! $this->access->isMember($house, $target, Carbon::parse($data['start_date']))) {
            throw DomainException::because('Availability can only be recorded for active house members.');
        }

        $startDate = Carbon::parse($data['start_date'])->toDateString();
        $endDate = array_key_exists('end_date', $data) && $data['end_date'] !== null && $data['end_date'] !== ''
            ? Carbon::parse($data['end_date'])->toDateString()
            : null;

        if ($endDate !== null && $startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => ['end_date must be greater than or equal to start_date.'],
            ]);
        }

        $status = AvailabilityStatus::tryFrom($data['status'] ?? AvailabilityStatus::Available->value)
            ?? throw ValidationException::withMessages([
                'status' => ['Invalid availability status.'],
            ]);

        $lockEnd = $endDate ?? $startDate;
        $this->monthLock->assertRangeIsEditable($house, $startDate, $lockEnd);

        $this->assertNoOverlap($house->id, $userId, $startDate, $endDate);

        return DB::transaction(function () use ($house, $userId, $startDate, $endDate, $status, $actor) {
            return MemberAvailabilityPeriod::query()->create([
                'house_id' => $house->id,
                'user_id' => $userId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status,
                'created_by' => $actor->id,
            ])->load('user');
        });
    }

    /**
     * @return Collection<int, MemberAvailabilityPeriod>
     */
    public function listForUser(House $house, User $actor, int $userId): Collection
    {
        $this->access->assertMember($house, $actor);

        return MemberAvailabilityPeriod::query()
            ->where('house_id', $house->id)
            ->where('user_id', $userId)
            ->with(['user', 'creator'])
            ->orderBy('start_date')
            ->get();
    }

    /**
     * @return Collection<int, MemberAvailabilityPeriod>
     */
    public function listForHouse(House $house, User $actor, ?string $from = null, ?string $to = null): Collection
    {
        $this->access->assertMember($house, $actor);

        $query = MemberAvailabilityPeriod::query()
            ->where('house_id', $house->id)
            ->with(['user', 'creator'])
            ->orderBy('start_date');

        if ($from !== null && $to !== null) {
            $query->overlapping($from, $to);
        }

        return $query->get();
    }

    public function activeDays(House $house, int $userId, Carbon|string $from, Carbon|string $to): int
    {
        return $this->calculator->daysForUser($house, $userId, $from, $to);
    }

    public function calculator(): AvailableDaysCalculator
    {
        return $this->calculator;
    }

    private function assertNoOverlap(int $houseId, int $userId, string $startDate, ?string $endDate): void
    {
        $overlapEnd = $endDate ?? '9999-12-31';

        $exists = MemberAvailabilityPeriod::query()
            ->where('house_id', $houseId)
            ->where('user_id', $userId)
            ->overlapping($startDate, $overlapEnd)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' => ['This availability period overlaps an existing period for the user.'],
            ]);
        }
    }
}
