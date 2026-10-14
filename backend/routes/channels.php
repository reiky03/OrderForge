<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('orders.{orderId}', function (User $user, int $orderId): bool {
    return $user->role === 'ADMIN' || Order::query()->whereKey($orderId)->where('customer_id', $user->id)->exists();
});
