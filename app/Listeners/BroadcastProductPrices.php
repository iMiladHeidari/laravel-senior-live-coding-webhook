<?php

namespace App\Listeners;

use App\Events\ProductPricesChanged;
use App\Jobs\BroadcastSocketJob;
use App\Listeners\Concerns\ProcessesMessagesOnce;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class BroadcastProductPrices implements ShouldQueue
{
    use InteractsWithQueue, ProcessesMessagesOnce;

    public string $queue = 'broadcasts';

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [1, 5, 15];

    /**
     * Broadcasts one message per product in the chunk using a single query. Only products whose
     * price still comes from this event's rate are sent; products already repriced by a newer rate
     * are broadcast by that newer event, so a late or out-of-order delivery never sends a price
     * that does not match its fiat_rate. Socket delivery is at-least-once, so the payload carries
     * source_timestamp and message_id for clients to drop duplicates or stale data.
     */
    public function handle(ProductPricesChanged $event): void
    {
        $this->processOnce($event->messageId, function () use ($event) {
            Product::query()
                ->whereKey($event->productIds)
                ->where('price_source_timestamp', $event->sourceTimestamp)
                ->get(['id', 'name', 'base_price_fiat', 'profit_percent', 'price_toman'])
                ->each(function (Product $product) use ($event) {
                    BroadcastSocketJob::dispatchSync("products.{$product->id}", [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'fiat' => $event->symbol,
                        'fiat_rate' => $event->rate,
                        'base_price_fiat' => $product->base_price_fiat,
                        'profit_percent' => $product->profit_percent ?? '0.00',
                        'price_toman' => $product->price_toman,
                        'price_irr' => $product->price_toman * 10,
                        'source_timestamp' => $event->sourceTimestamp,
                        'message_id' => $event->messageId,
                    ]);
                });
        });
    }
}
