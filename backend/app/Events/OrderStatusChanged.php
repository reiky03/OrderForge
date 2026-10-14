<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class OrderStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $orderId,
        public readonly string $status,
        public readonly ?string $paymentStatus = null,
        public readonly ?string $requestId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->orderId)];
    }

    public function broadcastAs(): string
    {
        return 'order.status.changed';
    }

    /** @return array{order_id:int,status:string,payment_status:?string,request_id:?string} */
    public function broadcastWith(): array
    {
        return ['order_id' => $this->orderId, 'status' => $this->status, 'payment_status' => $this->paymentStatus, 'request_id' => $this->requestId];
    }
}
