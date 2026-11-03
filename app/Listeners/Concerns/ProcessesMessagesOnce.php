<?php

namespace App\Listeners\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Inbox pattern: remembers which outbox message ids this consumer has already handled.
 */
trait ProcessesMessagesOnce
{
    protected function hasProcessed(string $messageId): bool
    {
        return DB::table('inbox_messages')
            ->where('message_id', $messageId)
            ->where('consumer', static::class)
            ->exists();
    }

    /**
     * @return bool false when another delivery already recorded this message
     */
    protected function markProcessed(string $messageId): bool
    {
        return DB::table('inbox_messages')->insertOrIgnore([
            'message_id' => $messageId,
            'consumer' => static::class,
            'processed_at' => now(),
        ]) === 1;
    }

    /**
     * Runs the callback at most once per message. The inbox row and the callback's database writes
     * commit together, so a failure rolls both back and the retry can run again. A concurrent
     * duplicate delivery blocks on the inbox primary key until the first commits, then is ignored.
     */
    protected function processOnce(string $messageId, Closure $callback): void
    {
        DB::transaction(function () use ($messageId, $callback) {
            if (! $this->markProcessed($messageId)) {
                return;
            }

            $callback();
        }, 3);
    }
}
