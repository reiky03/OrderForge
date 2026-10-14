<?php

namespace Tests\Feature;

use App\Actions\Outbox\ConsumeRabbitMqEvent;
use App\Actions\Outbox\PublishOutboxEvents;
use App\Models\DeadLetter;
use App\Models\OutboxEvent;
use App\Models\ProcessedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboxPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_event_is_published_to_rabbitmq_and_marked_published(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/publish')
            ? Http::response(['routed' => true], 200)
            : Http::response([], 204));
        $event = OutboxEvent::factory()->create(['payload' => ['event_id' => '2a81c5bc-1e95-4c60-92ca-161cf67a893d', 'event_type' => 'order.created']]);

        $this->assertSame(1, app(PublishOutboxEvents::class)->handle());
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'status' => 'PUBLISHED', 'attempts' => 1]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/publish') && $request['routing_key'] === 'order.created');
    }

    public function test_failed_publication_uses_backoff_then_becomes_visible_in_dead_letters(): void
    {
        Http::fake(fn () => Http::response(['error' => 'broker unavailable'], 503));
        $event = OutboxEvent::factory()->create();
        $publisher = app(PublishOutboxEvents::class);

        foreach ([1, 2, 3, 4] as $attempt) {
            $event->forceFill(['status' => 'PENDING', 'available_at' => now()->subSecond()])->save();
            $this->assertSame(0, $publisher->handle());
            $event->refresh();
            $this->assertSame($attempt === 4 ? 'FAILED' : 'PENDING', $event->status);
            if ($attempt === 4) {
                $this->assertDatabaseHas('dead_letters', ['event_id' => $event->event_id, 'status' => 'OPEN']);
            }
        }
    }

    public function test_published_event_without_processing_receipt_is_republished_but_processed_event_is_not(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/publish')
            ? Http::response(['routed' => true], 200)
            : Http::response([], 204));
        $lost = OutboxEvent::factory()->create([
            'status' => 'PUBLISHED', 'published_at' => now()->subMinutes(3),
            'event_id' => 'b1d96cb8-e4b6-4c6d-9ca4-80654c4c902b',
            'payload' => ['event_id' => 'b1d96cb8-e4b6-4c6d-9ca4-80654c4c902b', 'event_type' => 'order.created'],
        ]);
        $handled = OutboxEvent::factory()->create([
            'status' => 'PUBLISHED', 'published_at' => now()->subMinutes(3),
            'event_id' => 'ed656490-3b9b-41ab-b018-d65b97c5b2d5',
        ]);
        ProcessedEvent::query()->create([
            'event_id' => $handled->event_id, 'event_type' => $handled->event_type,
            'payload' => $handled->payload, 'processed_at' => now(),
        ]);

        $this->assertSame(1, app(PublishOutboxEvents::class)->handle());
        $this->assertDatabaseHas('outbox_events', ['id' => $lost->id, 'status' => 'PUBLISHED']);
        Http::assertSentCount(4); // exchange, queue, binding, one publish
        Http::assertSent(fn ($request) => str_contains($request->url(), '/publish')
            && str_contains((string) $request['payload'], $lost->event_id));
    }

    public function test_consumer_records_event_once_and_keeps_it_idempotent(): void
    {
        $served = false;
        Http::fake(function ($request) use (&$served) {
            if (str_contains($request->url(), '/get')) {
                if ($served) {
                    return Http::response([], 200);
                }
                $served = true;

                return Http::response([['payload' => json_encode(['event_id' => '33c3dcc7-e9a8-4a84-a2ca-46536db739de', 'event_type' => 'order.paid'], JSON_THROW_ON_ERROR)]], 200);
            }

            return Http::response([], 204);
        });

        $consumer = app(ConsumeRabbitMqEvent::class);
        $this->assertSame(1, $consumer->handle());
        $this->assertSame(0, $consumer->handle());
        $this->assertSame(1, ProcessedEvent::query()->count());
        $this->assertSame(0, DeadLetter::query()->count());
    }

    public function test_admin_can_retry_dead_letter_but_customer_cannot(): void
    {
        $deadLetter = DeadLetter::query()->create([
            'event_id' => '71667e5b-7f50-4bcb-9dbb-b52068288285', 'event_type' => 'order.created',
            'payload' => ['event_id' => '71667e5b-7f50-4bcb-9dbb-b52068288285', 'event_type' => 'order.created'],
            'attempts' => 4, 'failure_reason' => 'temporary broker failure', 'status' => 'OPEN', 'failed_at' => now(),
        ]);
        OutboxEvent::factory()->create([
            'event_id' => $deadLetter->event_id, 'event_type' => $deadLetter->event_type,
            'payload' => $deadLetter->payload, 'status' => 'FAILED', 'attempts' => 4,
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'CUSTOMER']));
        $this->postJson("/api/v1/admin/dead-letters/{$deadLetter->id}/retry")->assertForbidden();

        Http::fake(fn ($request) => str_contains($request->url(), '/publish')
            ? Http::response(['routed' => true], 200)
            : Http::response([], 204));
        Sanctum::actingAs(User::factory()->create(['role' => 'ADMIN']));
        $this->postJson("/api/v1/admin/dead-letters/{$deadLetter->id}/retry")->assertOk()->assertJsonPath('data.status', 'RETRIED');
        $this->assertDatabaseHas('dead_letters', ['id' => $deadLetter->id, 'status' => 'RETRIED']);
        $this->assertDatabaseHas('outbox_events', ['event_id' => $deadLetter->event_id, 'status' => 'PUBLISHED']);
    }
}
