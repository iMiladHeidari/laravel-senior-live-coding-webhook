<?php

use App\Events\FiatRateChanged;
use App\Events\ProductPricesChanged;

return [

    /*
    |--------------------------------------------------------------------------
    | Event Map
    |--------------------------------------------------------------------------
    |
    | Maps the stored event_type of an outbox message to its DomainEvent class.
    |
    */

    'events' => [
        FiatRateChanged::eventType() => FiatRateChanged::class,
        ProductPricesChanged::eventType() => ProductPricesChanged::class,
    ],

    'relay' => [
        'batch_size' => (int) env('OUTBOX_RELAY_BATCH', 200),
        'poll_interval_ms' => (int) env('OUTBOX_RELAY_POLL_MS', 200),
        'max_attempts' => (int) env('OUTBOX_MAX_ATTEMPTS', 10),
    ],

    'prune_after_days' => (int) env('OUTBOX_PRUNE_DAYS', 7),

    'product_chunk_size' => (int) env('OUTBOX_PRODUCT_CHUNK', 1000),

];
