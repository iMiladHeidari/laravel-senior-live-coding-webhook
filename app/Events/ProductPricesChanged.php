<?php

namespace App\Events;

use Illuminate\Support\Str;

final class ProductPricesChanged implements DomainEvent
{
    public readonly string $messageId;

    /**
     * @param  list<int>  $productIds
     */
    public function __construct(
        public readonly int $fiatId,
        public readonly string $symbol,
        public readonly array $productIds,
        public readonly int $rate,
        public readonly int $sourceTimestamp,
        public readonly string $causationId,
        ?string $messageId = null,
    ) {
        $this->messageId = $messageId ?? (string) Str::uuid();
    }

    public static function eventType(): string
    {
        return 'product.prices_changed';
    }

    /**
     * @param  array{fiat_id: int, symbol: string, product_ids: list<int>, rate: int, source_timestamp: int, causation_id: string}  $payload
     */
    public static function fromPayload(string $messageId, array $payload): static
    {
        return new self(
            fiatId: (int) $payload['fiat_id'],
            symbol: $payload['symbol'],
            productIds: array_map('intval', $payload['product_ids']),
            rate: (int) $payload['rate'],
            sourceTimestamp: (int) $payload['source_timestamp'],
            causationId: $payload['causation_id'],
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

    /**
     * One event per (causing rate change, product chunk), so a retried reprice cannot emit it twice.
     */
    public function dedupKey(): string
    {
        return "product-prices:{$this->causationId}:{$this->productIds[0]}";
    }

    public function toPayload(): array
    {
        return [
            'fiat_id' => $this->fiatId,
            'symbol' => $this->symbol,
            'product_ids' => $this->productIds,
            'rate' => $this->rate,
            'source_timestamp' => $this->sourceTimestamp,
            'causation_id' => $this->causationId,
        ];
    }
}
