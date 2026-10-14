<?php

namespace App\Actions\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

class MockPaymentProvider
{
    public function __construct(private readonly ProcessPaymentWebhook $webhookProcessor) {}

    /** @return array{payment:Payment,event_id:string,deliveries:int,duplicates:int} */
    public function deliver(Payment $payment, bool $succeed, int $deliveries, int $delaySeconds, ?string $requestId): array
    {
        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
        $timestamp = (string) time();
        $eventId = (string) Str::uuid();
        $body = json_encode([
            'event_id' => $eventId,
            'type' => $succeed ? 'payment.succeeded' : 'payment.failed',
            'payment_reference' => $payment->payment_reference,
            'amount' => (string) $payment->getRawOriginal('amount'),
            'currency' => $payment->currency,
        ], JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('orderforge.webhook_secret'));
        $duplicates = 0;
        for ($i = 0; $i < $deliveries; $i++) {
            $result = $this->webhookProcessor->handle($body, $timestamp, $signature, $requestId);
            if ($result['duplicate']) {
                $duplicates++;
            }
        }

        return ['payment' => $payment->fresh(), 'event_id' => $eventId, 'deliveries' => $deliveries, 'duplicates' => $duplicates];
    }
}
