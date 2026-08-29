#!/usr/bin/env bash
#
# Superset workspace teardown: drop the workspace-scoped PostgreSQL database
# and its Meilisearch indexes. Never touches the root checkout's data.
#
set -uo pipefail

cd "${SUPERSET_WORKSPACE_PATH:-$PWD}" 2>/dev/null || exit 0
[ -f .env ] || exit 0

read_env() {
  grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '"' || true
}

DB_NAME="$(read_env DB_DATABASE)"
DB_HOST="$(read_env DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(read_env DB_PORT)"; DB_PORT="${DB_PORT:-5432}"
DB_USER="$(read_env DB_USERNAME)"; DB_USER="${DB_USER:-postgres}"
DB_PASS="$(read_env DB_PASSWORD)"
[ -n "$DB_PASS" ] && [ "$DB_PASS" != "null" ] && export PGPASSWORD="$DB_PASS"

# Safety: only ever drop databases this setup script created.
case "$DB_NAME" in
  ghes_ws_*)
    if pg_isready -h "$DB_HOST" -p "$DB_PORT" >/dev/null 2>&1; then
      echo "Dropping database ${DB_NAME}"
      dropdb --if-exists -f -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME"
    fi
    ;;
  *)
    echo "Leaving database '${DB_NAME}' alone (not a workspace database)."
    ;;
esac

# Best-effort Meilisearch cleanup for this workspace's index prefix.
SCOUT_PREFIX="$(read_env SCOUT_PREFIX)"
MEILI_HOST="$(read_env MEILISEARCH_HOST)"
MEILI_KEY="$(read_env MEILISEARCH_KEY)"
case "$SCOUT_PREFIX" in
  ghes_ws_*|ghes_*_)
    if [ "$SCOUT_PREFIX" != "ghes_" ] && [ -n "$MEILI_HOST" ] && curl -sf -m 3 "${MEILI_HOST}/health" >/dev/null 2>&1; then
      AUTH=()
      [ -n "$MEILI_KEY" ] && AUTH=(-H "Authorization: Bearer ${MEILI_KEY}")
      curl -sf -m 5 "${AUTH[@]+"${AUTH[@]}"}" "${MEILI_HOST}/indexes?limit=1000" 2>/dev/null \
        | tr ',' '\n' | grep -o "\"uid\":\"${SCOUT_PREFIX}[^\"]*\"" | cut -d'"' -f4 \
        | while read -r index; do
            echo "Deleting Meilisearch index ${index}"
            curl -sf -m 5 -X DELETE "${AUTH[@]+"${AUTH[@]}"}" "${MEILI_HOST}/indexes/${index}" >/dev/null 2>&1 || true
          done
    fi
    ;;
esac

exit 0
