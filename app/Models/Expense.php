<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Policies\ExpensePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[UsePolicy(ExpensePolicy::class)]
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'house_id',
        'expense_category_id',
        'paid_by',
        'title',
        'description',
        'amount',
        'expense_date',
        'period_start_date',
        'period_end_date',
        'status',
        'allocation_rule_id',
        'created_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'period_start_date' => 'date',
            'period_end_date' => 'date',
            'status' => ExpenseStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocationRule(): BelongsTo
    {
        return $this->belongsTo(AllocationRule::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ExpenseAllocation::class);
    }

    public function coverageStart(): Carbon
    {
        return ($this->period_start_date ?? $this->expense_date)->copy()->startOfDay();
    }

    public function coverageEnd(): Carbon
    {
        return ($this->period_end_date ?? $this->expense_date)->copy()->startOfDay();
    }

    public function isConfirmed(): bool
    {
        return $this->status === ExpenseStatus::Confirmed;
    }
}
