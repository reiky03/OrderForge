<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_active_products_with_available_stock(): void
    {
        $product = Product::factory()->create(['name' => 'Keyboard']);
        $warehouse = Warehouse::factory()->create();
        Inventory::factory()->for($product)->for($warehouse)->create(['on_hand' => 8, 'reserved' => 3]);
        Product::factory()->create(['status' => 'INACTIVE']);

        $this->getJson('/api/v1/products?search=Keyboard')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.available_stock', 5)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonStructure(['request_id', 'data' => [['sku', 'price', 'currency', 'available_stock']]]);
    }

    public function test_catalog_cache_contains_only_serializable_response_data(): void
    {
        $product = Product::factory()->create(['name' => 'Cached Keyboard']);
        Inventory::factory()->for($product)->for(Warehouse::factory()->create())
            ->create(['on_hand' => 4, 'reserved' => 1]);

        $path = '/api/v1/products?search=Cached+Keyboard';
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.available_stock', 3);
        $this->getJson($path)->assertOk()->assertJsonPath('data.0.available_stock', 3);

        $key = 'products:v2:index:'.hash('sha256', json_encode(['Cached Keyboard', 20, 1], JSON_THROW_ON_ERROR));
        $cached = Cache::tags(['catalog'])->get($key);
        $this->assertIsArray($cached);
        $this->assertSame($product->id, $cached['data'][0]['id']);
        $this->assertSame(1, $cached['pagination']['total']);
    }

    public function test_product_detail_returns_not_found_for_inactive_products(): void
    {
        $product = Product::factory()->create(['status' => 'INACTIVE']);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_admin_adjustment_updates_inventory_and_writes_ledger_and_audit(): void
    {
        [$inventory, $admin] = $this->inventoryForAdmin(['on_hand' => 10, 'reserved' => 2]);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'INCREASE',
            'quantity' => 5,
            'reason' => 'Restock shipment 8841',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.on_hand', 15)
            ->assertJsonPath('data.reserved', 2)
            ->assertJsonPath('data.available', 13)
            ->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('inventory_movements', [
            'inventory_id' => $inventory->id,
            'type' => 'ADJUSTMENT',
            'quantity' => 5,
            'balance_after' => 15,
            'reason' => 'Restock shipment 8841',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_type' => 'USER',
            'actor_id' => $admin->id,
            'action' => 'INVENTORY_ADJUSTED',
            'entity_id' => (string) $inventory->id,
        ]);
    }

    public function test_adjustment_cannot_reduce_stock_below_reservations(): void
    {
        [$inventory, $admin] = $this->inventoryForAdmin(['on_hand' => 10, 'reserved' => 4]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'DECREASE',
            'quantity' => 7,
            'reason' => 'Damaged items',
        ])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK');

        $this->assertDatabaseHas('inventories', ['id' => $inventory->id, 'on_hand' => 10, 'reserved' => 4, 'version' => 0]);
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_correction_sets_the_count_without_violating_reserved_quantity(): void
    {
        [$inventory, $admin] = $this->inventoryForAdmin(['on_hand' => 10, 'reserved' => 4]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'CORRECTION',
            'quantity' => 6,
            'reason' => 'Cycle count',
        ])->assertOk()->assertJsonPath('data.on_hand', 6)->assertJsonPath('data.available', 2);

        $this->assertDatabaseHas('inventory_movements', ['inventory_id' => $inventory->id, 'quantity' => -4, 'balance_after' => 6]);
    }

    public function test_customer_cannot_read_or_adjust_admin_inventory(): void
    {
        [$inventory] = $this->inventoryForAdmin();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'INCREASE', 'quantity' => 1, 'reason' => 'Not allowed',
        ])->assertForbidden();
    }

    public function test_inventory_adjustment_requires_authentication_and_valid_input(): void
    {
        [$inventory, $admin] = $this->inventoryForAdmin();

        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'UNKNOWN', 'quantity' => 0, 'reason' => '',
        ])->assertUnauthorized();

        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'UNKNOWN', 'quantity' => 0, 'reason' => '',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_inventory_stock_cache_is_invalidated_after_adjustment(): void
    {
        [$inventory, $admin] = $this->inventoryForAdmin(['on_hand' => 4, 'reserved' => 0]);
        $this->getJson("/api/v1/products/{$inventory->product_id}")->assertJsonPath('data.available_stock', 4);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjustments", [
            'type' => 'INCREASE', 'quantity' => 3, 'reason' => 'Cache invalidation check',
        ])->assertOk();

        $this->getJson("/api/v1/products/{$inventory->product_id}")->assertJsonPath('data.available_stock', 7);
    }

    public function test_inventory_movement_ledger_is_immutable(): void
    {
        $movement = InventoryMovement::factory()->create();
        $this->expectException(QueryException::class);

        InventoryMovement::query()->whereKey($movement->id)->update(['reason' => 'rewritten history']);
    }

    /**
     * @param  array{on_hand?: int, reserved?: int}  $stock
     * @return array{Inventory, User}
     */
    private function inventoryForAdmin(array $stock = []): array
    {
        $inventory = Inventory::factory()->create($stock);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'ADMIN'])->save();

        return [$inventory, $admin];
    }
}
