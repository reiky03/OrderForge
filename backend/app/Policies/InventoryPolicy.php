<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'ADMIN';
    }

    public function view(User $user, Inventory $inventory): bool
    {
        return $user->role === 'ADMIN';
    }

    public function adjust(User $user, Inventory $inventory): bool
    {
        return $user->role === 'ADMIN';
    }
}
