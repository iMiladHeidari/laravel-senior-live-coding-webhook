<?php

namespace App\Services;

use App\Events\FiatRateChanged;
use App\Models\Fiat;
use App\Models\FiatPriceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

class FiatRateUpdater
{
    private const DEADLOCK_RETRIES = 3;

    public function __construct(private Outbox $outbox) {}

    /**
     * Applies the rates and records a FiatRateChanged outbox event per changed rate, atomically.
     *
     * Runs a constant number of queries regardless of how many rates the payload carries. The
     * selected fiat rows are locked (FOR UPDATE; SQLite runs IMMEDIATE transactions), so concurrent
     * webhooks for the same currency are serialized and only a strictly newer timestamp wins.
     *
     * @param  array<string, int>  $rates  symbol => price_irr
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function apply(array $rates, int $sourceTimestamp): array
    {

        // symbol, is_active, last_ts,
        $updated = DB::transaction(function () use ($rates, $sourceTimestamp) {
            $fiats = Fiat::query()
                ->whereIn('symbol', array_keys($rates))
                ->where('is_active', true)
                ->where(fn (Builder $query) => $this->olderThan($query, $sourceTimestamp))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'symbol', 'current_price_irr']);

            if ($fiats->isEmpty()) {
                return [];
            }

            $now = now();
            $rateById = $fiats->mapWithKeys(fn (Fiat $fiat) => [$fiat->id => $rates[$fiat->symbol]]);

            Fiat::query()
                ->whereKey($rateById->keys()->all())
                ->where(fn (Builder $query) => $this->olderThan($query, $sourceTimestamp))
                ->update([
                    'current_price_irr' => $this->caseById($rateById->all()),
                    'last_updated_timestamp' => $sourceTimestamp,
                    'updated_at' => $now,
                ]);

            FiatPriceHistory::query()->insertOrIgnore($fiats->map(fn (Fiat $fiat) => [
                'fiat_id' => $fiat->id,
                'price_irr' => $rateById[$fiat->id],
                'source_timestamp' => $sourceTimestamp,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            $this->outbox->recordMany($fiats
                ->filter(fn (Fiat $fiat) => $fiat->current_price_irr !== $rateById[$fiat->id])
                ->map(fn (Fiat $fiat) => new FiatRateChanged(
                    fiatId: $fiat->id,
                    symbol: $fiat->symbol,
                    previousRate: $fiat->current_price_irr,
                    rate: $rateById[$fiat->id],
                    sourceTimestamp: $sourceTimestamp,
                ))
                ->values()
                ->all());

            return $fiats->pluck('symbol')->all();
        }, self::DEADLOCK_RETRIES);

        $symbols = array_keys($rates);

        return [
            'updated' => array_values(array_intersect($symbols, $updated)),
            'skipped' => array_values(array_diff($symbols, $updated)),
        ];
    }

    private function olderThan(Builder $query, int $sourceTimestamp): void
    {
        $query->whereNull('last_updated_timestamp')
            ->orWhere('last_updated_timestamp', '<', $sourceTimestamp);
    }

    /**
     * Builds "CASE id WHEN 1 THEN 650000 ... END". Ids and rates are validated integers.
     *
     * @param  array<int, int>  $rateById
     */
    private function caseById(array $rateById): Expression
    {
        $whens = collect($rateById)
            ->map(fn (int $rate, int $id) => 'WHEN '.(int) $id.' THEN '.(int) $rate)
            ->implode(' ');

        return new Expression("CASE id {$whens} END");
    }
}
