<?php

namespace App\Models;

use App\Enums\MonthlySettlementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlySettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'house_id',
        'year',
        'month',
        'status',
        'total_expenses',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => MonthlySettlementStatus::class,
            'total_expenses' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->status === MonthlySettlementStatus::Closed;
    }
}
