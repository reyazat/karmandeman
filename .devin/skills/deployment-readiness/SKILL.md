---
name: deployment-readiness
description: Audit Docker Compose, images and release readiness for the single-server pilot without deploying
triggers:
  - user
  - model
---

# Deployment readiness review

1. Read `docs/Karmandeman_Deployment_Runbook_v1.1.md` sections 4-16,
   `docs/REPOSITORY_LAYOUT.md`, the actual Compose/Dockerfile/Caddyfile and
   `.dockerignore`. Distinguish reference templates from deployed artifacts.
2. Trace every build path from the Compose file location; ensure `.dockerignore`
   exists at the *actual build context root*. Check for copied secrets, pinned
   release digests/lockfiles, non-root/writable paths, isolated DB networks,
   public ports, TLS, webhook signature verification and request limits.
   Review runtime credentials (Docker env and process argv are inspectable),
   especially Redis, n8n and both Postgres instances.
3. Check backup and restore of both databases, object storage and encryption
   keys; health checks, logging without PII, monitoring, image scanning,
   staging migration/smoke tests, rollback and legal/provider gates.
   A single VPS is not HA; a Compose config check is not a security audit.
4. Run only local, non-destructive checks when tools and application source
   exist. Do not invoke `docker compose up`, migrations, production APIs or
   any server change without explicit authorization. Provide a prioritized
   blocker list, tested evidence and a separate list of unverified gates.
