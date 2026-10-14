<?php

return [
    'order_expiration_minutes' => (int) env('ORDER_EXPIRATION_MINUTES', 15),
    'idempotency_ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),
    'webhook_secret' => env('WEBHOOK_SECRET', 'local-only-change-me'),
    'webhook_timestamp_tolerance_seconds' => (int) env('WEBHOOK_TIMESTAMP_TOLERANCE_SECONDS', 300),
];
