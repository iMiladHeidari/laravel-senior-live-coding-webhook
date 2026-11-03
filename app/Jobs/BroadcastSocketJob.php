<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BroadcastSocketJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $channel,
        public array $data
    ) {}

    public function handle(): void
    {
        Log::info("Broadcasted message to channel [{$this->channel}]", [
            'data' => $this->data,
        ]);
    }
}
