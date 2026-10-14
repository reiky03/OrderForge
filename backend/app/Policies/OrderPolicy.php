<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->role === 'ADMIN' || $order->customer_id === $user->id;
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->role === 'CUSTOMER'
            && $order->customer_id === $user->id;
    }

    public function createPayment(User $user, Order $order): bool
    {
        return $user->role === 'CUSTOMER' && $order->customer_id === $user->id;
    }
}
