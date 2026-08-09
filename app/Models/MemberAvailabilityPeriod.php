<?php

namespace App\Models;

use App\Enums\AvailabilityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MemberAvailabilityPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'house_id',
        'user_id',
        'start_date',
        'end_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => AvailabilityStatus::class,
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOverlapping(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();

        return $query
            ->whereDate('start_date', '<=', $to)
            ->where(function (Builder $builder) use ($from) {
                $builder->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $from);
            });
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', AvailabilityStatus::Available);
    }

    /**
     * Inclusive overlap days with [rangeStart, rangeEnd].
     */
    public function overlapDays(Carbon|string $rangeStart, Carbon|string $rangeEnd): int
    {
        $rangeStart = Carbon::parse($rangeStart)->startOfDay();
        $rangeEnd = Carbon::parse($rangeEnd)->startOfDay();
        $periodStart = $this->start_date->copy()->startOfDay();
        $periodEnd = ($this->end_date ?? $rangeEnd)->copy()->startOfDay();

        $overlapStart = $periodStart->greaterThan($rangeStart) ? $periodStart : $rangeStart;
        $overlapEnd = $periodEnd->lessThan($rangeEnd) ? $periodEnd : $rangeEnd;

        if ($overlapStart->greaterThan($overlapEnd)) {
            return 0;
        }

        return (int) $overlapStart->diffInDays($overlapEnd) + 1;
    }
}
