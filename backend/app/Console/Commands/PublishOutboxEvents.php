<?php

namespace App\Console\Commands;

use App\Actions\Outbox\PublishOutboxEvents as PublishOutboxEventsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outbox:publish')]
#[Description('Command description')]
class PublishOutboxEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PublishOutboxEventsAction $action): int
    {
        $published = $action->handle();
        $this->components->info("Published {$published} outbox event(s).");

        return self::SUCCESS;
    }
}
