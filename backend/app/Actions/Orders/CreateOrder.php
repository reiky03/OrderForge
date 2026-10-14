<?php

namespace App\Actions\Orders;

use App\Actions\Outbox\RecordOutboxEvent;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\IdempotencyKey;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    public function __construct(private readonly RecordOutboxEvent $outboxWriter) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @return array{status: int, data: array<string, mixed>, request_id: ?string, replayed: bool}
     */
    public function handle(User $customer, string $idempotencyKey, array $items, ?string $requestId): array
    {
        $normalizedItems = collect($items)
            ->map(fn (array $item): array => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']])
            ->sortBy('product_id')
            ->values()
            ->all();
        /** @var list<array{product_id: int, quantity: int}> $normalizedItems */
        $requestHash = hash('sha256', json_encode($normalizedItems, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($customer, $idempotencyKey, $normalizedItems, $requestHash, $requestId): array {
            $existing = IdempotencyKey::query()
                ->where('user_id', $customer->id)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->expires_at->isFuture()) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException;
                }

                if ($existing->status !== 'COMPLETED' || $existing->response_status === null || $existing->response_body === null) {
                    throw new IdempotencyConflictException('An equivalent request with this key is still being processed.');
                }

                return [
                    'status' => $existing->response_status,
                    'data' => $existing->response_body['data'],
                    'request_id' => $existing->response_body['request_id'] ?? null,
                    'replayed' => true,
                ];
            }

            if ($existing !== null) {
                $existing->delete();
            }

            $inserted = IdempotencyKey::query()->insertOrIgnore([
                'user_id' => $customer->id,
                'key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'PROCESSING',
                'expires_at' => now()->addHours(config('orderforge.idempotency_ttl_hours')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                $existing = IdempotencyKey::query()
                    ->where('user_id', $customer->id)
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new IdempotencyConflictException;
                }

                if ($existing->status !== 'COMPLETED' || $existing->response_status === null || $existing->response_body === null) {
                    throw new IdempotencyConflictException('An equivalent request with this key is still being processed.');
                }

                return [
                    'status' => $existing->response_status,
                    'data' => $existing->response_body['data'],
                    'request_id' => $existing->response_body['request_id'] ?? null,
                    'replayed' => true,
                ];
            }

            $products = Product::query()
                ->active()
                ->whereIn('id', array_column($normalizedItems, 'product_id'))
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($normalizedItems)) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable.']);
            }

            $lines = [];
            $currency = null;
            $subtotalMinorUnits = 0;

            foreach ($normalizedItems as $item) {
                /** @var Product $product */
                $product = $products->get($item['product_id']);
                $currency ??= $product->currency;

                if ($product->currency !== $currency) {
                    throw ValidationException::withMessages(['items' => 'All products in an order must use the same currency.']);
                }

                $inventories = Inventory::query()
                    ->where('product_id', $product->id)
                    ->whereHas('warehouse', fn ($query) => $query->where('status', 'ACTIVE'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $inventory = $inventories->first(fn (Inventory $candidate): bool => $candidate->available >= $item['quantity']);

                if ($inventory === null) {
                    if (! app()->environment('testing')) {
                        try {
                            Redis::incr('orderforge:metrics:inventory_reservation_failures_total');
                        } catch (\Throwable) {
                            // Counters are best-effort and never affect checkout correctness.
                        }
                    }
                    throw new InsufficientStockException;
                }

                $unitPriceMinorUnits = $this->toMinorUnits((string) $product->getRawOriginal('price'));
                $lineSubtotalMinorUnits = $unitPriceMinorUnits * $item['quantity'];
                $subtotalMinorUnits += $lineSubtotalMinorUnits;
                $lines[] = [
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity' => $item['quantity'],
                    'unit_price' => $this->fromMinorUnits($unitPriceMinorUnits),
                    'subtotal' => $this->fromMinorUnits($lineSubtotalMinorUnits),
                ];
            }

            $expirationTime = now()->addMinutes(config('orderforge.order_expiration_minutes'));
            $order = Order::query()->create([
                'order_number' => 'OF-'.strtoupper((string) Str::ulid()),
                'customer_id' => $customer->id,
                'status' => 'PENDING_PAYMENT',
                'subtotal' => $this->fromMinorUnits($subtotalMinorUnits),
                'total' => $this->fromMinorUnits($subtotalMinorUnits),
                'currency' => $currency,
                'expires_at' => $expirationTime,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                /** @var Inventory $inventory */
                $inventory = $line['inventory'];
                $quantity = $line['quantity'];

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'inventory_id' => $inventory->id,
                    'product_id' => $product->id,
                    'sku_snapshot' => $product->sku,
                    'name_snapshot' => $product->name,
                    'price_snapshot' => $line['unit_price'],
                    'quantity' => $quantity,
                    'subtotal' => $line['subtotal'],
                ]);

                $inventory->forceFill([
                    'reserved' => $inventory->reserved + $quantity,
                    'version' => $inventory->version + 1,
                ])->save();

                StockReservation::query()->create([
                    'order_id' => $order->id,
                    'inventory_id' => $inventory->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'quantity' => $quantity,
                    'status' => 'ACTIVE',
                    'expires_at' => $expirationTime,
                ]);

                InventoryMovement::query()->create([
                    'inventory_id' => $inventory->id,
                    'type' => 'RESERVE',
                    'quantity' => 0,
                    'reserved_delta' => $quantity,
                    'reference_type' => 'order',
                    'reference_id' => (string) $order->id,
                    'balance_after' => $inventory->on_hand,
                    'reason' => 'Order stock reservation',
                ]);

                $this->outboxWriter->handle('inventory.reserved', $order, [
                    'product_id' => $product->id,
                    'warehouse_id' => $inventory->warehouse_id,
                    'quantity' => $quantity,
                ], $requestId);
            }

            $order->load('items');
            OrderStatusChanged::dispatch($order->id, OrderStatus::PendingPayment->value, null, $requestId);
            $data = OrderResource::make($order)->resolve(request());
            $this->outboxWriter->handle('order.created', $order, $data, $requestId);

            $idempotencyRecord = IdempotencyKey::query()
                ->where('user_id', $customer->id)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();
            $idempotencyRecord->forceFill([
                'status' => 'COMPLETED',
                'response_status' => 201,
                'response_body' => ['data' => $data, 'request_id' => $requestId],
            ])->save();

            Cache::tags(['catalog'])->flush();

            return ['status' => 201, 'data' => $data, 'request_id' => $requestId, 'replayed' => false];
        });
    }

    private function toMinorUnits(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
