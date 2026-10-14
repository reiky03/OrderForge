<?php

namespace App\Actions\Orders;

use App\Actions\Outbox\RecordOutboxEvent;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidOrderStateException;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class CancelOrder
{
    public function __construct(private readonly RecordOutboxEvent $outboxWriter) {}

    public function handle(Order $order, User $customer, ?string $requestId): Order
    {
        return DB::transaction(function () use ($order, $customer, $requestId): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->customer_id !== $customer->id || $lockedOrder->status !== OrderStatus::PendingPayment) {
                throw new InvalidOrderStateException('Only the customer can cancel an order that is awaiting payment.');
            }

            $reservations = StockReservation::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'ACTIVE')
                ->orderBy('inventory_id')
                ->lockForUpdate()
                ->get();
            $inventories = Inventory::query()
                ->whereIn('id', $reservations->pluck('inventory_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($reservations as $reservation) {
                /** @var Inventory|null $inventory */
                $inventory = $inventories->get($reservation->inventory_id);

                if ($inventory === null || $inventory->reserved < $reservation->quantity) {
                    throw new LogicException('An active stock reservation is inconsistent with inventory.');
                }

                $inventory->forceFill([
                    'reserved' => $inventory->reserved - $reservation->quantity,
                    'version' => $inventory->version + 1,
                ])->save();

                $reservation->forceFill(['status' => 'RELEASED', 'released_at' => now()])->save();

                InventoryMovement::query()->create([
                    'inventory_id' => $inventory->id,
                    'type' => 'RELEASE',
                    'quantity' => 0,
                    'reserved_delta' => -$reservation->quantity,
                    'reference_type' => 'order',
                    'reference_id' => (string) $lockedOrder->id,
                    'balance_after' => $inventory->on_hand,
                    'reason' => 'Order cancelled',
                ]);

                $this->outboxWriter->handle('inventory.released', $lockedOrder, [
                    'product_id' => $reservation->product_id,
                    'warehouse_id' => $reservation->warehouse_id,
                    'quantity' => $reservation->quantity,
                ], $requestId);
            }

            if ($reservations->isEmpty()) {
                throw new InvalidOrderStateException('The order has no active stock reservations.');
            }

            $lockedOrder->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();
            OrderStatusChanged::dispatch($lockedOrder->id, OrderStatus::Cancelled->value, null, $requestId);
            AuditLog::query()->create([
                'actor_type' => 'USER',
                'actor_id' => $customer->id,
                'action' => 'ORDER_CANCELLED',
                'entity_type' => 'order',
                'entity_id' => (string) $lockedOrder->id,
                'metadata' => ['order_number' => $lockedOrder->order_number],
                'request_id' => $requestId,
            ]);
            $this->outboxWriter->handle('order.cancelled', $lockedOrder, [
                'order_number' => $lockedOrder->order_number,
                'customer_id' => $customer->id,
            ], $requestId);
            Cache::tags(['catalog'])->flush();

            return $lockedOrder->load('items');
        });
    }
}
