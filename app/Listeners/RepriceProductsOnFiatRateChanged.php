<?php

namespace App\Listeners;

use App\Events\FiatRateChanged;
use App\Events\ProductPricesChanged;
use App\Listeners\Concerns\ProcessesMessagesOnce;
use App\Models\Fiat;
use App\Models\Product;
use App\Services\Outbox;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class RepriceProductsOnFiatRateChanged implements ShouldQueue
{
    use InteractsWithQueue, ProcessesMessagesOnce;

    private const DEADLOCK_RETRIES = 3;

    public string $queue = 'prices';

    public int $timeout = 120;

    /**
     * Real failures; releases by WithoutOverlapping do not count against this.
     */
    public int $maxExceptions = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(private Outbox $outbox) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * Serializes reprices per fiat to avoid wasted work. Correctness does not depend on this lock:
     * the price_source_timestamp guard below already makes concurrent or out-of-order runs safe.
     *
     * @return list<object>
     */
    public function middleware(FiatRateChanged $event): array
    {
        return [
            (new WithoutOverlapping("reprice-fiat:{$event->fiatId}"))
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 30),
        ];
    }

    /**
     * Reprices the fiat's products chunk by chunk with set-based UPDATEs (a fixed number of queries
     * per chunk). Each chunk's UPDATE and its ProductPricesChanged outbox event commit together;
     * the chunk's dedup key makes a retried run emit nothing twice.
     */
    public function handle(FiatRateChanged $event): void
    {
        if ($this->hasProcessed($event->messageId)) {
            return;
        }

        $fiat = Fiat::query()->find($event->fiatId, ['id', 'is_active', 'current_price_irr']);

        // A different current rate means a newer FiatRateChanged is on its way and will reprice instead.
        if (! $fiat || ! $fiat->is_active || $fiat->current_price_irr !== $event->rate) {
            $this->markProcessed($event->messageId);

            return;
        }

        Product::query()
            ->where('fiat_id', $event->fiatId)
            ->select('id')
            ->chunkById((int) config('outbox.product_chunk_size'), function (Collection $products) use ($event) {
                DB::transaction(fn () => $this->repriceChunk($products->modelKeys(), $event), self::DEADLOCK_RETRIES);
            });

        $this->markProcessed($event->messageId);
    }

    /**
     * @param  list<int>  $productIds
     */
    private function repriceChunk(array $productIds, FiatRateChanged $event): void
    {
        $rate = $event->rate;

        // price_toman = price_irr / 10, where price_irr = base * rate * (1 + profit / 100).
        Product::query()
            ->whereKey($productIds)
            ->where(fn (Builder $query) => $query
                ->whereNull('price_source_timestamp')
                ->orWhere('price_source_timestamp', '<', $event->sourceTimestamp))
            ->toBase()
            ->update([
                'price_toman' => new Expression(
                    "ROUND(base_price_fiat * {$rate} * (1 + COALESCE(profit_percent, 0) / 100.0) / 10)"
                ),
                'price_source_timestamp' => $event->sourceTimestamp,
                'updated_at' => now(),
            ]);

        $this->outbox->record(new ProductPricesChanged(
            fiatId: $event->fiatId,
            symbol: $event->symbol,
            productIds: $productIds,
            rate: $rate,
            sourceTimestamp: $event->sourceTimestamp,
            causationId: $event->messageId,
        ));
    }
}
