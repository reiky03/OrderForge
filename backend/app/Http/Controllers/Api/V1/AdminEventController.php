<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outbox\RetryDeadLetter;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\PaymentResource;
use App\Models\DeadLetter;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminEventController extends Controller
{
    public function orders(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = Order::query()->with('items', 'customer', 'payment')->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->string('status')))->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success(OrderResource::collection($rows)->resolve($request), $request->attributes->get('request_id'), 200, ['pagination' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = Payment::query()->with('order.customer')->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->string('status')))->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success(PaymentResource::collection($rows)->resolve($request), $request->attributes->get('request_id'), 200, ['pagination' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = DB::table('failed_jobs')->select(['id', 'uuid', 'queue', 'failed_at', 'exception'])->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $items = [];
        foreach ($rows->items() as $job) {
            $items[] = [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'failed_at' => $job->failed_at,
                'summary' => mb_substr(strtok((string) $job->exception, "\n") ?: 'Queue job failed.', 0, 400),
            ];
        }

        return ApiResponse::success($items, $request->attributes->get('request_id'), 200, ['pagination' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function outbox(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = OutboxEvent::query()->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success($rows->items(), $request->attributes->get('request_id'), 200, ['pagination' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $rows = DeadLetter::query()->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return ApiResponse::success($rows->items(), $request->attributes->get('request_id'), 200, ['pagination' => ['current_page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    public function retry(Request $request, DeadLetter $deadLetter, RetryDeadLetter $action): JsonResponse
    {
        $this->ensureAdmin($request);
        $resolved = $action->handle($deadLetter);

        return ApiResponse::success(['id' => $resolved->id, 'event_id' => $resolved->event_id, 'status' => $resolved->status, 'retried_at' => $resolved->retried_at?->toIso8601String()], $request->attributes->get('request_id'));
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user() instanceof User && $request->user()->role === 'ADMIN', 403);
    }
}
