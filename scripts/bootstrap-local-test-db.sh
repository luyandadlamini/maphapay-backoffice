#!/bin/zsh

set -euo pipefail

MYSQL_BIN="/usr/local/mysql/bin/mysql"
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if [[ ! -x "$MYSQL_BIN" ]]; then
    echo "Expected MySQL client at $MYSQL_BIN" >&2
    exit 1
fi

set -a
source "${PROJECT_ROOT}/.env.testing"
set +a

"$MYSQL_BIN" \
  --host="${DB_HOST}" \
  --port="${DB_PORT}" \
  --user="${DB_USERNAME}" \
  --password="${DB_PASSWORD}" \
  -e "CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate --env=testing --force

echo "Test database ready on ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
