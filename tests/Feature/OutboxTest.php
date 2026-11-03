<?php

namespace Tests\Feature;

use App\Events\DomainEvent;
use App\Events\FiatRateChanged;
use App\Events\ProductPricesChanged;
use App\Jobs\BroadcastSocketJob;
use App\Models\Fiat;
use App\Models\OutboxMessage;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const TOKEN = 'token-active-live-test-12345';

    private const TIMESTAMP = 1798965000;

    private const EXPECTED_PRICES = [
        'iPhone 15 Pro Max' => 78_000_000,          // 1200 * 650000 / 10, null profit = 0%
        'MacBook Pro 16"' => 178_750_000,           // 2500 * 650000 * 1.10 / 10
        'Apple Watch Ultra' => 52_000_000,
        'Sony PlayStation 5' => 39_937_500,         // 500 * 710000 * 1.125 / 10
        'DeLonghi Espresso Machine' => 46_150_000,
        'Dyson Airwrap Styler' => 42_435_000,       // 450 * 820000 * 1.15 / 10
        'Marshall Stanmore III' => 26_240_000,
    ];

    /**
     * @param  list<array{symbol: string, price_irr: int}>  $rates
     */
    private function sendRates(array $rates, int $timestamp = self::TIMESTAMP): void
    {
        $this->postJson('/api/webhooks/fiat-rates', ['timestamp' => $timestamp, 'rates' => $rates], ['X-Webhook-Token' => self::TOKEN])
            ->assertAccepted();
    }

    private function sendSampleRates(): void
    {
        $this->sendRates([
            ['symbol' => 'USD', 'price_irr' => 650000],
            ['symbol' => 'EUR', 'price_irr' => 710000],
            ['symbol' => 'GBP', 'price_irr' => 820000],
        ]);
    }

    private function relay(): void
    {
        $this->artisan('outbox:relay')->assertSuccessful();
    }

    private function redeliverEveryOutboxMessage(): void
    {
        OutboxMessage::orderBy('id')->get()->each(function (OutboxMessage $message) {
            /** @var class-string<DomainEvent> $class */
            $class = config('outbox.events')[$message->event_type];
            event($class::fromPayload($message->message_id, $message->payload));
        });
    }

    public function test_relay_drives_repricing_and_broadcasts_each_product_once(): void
    {
        Bus::fake([BroadcastSocketJob::class]);

        $this->sendSampleRates();
        $this->relay();

        $this->assertSame(self::EXPECTED_PRICES, Product::orderBy('id')->pluck('price_toman', 'name')->all());

        $this->assertSame(0, OutboxMessage::where('status', '!=', OutboxMessage::STATUS_PUBLISHED)->count());
        $this->assertSame(3, OutboxMessage::where('event_type', FiatRateChanged::eventType())->count());
        $this->assertSame(3, OutboxMessage::where('event_type', ProductPricesChanged::eventType())->count());

        Bus::assertDispatchedSyncTimes(BroadcastSocketJob::class, 7);
        Bus::assertDispatchedSync(BroadcastSocketJob::class, function (BroadcastSocketJob $job) {
            $macbook = Product::where('name', 'MacBook Pro 16"')->firstOrFail();

            return $job->channel === "products.{$macbook->id}"
                && $job->data['price_toman'] === 178_750_000
                && $job->data['price_irr'] === 1_787_500_000
                && $job->data['fiat'] === 'USD'
                && $job->data['fiat_rate'] === 650000
                && $job->data['source_timestamp'] === self::TIMESTAMP;
        });
    }

    public function test_running_the_relay_again_publishes_nothing(): void
    {
        Bus::fake([BroadcastSocketJob::class]);

        $this->sendSampleRates();
        $this->relay();
        $this->relay();

        Bus::assertDispatchedSyncTimes(BroadcastSocketJob::class, 7);
        $this->assertSame(6, OutboxMessage::count());
    }

    public function test_duplicate_delivery_of_every_event_has_no_extra_effect(): void
    {
        Bus::fake([BroadcastSocketJob::class]);

        $this->sendSampleRates();
        $this->relay();
        $this->redeliverEveryOutboxMessage();

        $this->assertSame(self::EXPECTED_PRICES, Product::orderBy('id')->pluck('price_toman', 'name')->all());
        $this->assertSame(6, OutboxMessage::count());
        $this->assertSame(6, DB::table('inbox_messages')->count());
        Bus::assertDispatchedSyncTimes(BroadcastSocketJob::class, 7);
    }

    public function test_relay_coalesces_a_burst_of_rate_changes_per_fiat(): void
    {
        Bus::fake([BroadcastSocketJob::class]);

        $this->sendRates([['symbol' => 'USD', 'price_irr' => 650000]], self::TIMESTAMP);
        $this->sendRates([['symbol' => 'USD', 'price_irr' => 700000]], self::TIMESTAMP + 1);
        $this->relay();

        $statuses = OutboxMessage::where('event_type', FiatRateChanged::eventType())->orderBy('id')->pluck('status')->all();
        $this->assertSame([OutboxMessage::STATUS_SUPERSEDED, OutboxMessage::STATUS_PUBLISHED], $statuses);

        $this->assertSame(84_000_000, Product::where('name', 'iPhone 15 Pro Max')->value('price_toman'));
        Bus::assertDispatchedSyncTimes(BroadcastSocketJob::class, 3);
        Bus::assertNotDispatchedSync(BroadcastSocketJob::class, fn (BroadcastSocketJob $job) => $job->data['fiat_rate'] !== 700000);
    }

    public function test_reprice_skips_an_event_whose_rate_is_no_longer_current(): void
    {
        Bus::fake([BroadcastSocketJob::class]);
        $usd = Fiat::where('symbol', 'USD')->firstOrFail();
        $before = Product::orderBy('id')->pluck('price_toman', 'id')->all();

        event(new FiatRateChanged($usd->id, 'USD', 600000, 1, self::TIMESTAMP));

        $this->assertSame($before, Product::orderBy('id')->pluck('price_toman', 'id')->all());
        $this->assertSame(0, OutboxMessage::count());
        $this->assertSame(1, DB::table('inbox_messages')->count());
    }

    public function test_an_older_rate_never_overwrites_or_broadcasts_over_a_newer_price(): void
    {
        Bus::fake([BroadcastSocketJob::class]);
        $usd = Fiat::where('symbol', 'USD')->firstOrFail();
        $older = new FiatRateChanged($usd->id, 'USD', 600000, 650000, self::TIMESTAMP);
        $newer = new FiatRateChanged($usd->id, 'USD', 650000, 700000, self::TIMESTAMP + 10);

        $usd->update(['current_price_irr' => 700000, 'last_updated_timestamp' => self::TIMESTAMP + 10]);
        event($newer);

        // The rate moves back to the older value (A -> B -> A), so the late older event passes the
        // "is my rate still current" check and only the per-product timestamp guard protects the prices.
        $usd->update(['current_price_irr' => 650000, 'last_updated_timestamp' => self::TIMESTAMP + 20]);
        event($older);
        event(new ProductPricesChanged(
            $usd->id, 'USD', Product::where('fiat_id', $usd->id)->pluck('id')->all(), 650000, self::TIMESTAMP, $older->messageId,
        ));

        $this->assertSame(84_000_000, Product::where('name', 'iPhone 15 Pro Max')->value('price_toman'));
        $this->assertSame(0, Product::where('fiat_id', $usd->id)->where('price_source_timestamp', '!=', self::TIMESTAMP + 10)->count());
        Bus::assertNotDispatchedSync(BroadcastSocketJob::class);
    }

    public function test_listener_query_count_does_not_grow_with_chunk_size(): void
    {
        Bus::fake([BroadcastSocketJob::class]);
        $usd = Fiat::where('symbol', 'USD')->firstOrFail();
        $productIds = Product::where('fiat_id', $usd->id)->orderBy('id')->pluck('id')->all();
        Product::whereKey($productIds)->update(['price_source_timestamp' => self::TIMESTAMP]);

        $queriesFor = function (array $ids) use ($usd): int {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            event(new ProductPricesChanged($usd->id, 'USD', $ids, 600000, self::TIMESTAMP, (string) str()->uuid()));

            return $queries;
        };

        $this->assertSame($queriesFor([$productIds[0]]), $queriesFor($productIds));
        Bus::assertDispatchedSyncTimes(BroadcastSocketJob::class, 1 + count($productIds));
    }

    public function test_unpublishable_message_is_retried_with_backoff_then_marked_failed(): void
    {
        config(['outbox.relay.max_attempts' => 2]);
        $this->freezeSecond();

        $message = OutboxMessage::create([
            'message_id' => '00000000-0000-0000-0000-000000000001',
            'event_type' => 'unknown.event',
            'aggregate_type' => 'fiat',
            'aggregate_id' => '1',
            'payload' => [],
            'dedup_key' => 'unknown:1',
            'available_at' => now(),
        ]);

        $this->relay();

        $message->refresh();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertTrue($message->available_at->equalTo(now()->addSeconds(2)));
        $this->assertStringContainsString('unknown.event', $message->last_error);

        $this->travel(2)->seconds();
        $this->relay();

        $this->assertSame(OutboxMessage::STATUS_FAILED, $message->refresh()->status);
    }

    public function test_prune_removes_only_old_handled_messages(): void
    {
        Bus::fake([BroadcastSocketJob::class]);

        $this->sendSampleRates();
        $this->relay();
        $this->sendRates([['symbol' => 'USD', 'price_irr' => 700000]], self::TIMESTAMP + 1);

        $this->travel(8)->days();
        $this->artisan('outbox:prune')->assertSuccessful();

        $this->assertSame([OutboxMessage::STATUS_PENDING], OutboxMessage::pluck('status')->all());
        $this->assertSame(0, DB::table('inbox_messages')->count());
    }
}
