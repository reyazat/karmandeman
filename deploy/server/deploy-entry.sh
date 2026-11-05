#!/usr/bin/env bash
# Entry point for deploys on the Karmandeman host. Installed as
# /opt/karmandeman/bin/deploy and bound as the forced command of the GitHub
# deploy key (authorized_keys `command=`), so that key can only run:
#   deploy <commit>     deploy a commit on origin/main; skipped when the running
#                       release already contains it (a late, older CI run)
#   rollback <commit>   deploy an older commit on origin/main on purpose
#   status              print the running release
# The release steps live in the checked-out commit (release.sh), so this file
# changes only when the entry contract changes; reinstall it by hand then.
set -euo pipefail

root=/opt/karmandeman
src=$root/src
current_file=$root/current-release

cmd=${SSH_ORIGINAL_COMMAND:-$*}
read -r action sha extra <<<"$cmd" || true

if [ "${action:-}" = status ]; then
    cat "$current_file" 2>/dev/null || echo "no release yet"
    exit 0
fi
if [ -n "${extra:-}" ] || ! [[ "${action:-}" =~ ^(deploy|rollback)$ ]] || ! [[ "${sha:-}" =~ ^[0-9a-f]{40}$ ]]; then
    echo "usage: deploy <40-hex commit> | rollback <40-hex commit> | status" >&2
    exit 64
fi

exec 9>"$root/deploy.lock"
flock -w 900 9 || { echo "another deploy is still running" >&2; exit 75; }

cd "$src"
# The host resolver (local BIND) sometimes fails a lookup while it tries
# IPv6 nameservers the host cannot reach; retry instead of failing the deploy.
for attempt in 1 2 3 4 5; do
    git fetch --quiet origin main && break
    [ "$attempt" -lt 5 ] || { echo "git fetch failed $attempt times" >&2; exit 69; }
    echo "git fetch failed (attempt $attempt), retrying" >&2
    sleep $((attempt * 5))
done
if ! git merge-base --is-ancestor "$sha" origin/main; then
    echo "commit $sha is not on origin/main" >&2
    exit 65
fi

current=$(cut -d' ' -f1 "$current_file" 2>/dev/null || true)
if [ "$action" = deploy ] && [ -n "$current" ] && [ "$current" != "$sha" ] \
    && git merge-base --is-ancestor "$sha" "$current" 2>/dev/null; then
    echo "release $current already contains $sha; nothing to do (use rollback to go back)"
    exit 0
fi

git checkout --quiet --force --detach "$sha"
exec bash "$src/deploy/server/release.sh" "$sha"
