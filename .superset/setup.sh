#!/usr/bin/env bash
#
# Superset workspace setup for Ghes (Laravel 13 + Inertia/React, PostgreSQL).
#
# Each workspace gets:
#   - its own .env (copied from the root checkout so API keys carry over)
#   - its own PostgreSQL database  (ghes_ws_<workspace>)
#   - its own Meilisearch index prefix
#   - its own free ports for `artisan serve` and Vite
#
set -euo pipefail

WORKSPACE_PATH="${SUPERSET_WORKSPACE_PATH:-$PWD}"
cd "$WORKSPACE_PATH"

WORKSPACE_NAME="${SUPERSET_WORKSPACE_NAME:-$(basename "$WORKSPACE_PATH")}"
SLUG="$(printf '%s' "$WORKSPACE_NAME" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '_' | sed 's/__*/_/g; s/^_//; s/_$//')"
SLUG="$(printf '%s' "${SLUG:-workspace}" | cut -c1-40)"
DB_NAME="ghes_ws_${SLUG}"

say() { printf '\033[1;34m==>\033[0m %s\n' "$1"; }
warn() { printf '\033[1;33m!!\033[0m %s\n' "$1"; }

# --- .env ------------------------------------------------------------------
if [ ! -f .env ]; then
  if [ -n "${SUPERSET_ROOT_PATH:-}" ] && [ -f "$SUPERSET_ROOT_PATH/.env" ]; then
    say "Copying .env from the root checkout"
    cp "$SUPERSET_ROOT_PATH/.env" .env
  else
    say "Creating .env from .env.example"
    cp .env.example .env
  fi
fi

set_env() {
  local key="$1" value="$2"
  if grep -qE "^${key}=" .env; then
    awk -v k="$key" -v v="$value" -F= '$1 == k { print k "=" v; next } { print }' .env > .env.superset.tmp
    mv .env.superset.tmp .env
  else
    printf '%s=%s\n' "$key" "$value" >> .env
  fi
}

get_env() {
  local key="$1" fallback="${2:-}"
  local line
  line="$(grep -E "^${key}=" .env | tail -n1 | cut -d= -f2- | tr -d '"' || true)"
  printf '%s' "${line:-$fallback}"
}

free_port() {
  local port="$1"
  while lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1; do
    port=$((port + 1))
  done
  printf '%s' "$port"
}

APP_PORT="$(get_env APP_PORT)"
[ -n "$APP_PORT" ] || APP_PORT="$(free_port 8000)"
VITE_PORT="$(get_env VITE_PORT)"
[ -n "$VITE_PORT" ] || VITE_PORT="$(free_port $((APP_PORT + 1000)))"

set_env APP_PORT "$APP_PORT"
set_env VITE_PORT "$VITE_PORT"
set_env APP_URL "http://127.0.0.1:${APP_PORT}"
set_env DB_DATABASE "$DB_NAME"
set_env SCOUT_PREFIX "ghes_${SLUG}_"

# --- dependencies ----------------------------------------------------------
say "Installing PHP dependencies"
composer install --no-interaction --prefer-dist

say "Installing JS dependencies"
if [ -f package-lock.json ]; then
  npm ci --no-audit --no-fund || npm install --no-audit --no-fund
else
  npm install --no-audit --no-fund
fi

grep -qE '^APP_KEY=.+' .env || php artisan key:generate --ansi --no-interaction
php artisan storage:link --no-interaction >/dev/null 2>&1 || true

# --- database --------------------------------------------------------------
DB_HOST="$(get_env DB_HOST 127.0.0.1)"
DB_PORT="$(get_env DB_PORT 5432)"
DB_USER="$(get_env DB_USERNAME postgres)"
DB_PASS="$(get_env DB_PASSWORD)"
[ -n "$DB_PASS" ] && [ "$DB_PASS" != "null" ] && export PGPASSWORD="$DB_PASS"

if pg_isready -h "$DB_HOST" -p "$DB_PORT" >/dev/null 2>&1; then
  if psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres -tAc \
      "SELECT 1 FROM pg_database WHERE datname = '${DB_NAME}'" | grep -q 1; then
    say "Database ${DB_NAME} already exists"
  else
    say "Creating database ${DB_NAME}"
    createdb -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$DB_NAME"
  fi

  say "Running migrations"
  php artisan migrate --force --no-interaction
else
  warn "PostgreSQL is not reachable at ${DB_HOST}:${DB_PORT} — skipped database creation and migrations."
  warn "Start it, then run: createdb ${DB_NAME} && php artisan migrate"
fi

say "Building frontend assets"
npm run build

cat <<SUMMARY

Workspace ready.
  app     http://127.0.0.1:${APP_PORT}
  vite    port ${VITE_PORT}
  db      ${DB_NAME}
  scout   ghes_${SLUG}_

Press Run to start the dev server, queue worker, log tail and Vite.
SUMMARY
