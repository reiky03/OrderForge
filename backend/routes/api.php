<?php

use App\Http\Controllers\Api\V1\AdminEventController;
use App\Http\Controllers\Api\V1\AdminInventoryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Models\Inventory;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id');

    Route::post('/payments/webhook/mock', [PaymentController::class, 'webhook'])->middleware('throttle:webhooks');

    Route::prefix('admin/inventory')->middleware('auth:sanctum')->group(function (): void {
        Route::get('/', [AdminInventoryController::class, 'index'])->can('viewAny', Inventory::class);
        Route::get('/{inventory}', [AdminInventoryController::class, 'show'])->can('view', 'inventory');
        Route::post('/{inventory}/adjustments', [AdminInventoryController::class, 'adjust'])->can('adjust', 'inventory');
    });

    Route::prefix('admin')->middleware('auth:sanctum')->group(function (): void {
        Route::get('/orders', [AdminEventController::class, 'orders']);
        Route::get('/payments', [AdminEventController::class, 'payments']);
        Route::get('/failed-jobs', [AdminEventController::class, 'failedJobs']);
        Route::get('/outbox', [AdminEventController::class, 'outbox']);
        Route::get('/dead-letters', [AdminEventController::class, 'deadLetters']);
        Route::post('/dead-letters/{deadLetter}/retry', [AdminEventController::class, 'retry']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->can('view', 'order');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->can('cancel', 'order');
        Route::post('/orders/{order}/payments', [PaymentController::class, 'create'])->can('createPayment', 'order');
        Route::post('/payments/{payment}/mock-success', [PaymentController::class, 'mockSuccess']);
        Route::post('/payments/{payment}/mock-failure', [PaymentController::class, 'mockFailure']);
        Route::post('/payments/{payment}/mock-timeout', [PaymentController::class, 'mockTimeout']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
    });
});
