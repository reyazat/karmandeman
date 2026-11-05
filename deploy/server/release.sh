#!/usr/bin/env bash
# Builds and starts one release on the Karmandeman host. Run through
# /opt/karmandeman/bin/deploy (deploy-entry.sh), which holds the deploy lock
# and has already checked out the commit.
#
# Rollback is a deploy of an older commit (`deploy rollback <commit>`); it is
# safe only while the database schema stays backward compatible (runbook
# section 11). A destructive migration needs the backup taken below.
set -euo pipefail

sha=$1
root=/opt/karmandeman
src=$root/src
env_file=$root/.env.production
backups=$root/backups
export KM_RELEASE=${sha:0:12}
export KM_SECRETS_DIR=$root/secrets

compose() {
    docker compose --project-directory "$src" --env-file "$env_file" \
        -f "$src/compose.prod.reference.yaml" -f "$src/deploy/server/compose.server.yaml" "$@"
}
log() { printf '%s release %s: %s\n' "$(date -u +%FT%TZ)" "$KM_RELEASE" "$*"; }
env_value() { sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed -E "s/^['\"](.*)['\"]$/\1/"; }

log "preflight"
"$src/deploy/preflight-env.sh" "$env_file"
compose config --quiet

if docker image inspect "karmandeman-app:$KM_RELEASE" "karmandeman-web:$KM_RELEASE" >/dev/null 2>&1; then
    log "images already built"
else
    log "building images"
    compose build app web
fi

log "starting redis, openbao, garage"
compose up -d --no-build --wait --wait-timeout 180 redis openbao openbao_unsealer garage

log "backing up the database"
install -d -m 700 "$backups"
dump="$backups/karmandeman-$(date -u +%Y%m%dT%H%M%SZ)-$KM_RELEASE.dump"
docker run --rm --network karmandeman_shared_db \
    -e PGPASSWORD="$(env_value PG_APP_PASSWORD)" \
    postgres:17.11-alpine \
    pg_dump -h "$(env_value DB_HOST)" -U "$(env_value PG_APP_USER)" -d "$(env_value PG_APP_DB)" -Fc >"$dump.part"
mv "$dump.part" "$dump"
chmod 600 "$dump"
ls -1t "$backups"/*.dump | tail -n +31 | xargs -r rm --

log "running migrations"
compose run --rm --no-deps app php artisan migrate --force --no-interaction

log "starting the application"
compose up -d --no-build --remove-orphans --wait --wait-timeout 180

log "smoke test"
port=$(env_value KM_WEB_PORT)
curl -fsS --max-time 10 -o /dev/null "http://127.0.0.1:${port:-8090}/up"
# Exits with the given code while any migration is still pending.
compose exec -T app php artisan migrate:status --pending=1 --no-interaction >/dev/null \
    || { log "pending migrations after deploy"; exit 1; }

echo "$sha $(date -u +%FT%TZ)" >"$root/current-release"
echo "$sha $(date -u +%FT%TZ)" >>"$root/releases.log"

log "pruning old images (keeping 3)"
for repo in karmandeman-app karmandeman-web; do
    docker image ls "$repo" --format '{{.CreatedAt}}\t{{.Tag}}' | sort -r \
        | awk -F'\t' -v keep="$KM_RELEASE" 'NR > 3 && $2 != keep { print $2 }' \
        | while read -r tag; do docker image rm "$repo:$tag" >/dev/null 2>&1 || true; done
done
docker builder prune -f --filter until=168h >/dev/null 2>&1 || true

log "done"
