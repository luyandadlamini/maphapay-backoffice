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

# Tenant-folder migrations are not picked up by `migrate`; Filament and tests use the default
# connection when tenancy is uninitialized, so ensure Phase 12 minor card schema exists.
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_24_002653_create_minor_card_limits_table.php
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_24_002653_create_minor_card_requests_table.php
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_24_002653_add_minor_account_uuid_to_cards_table.php

php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php
php artisan migrate --env=testing --force --path=database/migrations/tenant/2026_04_23_110120_add_minor_transition_columns_to_accounts_table.php

echo "Test database ready on ${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
