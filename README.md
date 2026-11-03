# Live Coding Challenge: Fiat Price Sync Webhook

## Context & Scenario
We operate a service where product catalog prices are pegged to foreign currencies (USD, EUR, etc.), but final prices are calculated and presented in IRR.

An external price feed provider sends price updates to our webhook (`POST /api/webhooks/fiat-rates`). Each webhook payload can contain price updates for one or multiple currencies.

When an update arrives:
1. **Fiat Updates:** We only update exchange rates for currencies that exist and are active in our system. A record of rate updates should be preserved for historical tracking.
2. **Product Sync:** All products tied to the updated currencies must have their IRR prices updated based on the new rate and the product's profit percentage:
   $$\text{price_irr} = (\text{base_price_fiat} \times \text{fiat_rate}) \times \left(1 + \frac{\text{profit_percent}}{100}\right)$$
   *(If a product has no profit percentage specified, it is treated as 0%).*
3. **Real-time Broadcast:** For each updated product, the updated pricing data should be broadcasted.

The webhook endpoint must be secure, protected from excessive traffic, and handle high-throughput updates efficiently.

---

## Quick Setup

1. **Database & Seed Data:**
   ```bash
   php artisan migrate:fresh --seed
   ```
   *(SQLite is pre-configured in `database/database.sqlite`)*

2. **Start Server:**
   ```bash
   php artisan serve
   ```

3. **Sample Webhook Request:**
   ```bash
   curl -X POST http://127.0.0.1:8000/api/webhooks/fiat-rates \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -H "X-Webhook-Token: token-active-live-test-12345" \
     -d '{
       "timestamp": 1798965000,
       "rates": [
         {"symbol": "USD", "price_irr": 650000},
         {"symbol": "EUR", "price_irr": 710000},
         {"symbol": "GBP", "price_irr": 820000}
       ]
     }'
   ```

---

## High-Throughput Setup (MySQL + Redis)

`.env.example` defaults to MySQL for the database and Redis for cache, rate limiting and queues:

```bash
cp .env.example .env && php artisan key:generate
docker compose up -d                       # mysql:8.4 + redis:7
php artisan migrate:fresh --seed
php artisan serve
php artisan outbox:relay --loop            # publishes outbox events (run 1+)
php artisan queue:work --queue=prices,broadcasts   # run N workers
```

The SQLite + database-queue setup above still works for local development.

Seed a large catalog for load testing with `SEED_EXTRA_PRODUCTS=100000 php artisan migrate:fresh --seed`, then for example:

```bash
hey -n 2000 -c 50 -m POST \
  -H "Content-Type: application/json" -H "X-Webhook-Token: token-active-live-test-12345" \
  -d '{"timestamp": 1798965000, "rates": [{"symbol": "USD", "price_irr": 650000}]}' \
  http://127.0.0.1:8000/api/webhooks/fiat-rates
```

---

## Submission
Commit your changes to a new branch and open a Merge Request describing your approach, architecture, and any performance trade-offs you considered.
