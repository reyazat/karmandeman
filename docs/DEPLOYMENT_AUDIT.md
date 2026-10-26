# Deployment reference audit

> Historical reference audit. The repository now contains partial application
> features and locked dependencies. See [the 2026-10-26 application audit](PROJECT_AUDIT_2026-10-26.md)
> for current test evidence and remaining release blockers. Claims below about
> missing source or unexecuted checks describe the earlier bootstrap state.

This is a local review of the single-VPS Docker Compose template, not a
production certification. The repository does not contain Laravel/Vue source,
Composer/NPM lockfiles, workflow exports, a PDF renderer or a running server.
The versioned runbook remains the release policy; the files here are examples.

## Corrections in the current template

- Compose build context now points at this repository root; Dockerfile and
  Caddyfile paths resolve here. The active root `.dockerignore` excludes dotenv
  files, local Devin settings, archives, tests and key material from builds.
  `.gitignore` also protects future local dotenv and private keys from
  accidental commits. Verify the full build context again after bootstrap.
- The Laravel environment uses an explicit allowlist instead of forwarding
  the combined dotenv to every Laravel process. In particular, Laravel no
  longer receives `N8N_ENCRYPTION_KEY` or the n8n DB password.
- Networks isolate the application database, n8n database and Redis. Only
  Caddy publishes ports 80/443. The bundled `n8n`/`postgres_n8n` services are
  gated behind the `bundled-n8n` Compose profile and are not started by
  default: n8n currently runs as a standalone container on the same host,
  reachable publicly at `ai.smartystudio.co.uk` (editor exposure remains a
  blocker below) or via a shared docker network from `app`/`horizon`
  (`N8N_API_URL`/`N8N_INTERNAL_URL` select the path). Callback HTTP must go
  through an authenticated Laravel endpoint via Caddy (TLS/public hostname),
  **not** to PHP-FPM port 9000.
- PHP workers run as `www-data`; Redis no longer receives its password in the
  visible `redis-server` process arguments. These are reference hardening
  measures and still need build/startup tests with the real app and images.

## Production blockers still open

1. **No feature implementation or release artifact yet.** A Laravel 13/Vue
   skeleton with lockfiles now exists and `migrate`/`test`/build pass via the
   `workspace` dev container, but MVP features, bot/n8n callbacks and the PDF
   renderer are unimplemented. Build both image targets and exercise HTTP,
   queue, scheduler, Redis and DB integration in staging before calling this
   stack deployable.
2. **Runtime secrets.** `.env.production` must stay out of Git and images and
   be permission 0600. Compose interpolation places DB, n8n (including
   `N8N_API_KEY`), Telegram, S3 and Redis credentials in container environment and rendered `docker compose
   config`; Docker admins can inspect them. Migrate to a managed secret store
   or Docker secret files supported by each application, restrict Docker/host
   access and plan rotation. Do not paste rendered config into tickets/logs.
3. **Least-privilege database roles.** `POSTGRES_USER` is a superuser in the
   official image; create separate non-superuser runtime roles for app and
   n8n using reviewed, idempotent bootstrap/migration steps. Do not manually
   run unreviewed SQL against existing volumes. Restrict DB and n8n egress
   where practical; Docker bridge isolation is not a full egress firewall.
4. **Immutable, audited release.** Replace floating PHP/Node/Composer/Caddy/
   Redis tags and fixed version tags for Postgres/n8n with tested digests in
   the production release manifest; scan images/SBOM and lockfiles. The
   `COPY . .` build step must be checked for new secret file types on each
   repo expansion. Run PHP extension/platform checks and production asset
   compatibility checks in CI.
5. **Durability and recovery.** Implement encrypted off-host backups for
   both databases, private versioned object storage and protected copies of
   `APP_KEY`/`N8N_ENCRYPTION_KEY`. Test an isolated restore with webhook and
   payment callbacks disabled, followed by ledger/queue reconciliation.
   Named volumes and Redis AOF on one VPS are not disaster recovery.
6. **Application-level and host controls.** Enforce webhook secret, HMAC
   callback TTL/replay protection, workspace authorization, request limits,
   admin MFA, n8n editor access by VPN/tunnel, and no public Horizon. Add
   runtime health probes/alerts, resource budgets, log redaction, host firewall
   and SSH hardening. Verify approved AI providers, n8n licensing and payment
   channel policy before go-live. A single host has no HA.

## Local verification and staging gate

`docker compose --env-file .env.production.example -f
compose.prod.reference.yaml config --quiet` checks the template syntax with
placeholders; it does not start containers, verify credentials or validate the
security of the resulting service. Before production, use a separate staging
environment with synthetic data, real locked dependencies and pinned images:
build both targets; test the Telegram webhook, signed n8n callback, worker
retries/duplicate charge protection, PDF/CSV, TLS, restore and backward-
compatible migrations. Take a fresh production backup and obtain explicit
release approval before any migration, deploy or rollback. Never share
`docker compose config` without `--quiet` when using real credentials.

The legacy `.devin/rules/*.mdc` files use Cursor-style frontmatter but Devin
CLI's native `.devin/rules/` loader reads `*.md`, not `*.mdc`. The active
agent instructions are root `AGENTS.md` and `.devin/skills/*/SKILL.md`;
do not rely on the legacy `.mdc` files for enforcement.
