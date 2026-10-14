<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordHttpMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('metrics_started_at', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $startedAt = $request->attributes->get('metrics_started_at');
        if (is_float($startedAt)) {
            $this->record($request, $response->getStatusCode(), microtime(true) - $startedAt);
        }
    }

    private function record(Request $request, int $status, float $durationSeconds): void
    {
        try {
            $method = strtoupper($request->method());
            Redis::incr("orderforge:metrics:http_requests_total:{$method}:{$status}");
            Redis::incrbyfloat("orderforge:metrics:http_request_duration_seconds_sum:{$method}", $durationSeconds);
            Redis::incr("orderforge:metrics:http_request_duration_seconds_count:{$method}");
        } catch (Throwable) {
            // Metrics must not interrupt a business request when Redis is unavailable.
        }
    }
}
