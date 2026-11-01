#!/usr/bin/env bash
# Checks a real env file against compose.prod.reference.yaml before a deploy.
# Each feature adds required `${VAR:?}` keys to Compose; a stale server env
# file then fails `docker compose config`. This names the keys to fix and
# never prints a value.
#
# Usage: deploy/preflight-env.sh [.env.production] [--allow-placeholders]
set -euo pipefail

env_file=.env.production
allow_placeholders=0
for arg in "$@"; do
    case "$arg" in
        --allow-placeholders) allow_placeholders=1 ;;
        *) env_file=$arg ;;
    esac
done
root=$(cd "$(dirname "$0")/.." && pwd)
compose="$root/compose.prod.reference.yaml"
[ -f "$env_file" ] || { echo "preflight: $env_file not found" >&2; exit 2; }

# Last assignment wins, like Compose; strip surrounding quotes.
value_of() {
    sed -n "s/^$1=//p" "$env_file" | tail -n 1 | sed -E "s/^['\"](.*)['\"]$/\1/"
}

problems=0
report() { echo "preflight: $1" >&2; problems=$((problems + 1)); }

for key in $(grep -oE '\$\{[A-Z0-9_]+:\?' "$compose" | sed -E 's/^\$\{//; s/:\?$//' | sort -u); do
    [ -n "$(value_of "$key")" ] || report "$key is required by Compose but missing or empty"
done

if [ "$allow_placeholders" -eq 0 ]; then
    for key in $(grep -oE '^[A-Z0-9_]+=' "$env_file" | tr -d '=' | sort -u); do
        if value_of "$key" | grep -qE 'REPLACE_|CHANGE_?ME|example\.(invalid|com)'; then
            report "$key still holds a placeholder"
        fi
    done
fi

# The bundled n8n runs only under its profile; without it this host never resolves.
if ! grep -qE '^COMPOSE_PROFILES=.*bundled-n8n' "$env_file"; then
    for key in N8N_INTERNAL_URL N8N_API_URL; do
        case "$(value_of "$key")" in
            http://n8n:*|'') report "$key targets the bundled n8n, which is off without the bundled-n8n profile" ;;
        esac
    done
fi

if [ "$problems" -gt 0 ]; then
    echo "preflight: $problems problem(s) in $env_file" >&2
    exit 1
fi
echo "preflight: $env_file matches the Compose requirements"
