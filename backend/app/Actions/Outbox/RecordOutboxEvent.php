<?php

namespace App\Actions\Outbox;

use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use Illuminate\Support\Str;

class RecordOutboxEvent
{
    /**
     * @param  array<string, mixed>  $eventData
     */
    public function handle(string $eventType, Order|Payment $aggregate, array $eventData, ?string $requestId): OutboxEvent
    {
        $eventId = (string) Str::uuid();
        $occurredAt = now()->toIso8601String();

        return OutboxEvent::query()->create([
            'event_id' => $eventId,
            'aggregate_type' => $aggregate instanceof Payment ? 'payment' : 'order',
            'aggregate_id' => (string) $aggregate->id,
            'event_type' => $eventType,
            'payload' => [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'aggregate_id' => (string) $aggregate->id,
                'request_id' => $requestId,
                'payload' => $eventData,
            ],
            'status' => 'PENDING',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }
}
