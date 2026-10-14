<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lines = [
            '# HELP orderforge_http_requests_total Completed HTTP requests grouped by method and status.',
            '# TYPE orderforge_http_requests_total counter',
        ];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            foreach ([200, 201, 204, 400, 401, 403, 404, 409, 422, 429, 500, 503] as $status) {
                $value = $this->redisValue("orderforge:metrics:http_requests_total:{$method}:{$status}");
                if ($value > 0) {
                    $lines[] = "orderforge_http_requests_total{method=\"{$method}\",status=\"{$status}\"} {$value}";
                }
            }
        }
        $lines[] = '# HELP orderforge_http_request_duration_seconds HTTP request duration summary by method.';
        $lines[] = '# TYPE orderforge_http_request_duration_seconds summary';
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $sum = $this->redisValue("orderforge:metrics:http_request_duration_seconds_sum:{$method}");
            $count = $this->redisValue("orderforge:metrics:http_request_duration_seconds_count:{$method}");
            $lines[] = "orderforge_http_request_duration_seconds_sum{method=\"{$method}\"} {$sum}";
            $lines[] = "orderforge_http_request_duration_seconds_count{method=\"{$method}\"} {$count}";
        }

        $businessMetrics = [
            'orders_created_total' => DB::table('outbox_events')->where('event_type', 'order.created')->count(),
            'orders_paid_total' => DB::table('outbox_events')->where('event_type', 'order.paid')->count(),
            'orders_expired_total' => DB::table('outbox_events')->where('event_type', 'order.expired')->count(),
            'payments_failed_total' => DB::table('outbox_events')->where('event_type', 'payment.failed')->count(),
            'dead_letters_open' => DB::table('dead_letters')->where('status', 'OPEN')->count(),
            'queue_jobs_failed' => DB::table('failed_jobs')->count(),
            'queue_jobs_pending' => DB::table('jobs')->count(),
            'inventory_reservation_failures_total' => $this->redisValue('orderforge:metrics:inventory_reservation_failures_total'),
        ];
        foreach ($businessMetrics as $name => $value) {
            $type = str_ends_with($name, '_total') ? 'counter' : 'gauge';
            $lines[] = "# TYPE orderforge_{$name} {$type}";
            $lines[] = "orderforge_{$name} {$value}";
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'X-Request-ID' => (string) $request->attributes->get('request_id'),
        ]);
    }

    private function redisValue(string $key): int|float
    {
        try {
            $value = Redis::get($key);

            return is_numeric($value) ? (float) $value : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
