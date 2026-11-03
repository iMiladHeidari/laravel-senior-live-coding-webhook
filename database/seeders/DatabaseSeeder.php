<?php

namespace Database\Seeders;

use App\Models\Fiat;
use App\Models\Product;
use App\Models\WebhookClient;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        WebhookClient::create([
            'name' => 'Primary Price Feed Bot',
            'token' => 'token-active-live-test-12345',
            'is_active' => true,
        ]);

        WebhookClient::create([
            'name' => 'Revoked Price Feed Bot',
            'token' => 'token-inactive-revoked-99999',
            'is_active' => false,
        ]);

        $usd = Fiat::create([
            'symbol' => 'USD',
            'name' => 'US Dollar',
            'current_price_irr' => 600000,
            'last_updated_timestamp' => 1700000000,
            'is_active' => true,
        ]);

        $eur = Fiat::create([
            'symbol' => 'EUR',
            'name' => 'Euro',
            'current_price_irr' => 650000,
            'last_updated_timestamp' => 1700000000,
            'is_active' => true,
        ]);

        $gbp = Fiat::create([
            'symbol' => 'GBP',
            'name' => 'British Pound',
            'current_price_irr' => 780000,
            'last_updated_timestamp' => 1700000000,
            'is_active' => true,
        ]);

        $items = [
            [
                'name' => 'iPhone 15 Pro Max',
                'fiat' => $usd,
                'base' => 1200.00,
                'profit' => null,
            ],
            [
                'name' => 'MacBook Pro 16"',
                'fiat' => $usd,
                'base' => 2500.00,
                'profit' => 10.00,
            ],
            [
                'name' => 'Apple Watch Ultra',
                'fiat' => $usd,
                'base' => 800.00,
                'profit' => 0.00,
            ],
            [
                'name' => 'Sony PlayStation 5',
                'fiat' => $eur,
                'base' => 500.00,
                'profit' => 12.50,
            ],
            [
                'name' => 'DeLonghi Espresso Machine',
                'fiat' => $eur,
                'base' => 650.00,
                'profit' => null,
            ],
            [
                'name' => 'Dyson Airwrap Styler',
                'fiat' => $gbp,
                'base' => 450.00,
                'profit' => 15.00,
            ],
            [
                'name' => 'Marshall Stanmore III',
                'fiat' => $gbp,
                'base' => 320.00,
                'profit' => null,
            ],
        ];

        foreach ($items as $item) {
            $fiatRate = $item['fiat']->current_price_irr;
            $profitMultiplier = 1 + (($item['profit'] ?? 0) / 100);
            $priceToman = (int) round(($item['base'] * ($fiatRate / 10)) * $profitMultiplier);

            Product::create([
                'name' => $item['name'],
                'fiat_id' => $item['fiat']->id,
                'base_price_fiat' => $item['base'],
                'profit_percent' => $item['profit'],
                'price_toman' => $priceToman,
            ]);
        }

        $this->seedLoadTestProducts([$usd, $eur, $gbp], (int) env('SEED_EXTRA_PRODUCTS', 0));
    }

    /**
     * @param  list<Fiat>  $fiats
     */
    private function seedLoadTestProducts(array $fiats, int $count): void
    {
        $now = now();

        for ($offset = 0; $offset < $count; $offset += 1000) {
            $rows = [];

            for ($i = $offset; $i < min($offset + 1000, $count); $i++) {
                $fiat = $fiats[$i % count($fiats)];
                $base = random_int(1000, 500000) / 100;
                $profit = $i % 3 === 0 ? null : random_int(0, 3000) / 100;

                $rows[] = [
                    'name' => "Load Test Product {$i}",
                    'fiat_id' => $fiat->id,
                    'base_price_fiat' => $base,
                    'profit_percent' => $profit,
                    'price_toman' => (int) round($base * ($fiat->current_price_irr / 10) * (1 + ($profit ?? 0) / 100)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Product::insert($rows);
        }
    }
}
