<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\IdempotencyKey;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_checkout_atomically_reserves_stock_and_records_an_outbox_event(): void
    {
        [$customer, $inventory] = $this->customerAndInventory(7, '125000.50');
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inventory->product_id, 'quantity' => 2]],
        ], ['Idempotency-Key' => 'b5eb7c44-a0de-42b8-a2fd-b7c7f94f9482']);

        $response->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status', OrderStatus::PendingPayment->value)
            ->assertJsonPath('data.subtotal', '250001.00')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.name', 'Mechanical Keyboard');

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'on_hand' => 7,
            'reserved' => 2,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $response->json('data.id'),
            'inventory_id' => $inventory->id,
            'quantity' => 2,
            'status' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'RESERVE',
            'quantity' => 0,
            'reserved_delta' => 2,
            'balance_after' => 7,
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'order.created', 'status' => 'PENDING']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'inventory.reserved', 'status' => 'PENDING']);
        $this->assertDatabaseHas('idempotency_keys', [
            'user_id' => $customer->id,
            'key' => 'b5eb7c44-a0de-42b8-a2fd-b7c7f94f9482',
            'status' => 'COMPLETED',
            'response_status' => 201,
        ]);
    }

    public function test_checkout_idempotency_returns_original_order_without_duplicate_side_effects(): void
    {
        [$customer, $inventory] = $this->customerAndInventory(4);
        Sanctum::actingAs($customer);
        $headers = ['Idempotency-Key' => '4f4f6b90-dc01-47dc-8243-381c0ad60537'];
        $payload = ['items' => [['product_id' => $inventory->product_id, 'quantity' => 1]]];

        $first = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/orders', $payload, $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, StockReservation::query()->count());
        $this->assertSame(2, OutboxEvent::query()->count());
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'reserved' => 1]);
    }

    public function test_reusing_idempotency_key_for_a_different_payload_conflicts(): void
    {
        [$customer, $inventory] = $this->customerAndInventory(5);
        Sanctum::actingAs($customer);
        $headers = ['Idempotency-Key' => '38454e0c-bfbd-4dd5-a962-f102df2e5fa2'];

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inventory->product_id, 'quantity' => 1]],
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inventory->product_id, 'quantity' => 2]],
        ], $headers)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, Order::query()->count());
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'reserved' => 1]);
    }

    public function test_expired_idempotency_key_can_be_reused(): void
    {
        [$customer, $inventory] = $this->customerAndInventory(5);
        $key = '38454e0c-bfbd-4dd5-a962-f102df2e5fa2';
        IdempotencyKey::factory()->for($customer)->create([
            'key' => $key,
            'expires_at' => now()->subSecond(),
        ]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inventory->product_id, 'quantity' => 1]],
        ], ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'false');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame('COMPLETED', IdempotencyKey::query()->where('user_id', $customer->id)->where('key', $key)->firstOrFail()->status);
    }

    public function test_insufficient_stock_rolls_back_every_item_and_idempotency_record(): void
    {
        [$customer, $firstInventory] = $this->customerAndInventory(5);
        $secondInventory = Inventory::factory()->create(['on_hand' => 1, 'reserved' => 0]);
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/orders', [
            'items' => [
                ['product_id' => $firstInventory->product_id, 'quantity' => 2],
                ['product_id' => $secondInventory->product_id, 'quantity' => 2],
            ],
        ], ['Idempotency-Key' => '8f1c9c04-11bb-48f7-99b2-8a431d1f042e'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame(0, IdempotencyKey::query()->count());
        $this->assertSame(0, OutboxEvent::query()->count());
        $this->assertDatabaseHas('inventories', ['id' => $firstInventory->id, 'reserved' => 0]);
        $this->assertDatabaseHas('inventories', ['id' => $secondInventory->id, 'reserved' => 0]);
    }

    public function test_customer_can_cancel_order_and_release_reserved_stock_once(): void
    {
        [$customer, $inventory] = $this->customerAndInventory(6);
        Sanctum::actingAs($customer);
        $created = $this->postJson('/api/v1/orders', [
            'items' => [['product_id' => $inventory->product_id, 'quantity' => 3]],
        ], ['Idempotency-Key' => '0bd4d502-3ac6-4c12-ad28-8e54462eb12b'])->assertCreated();
        $orderId = $created->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 6, 'reserved' => 0, 'version' => 2]);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $orderId,
            'status' => 'RELEASED',
        ]);
        $this->assertNotNull(StockReservation::query()->where('order_id', $orderId)->firstOrFail()->released_at);
        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'RELEASE',
            'quantity' => 0,
            'reserved_delta' => -3,
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'order.cancelled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ORDER_CANCELLED', 'entity_id' => (string) $orderId]);

        $this->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_ORDER_STATE');
        $this->assertSame(2, InventoryMovement::query()->count());
    }

    public function test_customer_order_list_and_detail_are_scoped_to_the_owner(): void
    {
        [$owner, $inventory] = $this->customerAndInventory(3);
        $order = Order::factory()->for($owner, 'customer')->create();
        $order->items()->create([
            'product_id' => $inventory->product_id,
            'sku_snapshot' => 'SKU-1',
            'name_snapshot' => 'Saved name',
            'price_snapshot' => '20.00',
            'quantity' => 1,
            'subtotal' => '20.00',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
    }

    public function test_order_creation_requires_authentication_idempotency_key_and_valid_lines(): void
    {
        $product = Product::factory()->create();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/orders', $payload)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/orders', $payload)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 0]]], [
            'Idempotency-Key' => 'c4aa9300-2e68-47ee-80cf-b8ff354ba7f6',
        ])->assertUnprocessable();
    }

    /** @return array{User, Inventory} */
    private function customerAndInventory(int $stock, string $price = '100.00'): array
    {
        $customer = User::factory()->create();
        $this->assertSame('CUSTOMER', $customer->role);
        $product = Product::factory()->create(['sku' => fake()->unique()->bothify('OF-#####'), 'name' => 'Mechanical Keyboard', 'price' => $price]);
        $warehouse = Warehouse::factory()->create();
        $inventory = Inventory::factory()->for($product)->for($warehouse)->create(['on_hand' => $stock, 'reserved' => 0]);

        return [$customer, $inventory];
    }
}
