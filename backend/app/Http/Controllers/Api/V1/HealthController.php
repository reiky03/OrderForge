<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['status' => 'ok'],
            $request->attributes->get('request_id'),
        );
    }

    public function ready(Request $request): JsonResponse
    {
        $checks = [
            'postgres' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
            'rabbitmq' => $this->rabbitMqIsReachable(),
            'reverb' => $this->tcpIsReachable((string) config('broadcasting.connections.reverb.options.host'), (int) config('broadcasting.connections.reverb.options.port')),
        ];
        $ready = ! in_array(false, $checks, true);

        return ApiResponse::success(
            [
                'status' => $ready ? 'ok' : 'degraded',
                'checks' => array_map(fn (bool $passed): string => $passed ? 'ok' : 'unavailable', $checks),
            ],
            $request->attributes->get('request_id'),
            $ready ? 200 : 503,
        );
    }

    /** @param callable(): mixed $check */
    private function check(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rabbitMqIsReachable(): bool
    {
        $host = (string) config('services.rabbitmq.host');
        $port = (int) config('services.rabbitmq.port');
        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 1);

        if (! is_resource($connection)) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function tcpIsReachable(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errorCode, $errorMessage, 1);
        if (! is_resource($connection)) {
            return false;
        }
        fclose($connection);

        return true;
    }
}
