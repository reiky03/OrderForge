<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data, ?string $requestId, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => $meta === [] ? (object) [] : $meta,
            'request_id' => $requestId,
        ], $status);
    }

    public static function error(
        string $code,
        string $message,
        array $details,
        int $status,
        ?string $requestId,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? (object) [] : $details,
            ],
            'request_id' => $requestId,
        ], $status);
    }
}
