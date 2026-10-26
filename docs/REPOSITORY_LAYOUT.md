# Repository bootstrap checklist

A Laravel 13 skeleton was bootstrapped into this root (2026-10-26); the
canonical tree below is created but features are not implemented yet. The
deployment reference files live at the current repository root
(`compose.prod.reference.yaml`, `compose.dev.yaml`, `.dockerignore`,
`.env.production.example`, `.env.example`) and `deploy/` (incl.
`Dockerfile.dev` for the local `workspace` PHP CLI service); older versioned documents refer to `infra_reference/` as the
original package layout. Use actual paths, not the historical package paths.
Bootstrap Laravel/Vue and implement the canonical tree defined by
ENGINEERING_STANDARDS before the first feature PR.

Expected application and frontend directories:

```text
app/Actions/{Feature}/
app/Http/Controllers/API/V1/{Feature}/
app/Http/Controllers/Webhooks/{Telegram,Internal,Payment}/
app/Http/Requests/{Feature}/
app/Http/Resources/{Feature}/
app/Models/
app/Repositories/Contracts/
app/Repositories/Eloquents/{Model}/
app/Services/
app/Providers/
app/Scopes/
app/Helpers/
resources/js/src/modules/{feature}/
resources/js/src/shared/{components,composables,types,utils,api}/
resources/js/src/stores/
resources/js/src/router/
```

Complementary Laravel directories are permitted: `Jobs`, `Policies`, `Events`, `Listeners`, `Data`, `Enums`. The real source and Composer/NPM lockfiles MUST exist before executing the Docker reference stack.

## Verify on a clean clone

1. `composer validate && composer install --no-interaction`
2. `npm ci && npm run build` (Node 24 LTS baseline)
3. `vendor/bin/pint --test && php artisan test`
4. `vendor/bin/phpstan analyse` after configuration
5. `npm run typecheck && npm run lint && npm run test:unit` after defining scripts
6. `docker compose --env-file .env.production -f compose.prod.reference.yaml config --quiet` from the repository root; do not share the rendered config, which contains secrets.
7. Container build and smoke tests; separate secrets, backups and staging are required.

The Compose file's `build.context: .` expects Laravel source and lockfiles in
this root. If it is moved back under `infra_reference/`, change the build
context to `..` and Dockerfile/Caddyfile paths to `infra_reference/deploy/...`;
place `.dockerignore` in the context root. See `docs/DEPLOYMENT_AUDIT.md` for
the remaining production blockers.

**No step above has been executed against an actual application in this reference package.**
