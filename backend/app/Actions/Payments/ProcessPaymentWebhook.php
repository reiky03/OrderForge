<?php

namespace App\Actions\Payments;

use App\Actions\Outbox\RecordOutboxEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProcessPaymentWebhook
{
    public function __construct(private readonly RecordOutboxEvent $outboxWriter) {}

    /** @return array{event_id:string,duplicate:bool,status:string} */
    public function handle(string $rawBody, ?string $timestamp, ?string $signature, ?string $requestId): array
    {
        $this->verifySignature($rawBody, $timestamp, $signature);
        $payload = Validator::make(json_decode($rawBody, true) ?? [], [
            'event_id' => ['required', 'uuid'],
            'type' => ['required', 'in:payment.succeeded,payment.failed'],
            'payment_reference' => ['required', 'string', 'max:64'],
            'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'currency' => ['required', 'alpha', 'size:3'],
        ])->validate();

        return DB::transaction(function () use ($payload, $requestId): array {
            $created = PaymentWebhook::query()->insertOrIgnore([
                'provider' => 'mock', 'external_event_id' => $payload['event_id'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'RECEIVED', 'created_at' => now(),
            ]);
            if ($created === 0) {
                return ['event_id' => $payload['event_id'], 'duplicate' => true, 'status' => 'DUPLICATE'];
            }
            $webhook = PaymentWebhook::query()->where('provider', 'mock')->where('external_event_id', $payload['event_id'])->lockForUpdate()->firstOrFail();
            $paymentLookup = Payment::query()->where('payment_reference', $payload['payment_reference'])->first();
            if ($paymentLookup === null) {
                $webhook->forceFill(['status' => 'IGNORED', 'processed_at' => now()])->save();

                return ['event_id' => $payload['event_id'], 'duplicate' => false, 'status' => 'IGNORED'];
            }
            $order = Order::query()->whereKey($paymentLookup->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($paymentLookup->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::Pending || $order->status !== OrderStatus::PendingPayment || $order->expires_at?->isPast()
                || $this->minorUnits((string) $payment->getRawOriginal('amount')) !== $this->minorUnits($payload['amount'])
                || $payment->currency !== strtoupper($payload['currency'])) {
                $webhook->forceFill(['status' => 'IGNORED', 'processed_at' => now()])->save();

                return ['event_id' => $payload['event_id'], 'duplicate' => false, 'status' => 'IGNORED'];
            }

            if ($payload['type'] === 'payment.failed') {
                $payment->forceFill(['status' => PaymentStatus::Failed, 'failed_at' => now()])->save();
                OrderStatusChanged::dispatch($order->id, $order->status->value, PaymentStatus::Failed->value, $requestId);
                $this->outboxWriter->handle('payment.failed', $payment, ['order_id' => $order->id], $requestId);
            } else {
                $reservations = StockReservation::query()->where('order_id', $order->id)->where('status', 'ACTIVE')->orderBy('inventory_id')->lockForUpdate()->get();
                if ($reservations->isEmpty()) {
                    throw new \RuntimeException('The order has no active inventory reservation to commit.');
                }
                foreach ($reservations as $reservation) {
                    $inventory = Inventory::query()->whereKey($reservation->inventory_id)->lockForUpdate()->firstOrFail();
                    if ($inventory->on_hand < $reservation->quantity || $inventory->reserved < $reservation->quantity) {
                        throw new \RuntimeException('Reserved inventory does not match the payment commitment.');
                    }
                    $inventory->forceFill(['on_hand' => $inventory->on_hand - $reservation->quantity, 'reserved' => $inventory->reserved - $reservation->quantity, 'version' => $inventory->version + 1])->save();
                    $reservation->forceFill(['status' => 'COMMITTED'])->save();
                    InventoryMovement::query()->create([
                        'inventory_id' => $inventory->id, 'type' => 'SALE', 'quantity' => -$reservation->quantity, 'reserved_delta' => -$reservation->quantity,
                        'reference_type' => 'order', 'reference_id' => (string) $order->id, 'balance_after' => $inventory->on_hand, 'reason' => 'Payment confirmed',
                    ]);
                }
                $payment->forceFill(['status' => PaymentStatus::Succeeded, 'paid_at' => now()])->save();
                $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
                OrderStatusChanged::dispatch($order->id, OrderStatus::Paid->value, PaymentStatus::Succeeded->value, $requestId);
                AuditLog::query()->create(['actor_type' => 'SYSTEM', 'actor_id' => null, 'action' => 'payment.succeeded', 'entity_type' => 'order', 'entity_id' => (string) $order->id, 'metadata' => ['before' => ['status' => OrderStatus::PendingPayment->value], 'after' => ['status' => OrderStatus::Paid->value, 'payment_id' => $payment->id]], 'request_id' => $requestId]);
                $this->outboxWriter->handle('inventory.committed', $order, ['reservations' => $reservations->map(fn (StockReservation $r): array => ['inventory_id' => $r->inventory_id, 'quantity' => $r->quantity])->all()], $requestId);
                $this->outboxWriter->handle('payment.succeeded', $payment, ['order_id' => $order->id], $requestId);
                $this->outboxWriter->handle('order.paid', $order, OrderResource::make($order->load('items'))->resolve(request()), $requestId);
            }
            $webhook->forceFill(['status' => 'PROCESSED', 'processed_at' => now()])->save();

            return ['event_id' => $payload['event_id'], 'duplicate' => false, 'status' => 'PROCESSED'];
        });
    }

    private function verifySignature(string $body, ?string $timestamp, ?string $signature): void
    {
        if ($timestamp === null || ! ctype_digit($timestamp) || $signature === null || abs(time() - (int) $timestamp) > (int) config('orderforge.webhook_timestamp_tolerance_seconds')) {
            throw new InvalidWebhookSignatureException;
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('orderforge.webhook_secret'));
        if (! hash_equals($expected, $signature)) {
            throw new InvalidWebhookSignatureException;
        }
    }

    private function minorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
