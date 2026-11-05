# Karmandeman - Agent Guidance

This repository contains a Laravel 13 application with initial Telegram
identity, workspace/employee provisioning, brand/profile memory, project
creation, gift-credit ledger and a manual product draft/revision/approval
slice with text normalization, SKU uniqueness, a review queue and an approved
batch snapshot, project reopen/cancel, signed Gate A approvals, a
near-duplicate warning, record Policies for project/product/wallet and
private encrypted photo storage with tenant-scoped download, product
photos limited to their own batch, bot photo intake, a bot /product
command, Gate A and reject from bot buttons, batch finish/approval with
/done and an explicit current batch, product correction by replying to a
bot card with mandatory reopen of an approved batch, and the approved batch
as a JSON file from the bot, batch cancel with /cancel, and the n8n
extraction run contract (signed start and callback, strict output schema,
cost caps, timeout; off by default and always off in production) with
run-scoped signed photo links that strip metadata, bot confirmation of
suggested values that keeps their photo provenance, and a grouped bot
question for suggested prices, a legacy n8n extraction workflow export with a
Gemini model step (retired by ADR-045; the owner selected OpenAI for voice and
text processing in n8n), an OpenAI photo-extraction workflow export (ADR-046,
now `openai.v2` with input.v2 by ADR-050: each photo's caption or the seller's
reply to it is stored encrypted and sent as that photo's text, with the brand
tone; not imported into n8n, not called against the real API and not selected
in config), a run-scoped signed voice link that strips the Ogg comment
header (ADR-047; the owner chose one run for photos and voices and no
default voice rate; no run is given voices yet),
a Laravel-owned run claim so only one n8n execution pays for the model,
and a seller-accepted extraction quote whose cap is held before the run;
on success the run's real usage is charged at the quote's tariff (at least
the class minimum, at most the cap) whether or not the seller approves the
output, and failure, timeout or cancel release the hold,
and a simple seller CSV intake mapped once and imported as cited drafts,
and bot batches that stay open for input until approval, with approved
batches corrected and reopened from a reply to a product card,
and a disabled-by-default, tenant-scoped encrypted Ogg/Opus voice intake in
Laravel (no transcription, provider call or credit charge yet),
and per-charge usage in the wallet API and /balance with a daily
read-only ledger reconciliation, and one open-for-input rule shared by
the bot and the API with /projects showing files and credit used,
and for the test phase (ADR-048) voice kept off, new input refused without
spendable credit, and a configurable markup percent (default 10) on the
usage charge, with no charge when Karmandeman fails,
and correction of any product field from a reply to its card with
"label: value" lines, the card showing every value Gate A approves (ADR-051),
and a Persian catalogue PDF of an approved snapshot from the bot at a
provisional fixed price, made and charged once, resent free, with no charge
when generation fails (ADR-052), and in the same pack and price one fixed
1080x1920 story image per approved product, sent with the PDF as a document
album, at most 20 products per pack (ADR-053), and a single-host deploy on
5.78.96.78 next to n8n, deployed from GitHub after CI passes on main (ADR-054;
see `deploy/server/README.md`), an inline bot menu that routes to the same
command handlers (ADR-055), catalogue photos turned upright from their EXIF
orientation, and an unapproved, unselected policy draft v5 that names OpenAI
and the catalogue pack (ADR-056)
(ADR-016 to ADR-056) with code and tests. These
are partial foundations, not a completed MVP. An imported OpenAI workflow with a
real API test, OpenAI voice and text workflows, an approved policy naming OpenAI and voice intake, XLSX intake,
CSV/ZIP/online-catalogue exports, a final export price, export quotes, refunds, operator adjustments,
the `text` and `file_output` charge classes and reconciling reported usage
against the provider's bill are still missing. Read
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
