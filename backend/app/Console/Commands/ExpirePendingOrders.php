<?php

namespace App\Console\Commands;

use App\Actions\Orders\ExpireOrders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:expire')]
#[Description('Command description')]
class ExpirePendingOrders extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ExpireOrders $action): int
    {
        $expired = $action->handle();
        $this->components->info("Expired {$expired} pending order(s).");

        return self::SUCCESS;
    }
}
