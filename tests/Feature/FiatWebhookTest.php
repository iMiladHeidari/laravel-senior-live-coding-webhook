<?php

namespace Tests\Feature;

use App\Events\FiatRateChanged;
use App\Models\Fiat;
use App\Models\FiatPriceHistory;
use App\Models\OutboxMessage;
use App\Models\WebhookClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FiatWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const URL = '/api/webhooks/fiat-rates';

    private const TOKEN = 'token-active-live-test-12345';

    private const TIMESTAMP = 1798965000;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'timestamp' => self::TIMESTAMP,
            'rates' => [
                ['symbol' => 'USD', 'price_irr' => 650000],
                ['symbol' => 'EUR', 'price_irr' => 710000],
                ['symbol' => 'GBP', 'price_irr' => 820000],
            ],
        ], $overrides);
    }

    private function send(array $payload, ?string $token = self::TOKEN): TestResponse
    {
        $headers = $token === null ? [] : ['X-Webhook-Token' => $token];

        return $this->postJson(self::URL, $payload, $headers);
    }

    private function rateChangedEvents(): int
    {
        return OutboxMessage::where('event_type', FiatRateChanged::eventType())->count();
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->send($this->payload(), null)->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->send($this->payload(), 'nope')->assertUnauthorized();
    }

    public function test_revoked_token_is_rejected(): void
    {
        $this->send($this->payload(), 'token-inactive-revoked-99999')->assertUnauthorized();

        $this->assertSame(600000, Fiat::where('symbol', 'USD')->value('current_price_irr'));
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_revoking_a_cached_client_takes_effect_immediately(): void
    {
        $this->send($this->payload())->assertAccepted();

        WebhookClient::where('token', self::TOKEN)->firstOrFail()->update(['is_active' => false]);

        $this->send($this->payload(['timestamp' => self::TIMESTAMP + 1]))->assertUnauthorized();
    }

    public function test_invalid_payload_is_rejected(): void
    {
        $this->send(['timestamp' => self::TIMESTAMP])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rates');

        $this->send($this->payload(['rates' => [
            ['symbol' => 'USD', 'price_irr' => 650000],
            ['symbol' => 'usd', 'price_irr' => 660000],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rates.1.symbol');

        $this->send($this->payload(['rates' => [['symbol' => 'USD', 'price_irr' => -5]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rates.0.price_irr');
    }

    public function test_it_updates_rates_and_records_history_and_outbox_events_without_queueing(): void
    {
        Queue::fake();

        $this->send($this->payload())
            ->assertAccepted()
            ->assertExactJson(['success' => true, 'updated' => ['USD', 'EUR', 'GBP'], 'skipped' => []]);

        $this->assertDatabaseHas('fiats', ['symbol' => 'USD', 'current_price_irr' => 650000, 'last_updated_timestamp' => self::TIMESTAMP]);
        $this->assertDatabaseHas('fiats', ['symbol' => 'EUR', 'current_price_irr' => 710000]);
        $this->assertDatabaseHas('fiats', ['symbol' => 'GBP', 'current_price_irr' => 820000]);
        $this->assertSame(3, FiatPriceHistory::where('source_timestamp', self::TIMESTAMP)->count());

        $usd = Fiat::where('symbol', 'USD')->firstOrFail();
        $event = OutboxMessage::where('aggregate_id', (string) $usd->id)->sole();
        $this->assertSame(OutboxMessage::STATUS_PENDING, $event->status);
        $this->assertSame(FiatRateChanged::eventType(), $event->event_type);
        $this->assertEquals([
            'fiat_id' => $usd->id,
            'symbol' => 'USD',
            'previous_rate' => 600000,
            'rate' => 650000,
            'source_timestamp' => self::TIMESTAMP,
        ], $event->payload);
        $this->assertSame(3, $this->rateChangedEvents());

        Queue::assertNothingPushed();
    }

    public function test_unknown_and_inactive_currencies_are_skipped(): void
    {
        Fiat::where('symbol', 'GBP')->update(['is_active' => false]);

        $this->send($this->payload(['rates' => [
            ['symbol' => 'USD', 'price_irr' => 650000],
            ['symbol' => 'GBP', 'price_irr' => 820000],
            ['symbol' => 'JPY', 'price_irr' => 4000],
        ]]))
            ->assertAccepted()
            ->assertJson(['updated' => ['USD'], 'skipped' => ['GBP', 'JPY']]);

        $this->assertSame(780000, Fiat::where('symbol', 'GBP')->value('current_price_irr'));
        $this->assertSame(1, FiatPriceHistory::count());
        $this->assertSame(1, $this->rateChangedEvents());
    }

    public function test_stale_or_replayed_payload_is_ignored(): void
    {
        $this->send($this->payload())->assertAccepted();
        $this->send($this->payload(['rates' => [['symbol' => 'USD', 'price_irr' => 1]]]))
            ->assertAccepted()
            ->assertJson(['updated' => [], 'skipped' => ['USD']]);
        $this->send($this->payload(['timestamp' => self::TIMESTAMP - 60, 'rates' => [['symbol' => 'EUR', 'price_irr' => 1]]]))
            ->assertJson(['updated' => [], 'skipped' => ['EUR']]);

        $this->assertSame(650000, Fiat::where('symbol', 'USD')->value('current_price_irr'));
        $this->assertSame(710000, Fiat::where('symbol', 'EUR')->value('current_price_irr'));
        $this->assertSame(3, FiatPriceHistory::count());
        $this->assertSame(3, $this->rateChangedEvents());
    }

    public function test_unchanged_rate_records_history_but_no_event(): void
    {
        $this->send($this->payload(['rates' => [['symbol' => 'USD', 'price_irr' => 600000]]]))
            ->assertAccepted()
            ->assertJson(['updated' => ['USD']]);

        $this->assertDatabaseHas('fiats', ['symbol' => 'USD', 'last_updated_timestamp' => self::TIMESTAMP]);
        $this->assertSame(1, FiatPriceHistory::count());
        $this->assertSame(0, OutboxMessage::count());
    }

    public function test_query_count_does_not_grow_with_the_number_of_rates(): void
    {
        WebhookClient::findActiveByToken(self::TOKEN);
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->send($this->payload(['rates' => [['symbol' => 'USD', 'price_irr' => 650000]]]))->assertAccepted();
        $singleRate = $queries;

        $queries = 0;
        $this->send($this->payload([
            'timestamp' => self::TIMESTAMP + 1,
            'rates' => [
                ['symbol' => 'USD', 'price_irr' => 660000],
                ['symbol' => 'EUR', 'price_irr' => 710000],
                ['symbol' => 'GBP', 'price_irr' => 820000],
            ],
        ]))->assertAccepted();

        $this->assertSame($singleRate, $queries);
    }

    public function test_endpoint_is_rate_limited_per_token(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->send([], 'bad-token')->assertUnauthorized();
        }

        $this->send([], 'bad-token')->assertTooManyRequests();
    }

    public function test_endpoint_is_rate_limited_per_ip_across_tokens(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->send([], "rotating-token-{$i}")->assertUnauthorized();
        }

        $this->send([], 'yet-another-token')->assertTooManyRequests();
    }
}
