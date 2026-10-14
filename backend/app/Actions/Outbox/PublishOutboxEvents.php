<?php

namespace App\Actions\Outbox;

use App\Models\DeadLetter;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PublishOutboxEvents
{
    private bool $topologyReady = false;

    public function handle(int $batchSize = 50): int
    {
        OutboxEvent::query()->where('status', 'PROCESSING')->where('available_at', '<=', now())->update(['status' => 'PENDING']);
        $ids = OutboxEvent::query()->where('status', 'PENDING')->where('available_at', '<=', now())->orderBy('id')->limit($batchSize)->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OutboxEvent {
                $row = OutboxEvent::query()->whereKey($id)->lockForUpdate()->first();
                if ($row === null || $row->status !== 'PENDING' || $row->available_at->isFuture()) {
                    return null;
                }
                $row->forceFill(['status' => 'PROCESSING', 'attempts' => $row->attempts + 1, 'available_at' => now()->addMinutes(5)])->save();

                return $row->fresh();
            });
            if ($event === null) {
                continue;
            }
            try {
                $this->publish($event->payload, $event->event_type);
                $event->forceFill(['status' => 'PUBLISHED', 'published_at' => now(), 'last_error' => null])->save();
                $published++;
            } catch (Throwable $exception) {
                $this->recordFailure($event, $exception->getMessage());
            }
        }

        $published += $this->recoverUnprocessed($batchSize);

        return $published;
    }

    private function recoverUnprocessed(int $batchSize): int
    {
        // Management API reads acknowledge before our database commit. Reconcile against
        // processed_events so a crash after that acknowledgement cannot lose an event.
        $ids = OutboxEvent::query()
            ->where('status', 'PUBLISHED')
            ->where('published_at', '<=', now()->subMinutes(2))
            ->whereNotIn('event_id', DB::table('processed_events')->select('event_id'))
            ->whereNotIn('event_id', DB::table('dead_letters')->whereIn('status', ['OPEN', 'RETRYING'])->select('event_id'))
            ->orderBy('id')->limit($batchSize)->pluck('id');
        $recovered = 0;
        foreach ($ids as $id) {
            $event = DB::transaction(function () use ($id): ?OutboxEvent {
                $row = OutboxEvent::query()->whereKey($id)->lockForUpdate()->first();
                if ($row === null || $row->status !== 'PUBLISHED' || $row->published_at?->isAfter(now()->subMinutes(2))
                    || ProcessedEvent::query()->where('event_id', $row->event_id)->exists()
                    || DeadLetter::query()->where('event_id', $row->event_id)->whereIn('status', ['OPEN', 'RETRYING'])->exists()) {
                    return null;
                }
                $row->forceFill(['published_at' => now()])->save();

                return $row;
            });
            if ($event === null) {
                continue;
            }
            try {
                $this->publish($event->payload, $event->event_type);
                $event->forceFill(['last_error' => null])->save();
                $recovered++;
            } catch (Throwable $exception) {
                $event->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            }
        }

        return $recovered;
    }

    /** @param array<string, mixed> $payload */
    public function publish(array $payload, string $routingKey): void
    {
        $this->ensureTopology();
        $client = $this->client();
        $vhost = rawurlencode('/');
        $exchange = (string) config('services.rabbitmq.exchange');
        $response = $client->post("/api/exchanges/{$vhost}/{$exchange}/publish", [
            'properties' => ['delivery_mode' => 2, 'content_type' => 'application/json'],
            'routing_key' => $routingKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_encoding' => 'string',
        ])->throw();
        if ($response->json('routed') !== true) {
            throw new RuntimeException('RabbitMQ did not route the published event.');
        }
    }

    public function ensureTopology(): void
    {
        if ($this->topologyReady) {
            return;
        }
        $client = $this->client();
        $vhost = rawurlencode('/');
        $exchange = (string) config('services.rabbitmq.exchange');
        $queue = (string) config('services.rabbitmq.queue');
        $client->put("/api/exchanges/{$vhost}/{$exchange}", ['type' => 'topic', 'durable' => true, 'auto_delete' => false, 'internal' => false, 'arguments' => []])->throw();
        $client->put("/api/queues/{$vhost}/{$queue}", ['durable' => true, 'auto_delete' => false, 'arguments' => []])->throw();
        $client->post("/api/bindings/{$vhost}/e/{$exchange}/q/{$queue}", ['routing_key' => '#', 'arguments' => []])->throw();
        $this->topologyReady = true;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.rabbitmq.management_url'))
            ->withBasicAuth((string) config('services.rabbitmq.username'), (string) config('services.rabbitmq.password'))
            ->acceptJson()->timeout(8)->connectTimeout(3);
    }

    private function recordFailure(OutboxEvent $event, string $reason): void
    {
        $attempts = $event->attempts;
        if ($attempts >= 4) {
            $event->forceFill(['status' => 'FAILED', 'last_error' => mb_substr($reason, 0, 2000)])->save();
            DeadLetter::query()->updateOrCreate(['event_id' => $event->event_id], [
                'event_type' => $event->event_type, 'payload' => $event->payload, 'attempts' => $attempts,
                'failure_reason' => mb_substr($reason, 0, 2000), 'status' => 'OPEN', 'failed_at' => now(),
            ]);

            return;
        }
        $delay = [1 => 30, 2 => 120, 3 => 600][$attempts] ?? 600;
        $event->forceFill(['status' => 'PENDING', 'available_at' => now()->addSeconds($delay), 'last_error' => mb_substr($reason, 0, 2000)])->save();
    }
}
