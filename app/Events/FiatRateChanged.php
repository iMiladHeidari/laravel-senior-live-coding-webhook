<?php

namespace App\Events;

use Illuminate\Support\Str;

final class FiatRateChanged implements DomainEvent
{
    public readonly string $messageId;

    public function __construct(
        public readonly int $fiatId,
        public readonly string $symbol,
        public readonly ?int $previousRate,
        public readonly int $rate,
        public readonly int $sourceTimestamp,
        ?string $messageId = null,
    ) {
        $this->messageId = $messageId ?? (string) Str::uuid();
    }

    public static function eventType(): string
    {
        return 'fiat.rate_changed';
    }

    /**
     * @param  array{fiat_id: int, symbol: string, previous_rate: ?int, rate: int, source_timestamp: int}  $payload
     */
    public static function fromPayload(string $messageId, array $payload): static
    {
        return new self(
            fiatId: (int) $payload['fiat_id'],
            symbol: $payload['symbol'],
            previousRate: isset($payload['previous_rate']) ? (int) $payload['previous_rate'] : null,
            rate: (int) $payload['rate'],
            sourceTimestamp: (int) $payload['source_timestamp'],
            messageId: $messageId,
        );
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    public function aggregateType(): string
    {
        return 'fiat';
    }

    public function aggregateId(): string
    {
        return (string) $this->fiatId;
    }

    public function dedupKey(): string
    {
        return "fiat-rate:{$this->fiatId}:{$this->sourceTimestamp}";
    }

    public function toPayload(): array
    {
        return [
            'fiat_id' => $this->fiatId,
            'symbol' => $this->symbol,
            'previous_rate' => $this->previousRate,
            'rate' => $this->rate,
            'source_timestamp' => $this->sourceTimestamp,
        ];
    }
}
