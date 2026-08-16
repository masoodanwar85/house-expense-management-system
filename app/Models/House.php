<?php

namespace App\Models;

use App\Policies\HousePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UsePolicy(HousePolicy::class)]
class House extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'owner_id',
        'currency',
        'timezone',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(HouseMember::class);
    }

    public function availabilityPeriods(): HasMany
    {
        return $this->hasMany(MemberAvailabilityPeriod::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function monthlySettlements(): HasMany
    {
        return $this->hasMany(MonthlySettlement::class);
    }

    public function settlementPayments(): HasMany
    {
        return $this->hasMany(SettlementPayment::class);
    }
}
