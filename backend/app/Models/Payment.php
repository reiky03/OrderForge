<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PaymentStatus $status
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = ['order_id', 'payment_reference', 'provider', 'amount', 'currency', 'status', 'paid_at', 'failed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
