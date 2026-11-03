<?php

namespace App\Services;

use App\Events\DomainEvent;
use App\Events\FiatRateChanged;
use App\Models\OutboxMessage;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use UnexpectedValueException;

class OutboxRelay
{
    public function __construct(private Dispatcher $events) {}

    /**
     * Claims one batch of due messages, publishes them, and returns how many were handled.
     *
     * Rows are claimed with FOR UPDATE SKIP LOCKED (ignored on SQLite), so several relays can run in
     * parallel. Delivery is at-least-once; consumers are idempotent through the inbox.
     */
    public function relayBatch(?int $limit = null): int
    {
        $limit ??= (int) config('outbox.relay.batch_size');

        return DB::transaction(function () use ($limit) {
            $messages = OutboxMessage::query()
                ->where('status', OutboxMessage::STATUS_PENDING)
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($messages->isEmpty()) {
                return 0;
            }

            $superseded = $this->supersededFiatRateChanges($messages);
            $published = [];

            foreach ($messages as $message) {
                if (isset($superseded[$message->id])) {
                    continue;
                }

                try {
                    $this->events->dispatch($this->toEvent($message));
                    $published[] = $message->id;
                } catch (Throwable $e) {
                    report($e);
                    $this->markFailedAttempt($message, $e);
                }
            }

            $now = now();

            if ($published !== []) {
                OutboxMessage::query()->whereKey($published)->update([
                    'status' => OutboxMessage::STATUS_PUBLISHED,
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($superseded !== []) {
                OutboxMessage::query()->whereKey(array_keys($superseded))->update([
                    'status' => OutboxMessage::STATUS_SUPERSEDED,
                    'updated_at' => $now,
                ]);
            }

            return $messages->count();
        }, 3);
    }

    /**
     * Within a batch only the newest rate change per fiat needs to be published.
     *
     * @param  Collection<int, OutboxMessage>  $messages
     * @return array<int, true> outbox ids that are superseded
     */
    private function supersededFiatRateChanges(Collection $messages): array
    {
        $superseded = [];

        $messages
            ->where('event_type', FiatRateChanged::eventType())
            ->groupBy('aggregate_id')
            ->each(function (Collection $group) use (&$superseded) {
                $group
                    ->sortByDesc(fn (OutboxMessage $message) => [$message->payload['source_timestamp'], $message->id])
                    ->skip(1)
                    ->each(function (OutboxMessage $message) use (&$superseded) {
                        $superseded[$message->id] = true;
                    });
            });

        return $superseded;
    }

    private function toEvent(OutboxMessage $message): DomainEvent
    {
        /** @var class-string<DomainEvent>|null $class */
        $class = config('outbox.events')[$message->event_type] ?? null;

        if ($class === null) {
            throw new UnexpectedValueException("Unknown outbox event type [{$message->event_type}].");
        }

        return $class::fromPayload($message->message_id, $message->payload);
    }

    private function markFailedAttempt(OutboxMessage $message, Throwable $e): void
    {
        $attempts = $message->attempts + 1;
        $exhausted = $attempts >= (int) config('outbox.relay.max_attempts');

        $message->forceFill([
            'attempts' => $attempts,
            'status' => $exhausted ? OutboxMessage::STATUS_FAILED : OutboxMessage::STATUS_PENDING,
            'available_at' => now()->addSeconds(min(2 ** $attempts, 300)),
            'last_error' => mb_substr($e->getMessage(), 0, 2000),
        ])->save();
    }
}
