<?php

namespace App\Models;

use App\Enums\SettlementPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'house_id',
        'from_user_id',
        'to_user_id',
        'year',
        'month',
        'amount',
        'status',
        'note',
        'recorded_by',
        'confirmed_by',
        'confirmed_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => SettlementPaymentStatus::class,
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isPending(): bool
    {
        return $this->status === SettlementPaymentStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === SettlementPaymentStatus::Confirmed;
    }

    public function forMonthLabel(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
