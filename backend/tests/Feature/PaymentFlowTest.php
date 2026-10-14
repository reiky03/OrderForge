<?php

namespace Tests\Feature;

use App\Actions\Orders\ExpireOrders;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_payment_commits_reserved_stock_and_duplicate_webhook_has_no_side_effects(): void
    {
        [$customer, $order, $inventory] = $this->pendingOrder();
        Sanctum::actingAs($customer);
        $payment = $this->createPayment($order);

        $response = $this->postJson("/api/v1/payments/{$payment->id}/mock-success", ['duplicate_deliveries' => 3]);
        $response->assertOk()->assertJsonPath('data.payment.status', PaymentStatus::Succeeded->value)
            ->assertJsonPath('data.webhook.deliveries', 3)->assertJsonPath('data.webhook.duplicates', 2);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Paid->value]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Succeeded->value]);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 8, 'reserved' => 0, 'version' => 2]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'COMMITTED']);
        $this->assertDatabaseHas('inventory_movements', ['inventory_id' => $inventory->id, 'type' => 'SALE', 'quantity' => -2, 'reserved_delta' => -2]);
        $this->assertSame(1, PaymentWebhook::query()->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'order.paid']);
    }

    public function test_failed_payment_is_recorded_and_leaves_reservation_for_expiration_recovery(): void
    {
        [$customer, $order, $inventory] = $this->pendingOrder();
        Sanctum::actingAs($customer);
        $payment = $this->createPayment($order);

        $this->postJson("/api/v1/payments/{$payment->id}/mock-failure")->assertOk()
            ->assertJsonPath('data.payment.status', PaymentStatus::Failed->value);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::PendingPayment->value]);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 10, 'reserved' => 2]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'payment.failed']);
    }

    public function test_webhook_rejects_invalid_signature_without_recording_event(): void
    {
        [$customer, $order] = $this->pendingOrder();
        Sanctum::actingAs($customer);
        $payment = $this->createPayment($order);
        $payload = ['event_id' => (string) Str::uuid(), 'type' => 'payment.succeeded', 'payment_reference' => $payment->payment_reference, 'amount' => '200.00', 'currency' => 'IDR'];

        $this->call('POST', '/api/v1/payments/webhook/mock', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(), 'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=invalid',
        ], json_encode($payload, JSON_THROW_ON_ERROR))->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_WEBHOOK_SIGNATURE');

        $this->assertSame(0, PaymentWebhook::query()->count());
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Pending->value]);
    }

    public function test_payment_creation_is_idempotent_and_owner_scoped(): void
    {
        [$customer, $order] = $this->pendingOrder();
        Sanctum::actingAs($customer);
        $headers = ['Idempotency-Key' => 'c8e1a22a-eab5-4c17-a77f-8bd10c381b1f'];
        $first = $this->postJson("/api/v1/orders/{$order->id}/payments", [], $headers)->assertCreated();
        $second = $this->postJson("/api/v1/orders/{$order->id}/payments", [], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_expiration_releases_reserved_stock_once_and_records_events(): void
    {
        [, $order, $inventory] = $this->pendingOrder();
        $payment = $this->createPayment($order);
        $order->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->assertSame(1, app(ExpireOrders::class)->handle());
        $this->assertSame(0, app(ExpireOrders::class)->handle());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Expired->value]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Expired->value]);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 10, 'reserved' => 0, 'version' => 2]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'RELEASED']);
        $this->assertDatabaseHas('inventory_movements', ['inventory_id' => $inventory->id, 'type' => 'RELEASE', 'quantity' => 0, 'reserved_delta' => -2]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'order.expired']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ORDER_EXPIRED', 'entity_id' => (string) $order->id]);
        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_private_order_channel_is_authorized_only_for_owner_or_admin(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        [$owner, $order] = $this->pendingOrder();
        $payload = ['socket_id' => '1234.5678', 'channel_name' => 'private-orders.'.$order->id];
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'CUSTOMER']));
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN']));
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertOk();
    }

    public function test_mock_provider_timeout_leaves_payment_and_reservation_pending(): void
    {
        [$customer, $order, $inventory] = $this->pendingOrder();
        $payment = $this->createPayment($order);
        Sanctum::actingAs($customer);

        $this->postJson("/api/v1/payments/{$payment->id}/mock-timeout", [])
            ->assertStatus(504)->assertJsonPath('error.code', 'MOCK_PROVIDER_TIMEOUT');
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => PaymentStatus::Pending->value]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::PendingPayment->value]);
        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 10, 'reserved' => 2]);
    }

    /** @return array{User,Order,Inventory} */
    private function pendingOrder(): array
    {
        $customer = User::factory()->create(['role' => 'CUSTOMER']);
        $product = Product::factory()->create(['price' => '100.00', 'currency' => 'IDR']);
        $warehouse = Warehouse::factory()->create(['status' => 'ACTIVE']);
        $inventory = Inventory::factory()->for($product)->for($warehouse)->create(['on_hand' => 10, 'reserved' => 2, 'version' => 1]);
        $order = Order::factory()->for($customer, 'customer')->create(['subtotal' => '200.00', 'total' => '200.00']);
        $order->items()->create(['product_id' => $product->id, 'sku_snapshot' => $product->sku, 'name_snapshot' => $product->name, 'price_snapshot' => '100.00', 'quantity' => 2, 'subtotal' => '200.00']);
        StockReservation::factory()->for($order)->for($inventory)->create(['quantity' => 2]);

        return [$customer, $order, $inventory];
    }

    private function createPayment(Order $order): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id, 'payment_reference' => 'PAY-'.strtoupper((string) Str::ulid()), 'provider' => 'mock',
            'amount' => $order->total, 'currency' => $order->currency, 'status' => PaymentStatus::Pending,
        ]);
    }
}
