<?php

namespace App\Actions\Outbox;

use App\Models\DeadLetter;
use App\Models\ProcessedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ConsumeRabbitMqEvent
{
    public function __construct(private readonly PublishOutboxEvents $publisher) {}

    public function handle(int $batchSize = 10): int
    {
        $this->publisher->ensureTopology();
        $client = Http::baseUrl((string) config('services.rabbitmq.management_url'))
            ->withBasicAuth((string) config('services.rabbitmq.username'), (string) config('services.rabbitmq.password'))
            ->acceptJson()->timeout(8)->connectTimeout(3);
        $queue = (string) config('services.rabbitmq.queue');
        $response = $client->post('/api/queues/'.rawurlencode('/').'/'.rawurlencode($queue).'/get', [
            'count' => $batchSize, 'ackmode' => 'ack_requeue_false', 'encoding' => 'auto', 'truncate' => 50000,
        ])->throw();
        $messages = $response->json();
        if (! is_array($messages)) {
            return 0;
        }
        $processed = 0;
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            try {
                $payload = is_array($message['payload'] ?? null)
                    ? $message['payload']
                    : json_decode((string) ($message['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($payload) || ! isset($payload['event_id'], $payload['event_type'])) {
                    throw new \UnexpectedValueException('RabbitMQ event is missing event_id or event_type.');
                }
                DB::transaction(fn () => ProcessedEvent::query()->insertOrIgnore([
                    'event_id' => $payload['event_id'], 'event_type' => $payload['event_type'],
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'processed_at' => now(),
                ]));
                $processed++;
            } catch (Throwable $exception) {
                $payload = is_array($message['payload'] ?? null) ? $message['payload'] : ['raw_payload' => $message['payload'] ?? null];
                $eventId = isset($payload['event_id']) && is_string($payload['event_id']) && Str::isUuid($payload['event_id'])
                    ? $payload['event_id'] : (string) Str::uuid();
                DeadLetter::query()->firstOrCreate(['event_id' => $eventId], [
                    'event_type' => (string) ($payload['event_type'] ?? 'unknown'), 'payload' => $payload,
                    'attempts' => 1, 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000), 'status' => 'OPEN', 'failed_at' => now(),
                ]);
            }
        }

        return $processed;
    }
}
