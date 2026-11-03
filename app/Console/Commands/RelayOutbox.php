<?php

namespace App\Console\Commands;

use App\Services\OutboxRelay;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

class RelayOutbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:relay
                            {--loop : Keep relaying until the process is stopped}
                            {--batch= : Maximum messages claimed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish pending transactional outbox messages to the event bus';

    private bool $shouldStop = false;

    /**
     * Execute the console command.
     */
    public function handle(OutboxRelay $relay): int
    {
        $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;
        $pollIntervalMs = (int) config('outbox.relay.poll_interval_ms');

        $this->trap([SIGTERM, SIGINT, SIGQUIT], function () {
            $this->shouldStop = true;
        });

        $total = 0;

        do {
            $handled = $relay->relayBatch($batch);
            $total += $handled;

            if ($handled === 0 && $this->option('loop')) {
                Sleep::for($pollIntervalMs)->milliseconds();
            }
        } while (! $this->shouldStop && ($this->option('loop') || $handled > 0));

        $this->components->info("Relayed {$total} outbox message(s).");

        return self::SUCCESS;
    }
}
