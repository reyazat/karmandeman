# Karmandeman - Agent Guidance

This repository contains a Laravel 13 application with initial Telegram
identity, workspace/employee provisioning, brand/profile memory, project
creation, gift-credit ledger and a manual product draft/revision/approval
slice with text normalization, SKU uniqueness, a review queue and an approved
batch snapshot, project reopen/cancel, signed Gate A approvals, a
near-duplicate warning, record Policies for project/product/wallet and
private encrypted photo storage with tenant-scoped download, product
photos limited to their own batch and bot photo intake (ADR-016 to ADR-024)
with code and tests. These are partial foundations, not a
completed MVP. Telegram text-to-product intake, AI product extraction, file exports, full wallet
lifecycle and n8n workflows are still missing. Read
`docs/PROJECT_AUDIT_2026-10-26.md` for evidence and open gates, and
`docs/DEVELOPMENT_PLAN.md` for the implementation order. Do not claim a build,
release or production deployment is working until it has been tested against
actual application behaviour. Check the file layout
before following paths in historical documents: the reference infra files
currently live at the repository root and `deploy/`.

## Source of truth
- Application architecture: `docs/ENGINEERING_STANDARDS.md` (normative).
- MVP scope and acceptance: `docs/Karmandeman_Telegram_MVP_PRD_v1.3.md`.
- Product phase gates: `docs/Karmandeman_Product_Strategy_and_Roadmap_v1.3.md`.
- Docker, security and release gates: `docs/Karmandeman_Deployment_Runbook_v1.1.md`.
- If documents disagree, flag the conflict; record an ADR before changing a
  normative decision. Do not silently expand the Telegram pilot into a full web
  workspace or implement unapproved payment flows.

## Non-negotiable boundaries
- Laravel owns accounts, workspace authorization, product state, memory and
  atomic/idempotent credit ledger; Telegram and n8n are untrusted adapters.
- Scope repository queries to authenticated workspace and enforce Policies;
  never trust a request's `workspace_id` as authority.
- No real secrets in source, logs, Docker build context or images. No public
  DB/Redis/n8n editor. Do not run migrations or deploy to production without
  approval, a backup, a staging rehearsal and rollback plan.

## Task workflows
- Feature or bug in Laravel/Vue/Telegram/n8n: use `/implement-feature`.
- Code review or security review of a change: use `/review-change`.
- Compose, Docker, release, backup or server hardening: use
  `/deployment-readiness` (review only; never deploy automatically).
- Skills live in `.devin/skills/`. Read the relevant docs and existing code,
  add positive/negative and isolation tests, run only available checks, and
  report unverified gates rather than implying they passed.

## Local development
- PHP CLI runs inside docker: `docker compose --env-file .env
  -f compose.prod.reference.yaml -f compose.dev.yaml run --rm workspace
  php artisan ...` (host PHP lacks pgsql; infra ports map to
  127.0.0.1:55432/56379 because native postgres/redis already run locally).
- n8n is the standalone instance at `https://ai.smartystudio.co.uk`
  (`N8N_API_URL`/`N8N_INTERNAL_URL` in `.env`); bundled compose n8n stays
  behind the `bundled-n8n` profile.
