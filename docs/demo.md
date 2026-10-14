# OrderForge demo runbook

Use a fresh local seed for the one-unit concurrency demo. Start from the repository root:

```bash
docker compose up --build -d
docker compose exec app php artisan db:seed --force
curl -i http://127.0.0.1:8000/health/ready
```

Open http://localhost:3000. Sign in with `customer1@orderforge.test` and `OrderForgeLocal2026!`. Select a product other than the one-unit keyboard and check out; the UI creates the order and pending mock payment together. In **Orders**, simulate success (the UI sends three duplicate deliveries). Point out the `PAID` order, `SUCCEEDED` payment, stock ledger movement, and immediate order update from Reverb. The request ID in the response can be searched in `docker compose logs app`.

Sign in as `admin@orderforge.test` to show warehouse balances, outbox delivery attempts, payment rows, dead letters, and failed queue jobs. Capture portfolio screenshots from the customer order and admin operation tabs, plus `/health/ready` and `/metrics`. Remove tokens, passwords, and private customer details from public screenshots.

## Concurrent checkout

The keyboard (`OF-KB-001`) must show one available unit. Run:

```bash
python3 scripts/concurrency_demo.py
```

The script logs in as two customers, sends 20 simultaneous unique-key checkouts, asserts one `201` and 19 `409 INSUFFICIENT_STOCK`, then cancels the successful pending order. It fails clearly if stock is not one at the start. This verifies real PostgreSQL row locks through the HTTP stack.

## Expiration and broker recovery

For expiry, set `ORDER_EXPIRATION_MINUTES=1` in root `.env`, restart the app/scheduler, create an unpaid order, wait one minute, then run `docker compose exec app php artisan orders:expire`. The order becomes `EXPIRED` and reservations are released. Restore the preferred expiry setting afterward.

For broker recovery, run `docker compose stop rabbitmq`, create an order in the dashboard, then inspect admin outbox: the business transaction succeeds and the event stays pending. Run `docker compose start rabbitmq`, `docker compose exec app php artisan outbox:publish`, and `docker compose exec app php artisan events:consume --once`. The outbox row moves to published and a unique processing receipt is stored. The scheduler and event consumer do this automatically in the normal stack.

## Verification and evidence

```bash
cd backend
php artisan test --compact
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=512M
cd ../frontend
npm run lint
npm run build
```

For query-plan evidence on a running stack:

```bash
docker compose exec -T postgres psql -U orderforge -d orderforge -c "EXPLAIN (ANALYZE, BUFFERS) SELECT i.id, i.on_hand, i.reserved FROM inventories i JOIN products p ON p.id = i.product_id WHERE p.sku = 'OF-KB-001' ORDER BY i.id FOR UPDATE OF i;"
```

Record the actual plan and timing for your machine; the seeded dataset is small, so this demonstrates indexed lookup and locking, not production scale throughput. Key interview points: why the stock authority is PostgreSQL, why idempotency keys and webhook IDs are distinct, why outbox delivery can repeat, and how the scheduler recovers unpaid reservations.
