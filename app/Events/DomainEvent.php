<?php

namespace App\Events;

/**
 * An event that is persisted to the transactional outbox and published by the relay.
 */
interface DomainEvent
{
    public static function eventType(): string;

    /**
     * Rebuilds the event from a stored outbox payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $messageId, array $payload): static;

    public function messageId(): string;

    public function aggregateType(): string;

    public function aggregateId(): string;

    /**
     * Globally unique key; recording the same key twice is a no-op.
     */
    public function dedupKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
