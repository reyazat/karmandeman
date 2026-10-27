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

When building through Docker on WSL, run Node as the workspace user so Vite's
temporary files and build outputs remain writable on the next local run:

```sh
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/app" -w /app node:24-trixie-slim npm run build
```

Telegram responses now use an encrypted database outbox on connection/queue
`telegram`, in the same transaction as domain changes. A dedicated worker is
required: `php artisan queue:work telegram --queue=telegram --sleep=1 --timeout=20 --tries=5`.
The Compose reference includes `telegram_worker`; it has not been started.
Horizon only consumes Redis queues. See [ADR-008](docs/adr/ADR-008-foundation-hardening.md)
for transaction semantics, delivery limits and release requirements. The
reference worker has not been deployed or started.

The Persian pilot terms and retention schedule are in the
[policy approval guide](docs/PILOT_DATA_POLICY_v1.0.md). The current bundle is
**pending approval**: Telegram onboarding cannot collect final consent or
continue until its exact content and implementation evidence are approved.
Support receipts and account deletion remain available. `/delete_data` asks for
a signed, ten-minute confirmation; confirming records one `account_deletions`
row with T0 and a T0+168h deadline, marks the workspace `deletion_pending`,
revokes API tokens and rejects every later mutation (FR-086). API brand and
project mutations require consent to the exact approved policy. Purge of data,
backups and keys (FR-087), the Mini App delete button (FR-085), the operator
path and deadline monitoring are not implemented; they remain release
requirements.

The owner-directed requirements in
[ADR-011](docs/adr/ADR-011-seven-day-complete-erasure.md) set a
fourteen-day maximum backup age and complete erasure within seven days of a
verified request, including backups. Private-data encryption and an authenticated
account deletion button are P0 requirements in the PRD. The selected policy
candidate is `pilot-20261027-v3`; the v1/v2 proposals are archived. Recording these
requirements does not implement or activate them.

Read the current policy status without changing data:

```sh
docker compose --env-file .env -f compose.prod.reference.yaml -f compose.dev.yaml run --rm --no-deps workspace php artisan policy:check
```

Exit code 1 with `ready=false` is expected for the current candidate. See
[ADR-009](docs/adr/ADR-009-pilot-policy-and-erasure.md) for approval, migration
and release gates; test fixtures never approve the real bundle.
