---
name: implement-feature
description: Implement or fix a Karmandeman Laravel, Vue, Telegram or n8n feature with contracts and tests
triggers:
  - user
  - model
---

# Implement a feature

1. Read `docs/ENGINEERING_STANDARDS.md` and the relevant PRD acceptance criteria
   in `docs/Karmandeman_Telegram_MVP_PRD_v1.3.md`. Check the actual repository
   tree first: this package may still contain only specifications. For a new
   product capability, check the phase gate in the product strategy.
2. State the use case, changed files, data flow, trust boundaries and tests.
   Flag deviations from the normative standard before implementation; record
   an ADR when necessary. Avoid inventing future-phase modules.
3. Laravel: thin API/V1 controller -> feature FormRequest or verified webhook
   DTO -> single-purpose `execute()` Action -> repository contract and Eloquent
   implementation -> Resource. Bind interfaces in Providers. Use Services only
   for external integration or real orchestration. Keep models lean.
4. Enforce workspace-scoped queries AND Policies. Treat n8n output and Telegram
   payloads as untrusted; validate signed webhooks/callbacks, deduplicate
   retries and dispatch external side effects after commit. Money is integer
   minor units with currency, locked transaction and unique idempotency key.
   Do not assume a payment channel is approved for Telegram digital goods.
5. Vue (when in scope): feature-first Vue 3/TypeScript, typed Resource DTOs,
   Pinia/Router for shared state/navigation, accessible mobile/RTL/error states;
   backend authorization remains authoritative.
6. Add focused positive, negative, tenant-isolation and replay/concurrency
   tests as applicable; verify API and n8n schema compatibility. Run the
   available checks in `docs/ENGINEERING_STANDARDS.md` section 8. Report exact
   results, missing prerequisites and remaining risks; never claim tests ran
   when there is no application or lockfile.
