<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property OrderStatus $status
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Payment|null $payment
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'order_number', 'customer_id', 'status', 'subtotal', 'total', 'currency', 'expires_at', 'paid_at', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<StockReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** @return HasOne<Payment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
