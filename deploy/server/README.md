# Karmandeman on 5.78.96.78 (karmandeman.ir)

Single-host deploy next to the standalone n8n stack (`/root/n8n`). Set up on
2026-11-05. This is a pilot host, not HA; the runbook gates in
`docs/Karmandeman_Deployment_Runbook_v1.1.md` section 16 still apply.

## Layout

| Path | Owner, mode | Content |
| --- | --- | --- |
| `/opt/karmandeman/src` | deploy | Git checkout, detached at the running release |
| `/opt/karmandeman/.env.production` | deploy, 0600 | Compose env file (all app secrets) |
| `/opt/karmandeman/secrets/` | deploy, 0700 | `openbao-unseal-key`, `garage-rpc-secret`, `pg-app-password` |
| `/opt/karmandeman/backups/` | deploy, 0700 | `pg_dump -Fc` of `km_app` before every migration (last 30) |
| `/opt/karmandeman/bin/deploy` | root, 0755 | Copy of `deploy-entry.sh`, forced command of the GitHub key |
| `/opt/karmandeman/current-release`, `releases.log` | deploy | Running commit and history |
| `/etc/apache2/sites-available/karmandeman.ir.conf` | root | Copy of `apache-karmandeman.ir.conf` |

Traffic: Cloudflare (proxied) -> Apache 443 (Let's Encrypt cert) ->
Caddy `127.0.0.1:8090` -> php-fpm `app:9000`.

Database: role and database `km_app` on the n8n Postgres container
(`n8n-postgres-1`, PostgreSQL 15). The app reaches it as `n8n-postgres` on
the internal network `karmandeman_shared_db`; only that Postgres container
joins it, and `/root/n8n/docker-compose.yml` declares it so a recreate keeps
it. `CONNECT` on the `n8n` and `postgres` databases is revoked from `PUBLIC`.

OpenBao: file storage in volume `karmandeman_openbao_data`, one unseal key
(in `secrets/`), `openbao_unsealer` unseals it after a restart. The app token
has policy `karmandeman-app` (transit keys `ws-*` only, 10-year TTL). The
root token was revoked; make a new one with `bao operator generate-root` and
the unseal key when needed.

Garage: single node, bucket `karmandeman-private`, key `karmandeman-app`.
Admin: `docker exec karmandeman-garage-1 /garage <command>`.

## Deploys

`.github/workflows/deploy.yml` runs after Foundation checks pass on a push to
`main` and runs `ssh deploy@5.78.96.78 "deploy <sha>"`. The key is limited to
`/opt/karmandeman/bin/deploy`, which accepts only `deploy <sha>`,
`rollback <sha>` and `status`, for commits on `origin/main`.

GitHub repository secrets (Settings -> Secrets and variables -> Actions):

- `DEPLOY_HOST`: `5.78.96.78`
- `DEPLOY_SSH_KEY`: private key from `/root/karmandeman-github-deploy-key`
  (delete the server copy after saving it in GitHub)
- `DEPLOY_KNOWN_HOSTS`: content of `/root/karmandeman-github-known-hosts`

By hand on the server:

```bash
sudo -u deploy SSH_ORIGINAL_COMMAND="rollback <older sha>" /opt/karmandeman/bin/deploy
```

A rollback is safe only while the schema is backward compatible; otherwise
restore the dump taken before the migration.

Compose by hand (as `deploy`):

```bash
cd /opt/karmandeman && export KM_RELEASE=$(cut -c1-12 current-release) KM_SECRETS_DIR=/opt/karmandeman/secrets
docker compose --project-directory src --env-file .env.production -f src/compose.prod.reference.yaml -f src/deploy/server/compose.server.yaml ps
```

If `deploy-entry.sh` changes, reinstall it as root:
`install -m 755 /opt/karmandeman/src/deploy/server/deploy-entry.sh /opt/karmandeman/bin/deploy`.

## Open items

- Telegram bot token and webhook registration.
- Backups leave the host only when copied off; there is no off-site copy of
  the dumps, Garage data or the OpenBao volume yet (runbook section 12).
- Client IP behind Cloudflare is a Cloudflare edge address until Apache uses
  `mod_remoteip` with the Cloudflare ranges.
