<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'expense_id',
        'user_id',
        'amount',
        'allocation_details',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'allocation_details' => 'array',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
