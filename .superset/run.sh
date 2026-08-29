#!/usr/bin/env bash
#
# Dev server for a Ghes workspace: HTTP server + queue worker + log tail + Vite.
# Ports come from .env, where .superset/setup.sh recorded free ones.
#
set -euo pipefail

cd "${SUPERSET_WORKSPACE_PATH:-$PWD}"

if [ ! -f .env ]; then
  echo "No .env — run .superset/setup.sh first." >&2
  exit 1
fi

read_env() {
  grep -E "^$1=" .env | tail -n1 | cut -d= -f2- | tr -d '"' || true
}

APP_PORT="$(read_env APP_PORT)"; APP_PORT="${APP_PORT:-8000}"
VITE_PORT="$(read_env VITE_PORT)"; VITE_PORT="${VITE_PORT:-5173}"

echo "App:  http://127.0.0.1:${APP_PORT}   Vite: ${VITE_PORT}"

exec npx concurrently -c "#93c5fd,#c4b5fd,#fb7185,#fdba74" \
  "php artisan serve --host=127.0.0.1 --port=${APP_PORT}" \
  "php artisan queue:listen --tries=1 --timeout=0" \
  "php artisan pail --timeout=0" \
  "npm run dev -- --port ${VITE_PORT} --strictPort" \
  --names=server,queue,logs,vite --kill-others
