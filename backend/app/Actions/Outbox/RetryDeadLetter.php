<?php

namespace App\Actions\Outbox;

use App\Models\DeadLetter;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RetryDeadLetter
{
    public function __construct(private readonly PublishOutboxEvents $publisher) {}

    public function handle(DeadLetter $deadLetter): DeadLetter
    {
        $claimed = DB::transaction(function () use ($deadLetter): ?DeadLetter {
            $row = DeadLetter::query()->whereKey($deadLetter->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'OPEN') {
                return null;
            }
            $row->forceFill(['status' => 'RETRYING'])->save();

            return $row;
        });
        if ($claimed === null) {
            throw new RuntimeException('This dead letter is already being retried or has been resolved.');
        }
        try {
            $this->publisher->publish($claimed->payload, $claimed->event_type);
            DB::transaction(function () use ($claimed): void {
                OutboxEvent::query()->where('event_id', $claimed->event_id)->update([
                    'status' => 'PUBLISHED', 'published_at' => now(), 'last_error' => null,
                ]);
                $claimed->forceFill(['status' => 'RETRIED', 'retried_at' => now()])->save();
            });
        } catch (Throwable $exception) {
            $claimed->forceFill(['status' => 'OPEN', 'failure_reason' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            throw $exception;
        }

        return $claimed->fresh();
    }
}
