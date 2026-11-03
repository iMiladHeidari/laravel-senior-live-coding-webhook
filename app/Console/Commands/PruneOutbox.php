<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneOutbox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'outbox:prune {--days= : Delete handled messages older than this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete published/superseded outbox messages and old inbox entries';

    private const DELETE_CHUNK = 5000;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('outbox.prune_after_days'));
        $cutoff = now()->subDays($days);

        $outbox = $this->deleteInChunks(fn () => OutboxMessage::query()
            ->whereIn('status', [OutboxMessage::STATUS_PUBLISHED, OutboxMessage::STATUS_SUPERSEDED])
            ->where('updated_at', '<', $cutoff)
            ->limit(self::DELETE_CHUNK)
            ->delete());

        $inbox = $this->deleteInChunks(fn () => DB::table('inbox_messages')
            ->where('processed_at', '<', $cutoff)
            ->limit(self::DELETE_CHUNK)
            ->delete());

        $this->components->info("Pruned {$outbox} outbox and {$inbox} inbox message(s).");

        return self::SUCCESS;
    }

    /**
     * @param  callable(): int  $deleteChunk
     */
    private function deleteInChunks(callable $deleteChunk): int
    {
        $total = 0;

        do {
            $deleted = $deleteChunk();
            $total += $deleted;
        } while ($deleted === self::DELETE_CHUNK);

        return $total;
    }
}
