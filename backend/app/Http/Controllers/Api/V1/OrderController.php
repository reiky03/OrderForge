<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CreateOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customerFromRequest($request);
        $perPage = $request->integer('per_page', 20);
        abort_if($perPage < 1 || $perPage > 100, 422, 'per_page must be between 1 and 100.');
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items', 'payment')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::success(
            OrderResource::collection($orders)->resolve(),
            $request->attributes->get('request_id'),
            200,
            ['pagination' => [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ]],
        );
    }

    public function store(CreateOrderRequest $request, CreateOrder $action): JsonResponse
    {
        $result = $action->handle(
            $this->customerFromRequest($request),
            $request->validated('idempotency_key'),
            $request->validated('items'),
            $request->attributes->get('request_id'),
        );
        $response = ApiResponse::success($result['data'], $result['request_id'], $result['status']);

        return $response->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $order->load('items', 'payment');

        return ApiResponse::success(
            OrderResource::make($order)->resolve(),
            $request->attributes->get('request_id'),
        );
    }

    public function cancel(Request $request, Order $order, CancelOrder $action): JsonResponse
    {
        $cancelledOrder = $action->handle($order, $this->customerFromRequest($request), $request->attributes->get('request_id'));

        return ApiResponse::success(
            OrderResource::make($cancelledOrder)->resolve(),
            $request->attributes->get('request_id'),
        );
    }

    private function customerFromRequest(Request $request): User
    {
        $customer = $request->user();

        abort_unless($customer instanceof User && $customer->role === 'CUSTOMER', 403);

        return $customer;
    }
}
