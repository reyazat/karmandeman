# Karmandeman / کارمند من

Laravel owns product data, workspace authorization, memory and credits.
Telegram is the pilot interface; n8n is an untrusted workflow adapter.

The implementation is an early foundation, **not a complete or production-ready MVP**.
Read [the audit](docs/PROJECT_AUDIT_2026-10-26.md) and
[the development plan](docs/DEVELOPMENT_PLAN.md) before choosing the next feature.
The normative architecture is [ENGINEERING_STANDARDS](docs/ENGINEERING_STANDARDS.md).

## Local verification

PHP 8.4 runs through the development `workspace` container. Use the existing
local environment and never share rendered Compose configuration or dotenv.

```sh
docker compose --env-file .env -f compose.prod.reference.yaml -f compose.dev.yaml run --rm --no-deps -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= workspace php artisan test
docker compose --env-file .env -f compose.prod.reference.yaml -f compose.dev.yaml run --rm --no-deps workspace composer validate --strict
docker compose --env-file .env -f compose.prod.reference.yaml -f compose.dev.yaml run --rm --no-deps workspace vendor/bin/pint --test
docker compose --env-file .env -f compose.prod.reference.yaml -f compose.dev.yaml run --rm --no-deps workspace composer analyse
```

Tests refuse a database other than SQLite `:memory:` or the explicitly named
`karmandeman_testing` database. PostgreSQL tests must use a **separate disposable
instance**, never the app DB with a renamed connection. Use Node 24 and `npm ci`,
`npm run typecheck`, `npm run lint`, `npm run test:unit`, `npm run build` for the
frontend. Empty frontend routes mean there is no working review UI yet; do not
add dummy tests to conceal that gap.

Telegram responses now use an encrypted database outbox on connection/queue
`telegram`, in the same transaction as domain changes. A dedicated worker is
required: `php artisan queue:work telegram --queue=telegram --sleep=1 --timeout=20 --tries=5`.
The Compose reference includes `telegram_worker`; it has not been started.
Horizon only consumes Redis queues. See [ADR-008](docs/adr/ADR-008-foundation-hardening.md)
for transaction semantics, delivery limits and release requirements. The
reference worker has not been deployed or started.
