<?php

namespace App\Actions\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdjustInventoryStock
{
    /**
     * @param  array{type: string, quantity: int, reason: string}  $adjustment
     */
    public function handle(Inventory $inventory, User $actor, array $adjustment, ?string $requestId): Inventory
    {
        return DB::transaction(function () use ($inventory, $actor, $adjustment, $requestId): Inventory {
            $lockedInventory = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $before = $lockedInventory->on_hand;

            $after = match ($adjustment['type']) {
                'INCREASE' => $before + $adjustment['quantity'],
                'DECREASE' => $before - $adjustment['quantity'],
                'CORRECTION' => $adjustment['quantity'],
                default => throw new \InvalidArgumentException('Unsupported inventory adjustment type.'),
            };

            if ($after < $lockedInventory->reserved) {
                throw new InsufficientStockException;
            }

            $delta = $after - $before;

            if ($delta === 0) {
                throw ValidationException::withMessages(['quantity' => 'The adjustment must change the on-hand stock.']);
            }

            $lockedInventory->forceFill([
                'on_hand' => $after,
                'version' => $lockedInventory->version + 1,
            ])->save();

            InventoryMovement::query()->create([
                'inventory_id' => $lockedInventory->id,
                'type' => 'ADJUSTMENT',
                'quantity' => $delta,
                'reference_type' => 'inventory_adjustment',
                'reference_id' => (string) $lockedInventory->id,
                'balance_after' => $after,
                'reason' => $adjustment['reason'],
            ]);

            AuditLog::query()->create([
                'actor_type' => 'USER',
                'actor_id' => $actor->id,
                'action' => 'INVENTORY_ADJUSTED',
                'entity_type' => 'inventory',
                'entity_id' => (string) $lockedInventory->id,
                'metadata' => [
                    'adjustment_type' => $adjustment['type'],
                    'quantity_delta' => $delta,
                    'on_hand_before' => $before,
                    'on_hand_after' => $after,
                    'reserved' => $lockedInventory->reserved,
                    'reason' => $adjustment['reason'],
                ],
                'request_id' => $requestId,
            ]);

            Cache::tags(['catalog'])->flush();

            return $lockedInventory->load(['product', 'warehouse']);
        });
    }
}
