<?php

namespace App\Services;

use App\Events\DomainEvent;
use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use LogicException;

class Outbox
{
    /**
     * Stores the event in the outbox as part of the caller's transaction.
     *
     * @return bool false when an event with the same dedup key already exists
     */
    public function record(DomainEvent $event): bool
    {
        return $this->recordMany([$event]) === 1;
    }

    /**
     * Stores the events with a single INSERT as part of the caller's transaction.
     * Events whose dedup key already exists are ignored.
     *
     * @param  list<DomainEvent>  $events
     * @return int number of events actually stored
     */
    public function recordMany(array $events): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox events must be recorded inside the database transaction that changes state.');
        }

        if ($events === []) {
            return 0;
        }

        $now = now();

        return OutboxMessage::query()->insertOrIgnore(array_map(fn (DomainEvent $event) => [
            'message_id' => $event->messageId(),
            'event_type' => $event::eventType(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'payload' => json_encode($event->toPayload(), JSON_THROW_ON_ERROR),
            'dedup_key' => $event->dedupKey(),
            'status' => OutboxMessage::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $events));
    }
}
