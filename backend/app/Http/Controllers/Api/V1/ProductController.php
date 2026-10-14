<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $search = trim($filters['search'] ?? '');
        $cacheKey = 'products:v2:index:'.hash('sha256', json_encode([$search, $perPage, $page], JSON_THROW_ON_ERROR));

        $catalog = Cache::tags(['catalog'])->remember($cacheKey, now()->addMinutes(5), function () use ($search, $perPage, $page): array {
            $products = Product::query()
                ->active()
                ->addSelect(['available_stock' => Inventory::query()
                    ->selectRaw('COALESCE(SUM(on_hand - reserved), 0)')
                    ->whereColumn('product_id', 'products.id')])
                ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                }))
                ->orderBy('name')
                ->paginate($perPage, ['*'], 'page', $page);

            return [
                'data' => ProductResource::collection($products)->resolve(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ];
        });

        return ApiResponse::success(
            $catalog['data'],
            $request->attributes->get('request_id'),
            200,
            ['pagination' => $catalog['pagination']],
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $product = Cache::tags(['catalog'])->remember("products:v2:show:{$id}", now()->addMinutes(5), function () use ($id): array {
            $product = Product::query()
                ->active()
                ->addSelect(['available_stock' => Inventory::query()
                    ->selectRaw('COALESCE(SUM(on_hand - reserved), 0)')
                    ->whereColumn('product_id', 'products.id')])
                ->findOrFail($id);

            return ProductResource::make($product)->resolve();
        });

        return ApiResponse::success(
            $product,
            $request->attributes->get('request_id'),
        );
    }
}
