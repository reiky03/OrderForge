<?php

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderStateException;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\RecordHttpMetrics;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnsureRequestId::class);
        $middleware->append(RecordHttpMetrics::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            InsufficientStockException::class,
            IdempotencyConflictException::class,
            InvalidOrderStateException::class,
            InvalidWebhookSignatureException::class,
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof InsufficientStockException => 409,
                $exception instanceof IdempotencyConflictException => 409,
                $exception instanceof InvalidOrderStateException => 409,
                $exception instanceof InvalidWebhookSignatureException => 401,
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 429 => 429,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };

            $code = match (true) {
                $exception instanceof InsufficientStockException => 'INSUFFICIENT_STOCK',
                $exception instanceof IdempotencyConflictException => 'IDEMPOTENCY_CONFLICT',
                $exception instanceof InvalidOrderStateException => 'INVALID_ORDER_STATE',
                $exception instanceof InvalidWebhookSignatureException => 'INVALID_WEBHOOK_SIGNATURE',
                $status === 401 => 'UNAUTHORIZED',
                $status === 403 => 'FORBIDDEN',
                $status === 404 => 'NOT_FOUND',
                $status === 422 => 'VALIDATION_ERROR',
                $status === 429 => 'RATE_LIMIT_EXCEEDED',
                default => 'INTERNAL_SERVER_ERROR',
            };

            $message = match (true) {
                $exception instanceof ValidationException => 'The submitted data is invalid.',
                $exception instanceof InsufficientStockException => $exception->getMessage(),
                $exception instanceof IdempotencyConflictException => $exception->getMessage(),
                $exception instanceof InvalidOrderStateException => $exception->getMessage(),
                $exception instanceof AuthenticationException => 'Unauthenticated.',
                $exception instanceof AuthorizationException => 'This action is unauthorized.',
                $status === 404 => 'Resource not found.',
                $status === 429 => 'Too many requests. Please try again shortly.',
                $status >= 500 => 'An unexpected server error occurred.',
                default => $exception->getMessage(),
            };

            $details = $exception instanceof ValidationException
                ? ['fields' => $exception->errors()]
                : [];

            return ApiResponse::error(
                $code,
                $message,
                $details,
                $status,
                $request->attributes->get('request_id'),
            );
        });
    })->create();
