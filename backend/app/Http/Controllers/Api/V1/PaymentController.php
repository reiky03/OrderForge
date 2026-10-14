<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\CreatePayment;
use App\Actions\Payments\MockPaymentProvider;
use App\Actions\Payments\ProcessPaymentWebhook;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidOrderStateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatePaymentRequest;
use App\Http\Requests\Api\V1\MockPaymentRequest;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function create(CreatePaymentRequest $request, Order $order, CreatePayment $action): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->role === 'CUSTOMER', 403);
        Gate::authorize('createPayment', $order);
        $result = $action->handle($order, $user, $request->validated('idempotency_key'), $request->attributes->get('request_id'));

        return ApiResponse::success($result['data'], $result['request_id'], $result['status'])->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function webhook(Request $request, ProcessPaymentWebhook $action): JsonResponse
    {
        $result = $action->handle($request->getContent(), $request->header('X-Webhook-Timestamp'), $request->header('X-Webhook-Signature'), $request->attributes->get('request_id'));

        return ApiResponse::success($result, $request->attributes->get('request_id'));
    }

    public function mockSuccess(MockPaymentRequest $request, Payment $payment, MockPaymentProvider $provider): JsonResponse
    {
        return $this->simulate($request, $payment, $provider, true);
    }

    public function mockFailure(MockPaymentRequest $request, Payment $payment, MockPaymentProvider $provider): JsonResponse
    {
        return $this->simulate($request, $payment, $provider, false);
    }

    public function mockTimeout(MockPaymentRequest $request, Payment $payment): JsonResponse
    {
        Gate::authorize('simulate', $payment);
        if ($payment->status !== PaymentStatus::Pending) {
            throw new InvalidOrderStateException('Only a pending payment can time out.');
        }
        $delaySeconds = (int) ($request->validated('delay_seconds') ?? 0);
        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
        Log::warning('mock_payment_timeout', ['payment_id' => $payment->id, 'order_id' => $payment->order_id]);

        return ApiResponse::error('MOCK_PROVIDER_TIMEOUT', 'The mock provider timed out. The payment remains pending.', [], 504, $request->attributes->get('request_id'));
    }

    private function simulate(MockPaymentRequest $request, Payment $payment, MockPaymentProvider $provider, bool $succeed): JsonResponse
    {
        Gate::authorize('simulate', $payment);
        $data = $request->validated();
        $result = $provider->deliver($payment, $succeed, (int) ($data['duplicate_deliveries'] ?? 1), (int) ($data['delay_seconds'] ?? 0), $request->attributes->get('request_id'));

        return ApiResponse::success([
            'payment' => PaymentResource::make($result['payment'])->resolve($request),
            'webhook' => ['event_id' => $result['event_id'], 'deliveries' => $result['deliveries'], 'duplicates' => $result['duplicates']],
        ], $request->attributes->get('request_id'));
    }
}
