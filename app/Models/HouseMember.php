<?php

namespace App\Models;

use App\Enums\HouseMemberRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class HouseMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'house_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => HouseMemberRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
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

    public function isActive(?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->joined_at->greaterThan($at)) {
            return false;
        }

        return $this->left_at === null || $this->left_at->greaterThanOrEqualTo($at);
    }

    /**
     * Membership overlaps an inclusive calendar date range.
     */
    public function scopeOverlappingPeriod(Builder $query, Carbon|string $from, Carbon|string $to): Builder
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->endOfDay();

        return $query
            ->where('joined_at', '<=', $to)
            ->where(function (Builder $builder) use ($from) {
                $builder->whereNull('left_at')
                    ->orWhere('left_at', '>=', $from);
            });
    }
}
