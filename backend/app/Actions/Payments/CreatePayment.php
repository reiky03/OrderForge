<?php

namespace App\Actions\Payments;

use App\Actions\Outbox\RecordOutboxEvent;
use App\Enums\OrderStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InvalidOrderStateException;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePayment
{
    public function __construct(private readonly RecordOutboxEvent $outboxWriter) {}

    /** @return array{status:int,data:array<string,mixed>,request_id:?string,replayed:bool} */
    public function handle(Order $order, User $customer, string $key, ?string $requestId): array
    {
        $hash = hash('sha256', 'create_payment:'.$order->id);

        return DB::transaction(function () use ($order, $customer, $key, $hash, $requestId): array {
            $record = IdempotencyKey::query()->where('user_id', $customer->id)->where('key', $key)->lockForUpdate()->first();
            if ($record !== null && $record->expires_at->isFuture()) {
                if (! hash_equals($record->request_hash, $hash) || $record->status !== 'COMPLETED' || $record->response_body === null) {
                    throw new IdempotencyConflictException;
                }

                return ['status' => $record->response_status ?? 201, 'data' => $record->response_body['data'], 'request_id' => $record->response_body['request_id'] ?? null, 'replayed' => true];
            }
            $record?->delete();
            IdempotencyKey::query()->insertOrIgnore([
                'user_id' => $customer->id, 'key' => $key, 'request_hash' => $hash, 'status' => 'PROCESSING',
                'expires_at' => now()->addHours(config('orderforge.idempotency_ttl_hours')), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $idempotency = IdempotencyKey::query()->where('user_id', $customer->id)->where('key', $key)->lockForUpdate()->firstOrFail();
            if (! hash_equals($idempotency->request_hash, $hash) || $idempotency->status === 'COMPLETED') {
                if ($idempotency->status === 'COMPLETED' && hash_equals($idempotency->request_hash, $hash) && $idempotency->response_body !== null) {
                    return ['status' => $idempotency->response_status ?? 201, 'data' => $idempotency->response_body['data'], 'request_id' => $idempotency->response_body['request_id'] ?? null, 'replayed' => true];
                }
                throw new IdempotencyConflictException;
            }

            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->customer_id !== $customer->id || $lockedOrder->status !== OrderStatus::PendingPayment || $lockedOrder->expires_at?->isPast()) {
                throw new InvalidOrderStateException('Payment can only be created for an active pending order.');
            }
            $payment = Payment::query()->where('order_id', $lockedOrder->id)->lockForUpdate()->first();
            $wasCreated = $payment === null;
            $payment ??= Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'payment_reference' => 'PAY-'.strtoupper((string) Str::ulid()),
                'provider' => 'mock',
                'amount' => $lockedOrder->total,
                'currency' => $lockedOrder->currency,
                'status' => 'PENDING',
            ]);
            $data = PaymentResource::make($payment)->resolve(request());
            if ($wasCreated) {
                $this->outboxWriter->handle('payment.created', $payment, $data, $requestId);
            }
            $idempotency->forceFill(['status' => 'COMPLETED', 'response_status' => 201, 'response_body' => ['data' => $data, 'request_id' => $requestId]])->save();

            return ['status' => 201, 'data' => $data, 'request_id' => $requestId, 'replayed' => false];
        });
    }
}
