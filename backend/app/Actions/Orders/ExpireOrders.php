<?php

namespace App\Actions\Orders;

use App\Actions\Outbox\RecordOutboxEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderStatusChanged;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StockReservation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class ExpireOrders
{
    public function __construct(private readonly RecordOutboxEvent $outboxWriter) {}

    /** @return int Number of orders transitioned to EXPIRED. */
    public function handle(int $batchSize = 100, ?string $requestId = null): int
    {
        $expiredIds = [];
        Order::query()->where('status', OrderStatus::PendingPayment)->where('expires_at', '<=', now())
            ->orderBy('id')->limit($batchSize)->pluck('id')->each(function (int $orderId) use (&$expiredIds, $requestId): void {
                if ($this->expireOne($orderId, $requestId)) {
                    $expiredIds[] = $orderId;
                }
            });

        if ($expiredIds !== []) {
            Cache::tags(['catalog'])->flush();
        }

        return count($expiredIds);
    }

    private function expireOne(int $orderId, ?string $requestId): bool
    {
        return DB::transaction(function () use ($orderId, $requestId): bool {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();
            if ($order === null || $order->status !== OrderStatus::PendingPayment || $order->expires_at?->isFuture()) {
                return false;
            }
            $payment = Payment::query()->where('order_id', $orderId)->lockForUpdate()->first();

            $reservations = StockReservation::query()->where('order_id', $order->id)->where('status', 'ACTIVE')
                ->orderBy('inventory_id')->lockForUpdate()->get();
            $inventories = Inventory::query()->whereIn('id', $reservations->pluck('inventory_id')->all())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($reservations as $reservation) {
                /** @var Inventory|null $inventory */
                $inventory = $inventories->get($reservation->inventory_id);
                if ($inventory === null || $inventory->reserved < $reservation->quantity) {
                    throw new LogicException('An active stock reservation is inconsistent with inventory.');
                }
                $inventory->forceFill(['reserved' => $inventory->reserved - $reservation->quantity, 'version' => $inventory->version + 1])->save();
                $reservation->forceFill(['status' => 'RELEASED', 'released_at' => now()])->save();
                InventoryMovement::query()->create([
                    'inventory_id' => $inventory->id, 'type' => 'RELEASE', 'quantity' => 0, 'reserved_delta' => -$reservation->quantity,
                    'reference_type' => 'order', 'reference_id' => (string) $order->id, 'balance_after' => $inventory->on_hand, 'reason' => 'Order expired',
                ]);
                $this->outboxWriter->handle('inventory.released', $order, ['product_id' => $reservation->product_id, 'warehouse_id' => $reservation->warehouse_id, 'quantity' => $reservation->quantity], $requestId);
            }

            $order->forceFill(['status' => OrderStatus::Expired])->save();
            OrderStatusChanged::dispatch($order->id, OrderStatus::Expired->value, $payment?->status->value, $requestId);
            if ($payment?->status === PaymentStatus::Pending) {
                $payment->forceFill(['status' => PaymentStatus::Expired])->save();
                $this->outboxWriter->handle('payment.expired', $payment, ['order_id' => $order->id], $requestId);
            }
            AuditLog::query()->create([
                'actor_type' => 'SYSTEM', 'actor_id' => null, 'action' => 'ORDER_EXPIRED', 'entity_type' => 'order',
                'entity_id' => (string) $order->id, 'metadata' => ['order_number' => $order->order_number, 'released_reservations' => $reservations->count()],
                'request_id' => $requestId,
            ]);
            $this->outboxWriter->handle('order.expired', $order, ['order_number' => $order->order_number, 'released_reservations' => $reservations->count()], $requestId);

            return true;
        });
    }
}
