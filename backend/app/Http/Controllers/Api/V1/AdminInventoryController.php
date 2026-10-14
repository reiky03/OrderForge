<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\AdjustInventoryStock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InventoryAdjustmentRequest;
use App\Http\Resources\Api\V1\InventoryResource;
use App\Models\Inventory;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminInventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $inventories = Inventory::query()
            ->with(['product', 'warehouse'])
            ->when(isset($filters['warehouse_id']), fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['product_id']), fn ($query) => $query->where('product_id', $filters['product_id']))
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return ApiResponse::success(
            InventoryResource::collection($inventories)->resolve(),
            $request->attributes->get('request_id'),
            200,
            ['pagination' => [
                'current_page' => $inventories->currentPage(),
                'per_page' => $inventories->perPage(),
                'total' => $inventories->total(),
                'last_page' => $inventories->lastPage(),
            ]],
        );
    }

    public function show(Request $request, Inventory $inventory): JsonResponse
    {
        $inventory->load(['product', 'warehouse']);

        return ApiResponse::success(
            InventoryResource::make($inventory)->resolve(),
            $request->attributes->get('request_id'),
        );
    }

    public function adjust(InventoryAdjustmentRequest $request, Inventory $inventory, AdjustInventoryStock $action): JsonResponse
    {
        $actor = $request->user();

        abort_unless($actor instanceof User, 401);

        $inventory = $action->handle(
            $inventory,
            $actor,
            $request->validated(),
            $request->attributes->get('request_id'),
        );

        return ApiResponse::success(
            InventoryResource::make($inventory)->resolve(),
            $request->attributes->get('request_id'),
        );
    }
}
