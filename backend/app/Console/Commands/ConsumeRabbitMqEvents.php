<?php

namespace App\Console\Commands;

use App\Actions\Outbox\ConsumeRabbitMqEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('events:consume {--once} {--sleep=2}')]
#[Description('Consume OrderForge events from RabbitMQ.')]
class ConsumeRabbitMqEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ConsumeRabbitMqEvent $consumer): int
    {
        do {
            try {
                $count = $consumer->handle();
                if ($count > 0) {
                    $this->components->info("Processed {$count} RabbitMQ event(s).");
                }
            } catch (\Throwable $exception) {
                report($exception);
                $this->components->error('RabbitMQ consume failed: '.$exception->getMessage());
            }
            if ($this->option('once')) {
                break;
            }
            sleep(max(1, (int) $this->option('sleep')));
        } while (true);

        return self::SUCCESS;
    }
}
