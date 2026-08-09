<?php

namespace App\Models;

use App\Enums\AllocationRuleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AllocationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_category_id',
        'rule_type',
        'configuration',
        'effective_from',
        'effective_to',
        'version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => AllocationRuleType::class,
            'configuration' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function coversPeriod(Carbon|string $from, Carbon|string $to): bool
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($this->effective_from->greaterThan($from)) {
            return false;
        }

        if ($this->effective_to === null) {
            return true;
        }

        return $this->effective_to->greaterThanOrEqualTo($to);
    }
}
