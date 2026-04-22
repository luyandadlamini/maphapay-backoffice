#!/bin/zsh

set -euo pipefail

MYSQL_BASE="/usr/local/mysql"
MYSQLD_BIN="${MYSQL_BASE}/bin/mysqld"
MYSQLADMIN_BIN="${MYSQL_BASE}/bin/mysqladmin"
MYSQL_BIN="${MYSQL_BASE}/bin/mysql"
LAUNCHD_PLIST="/Library/LaunchDaemons/com.oracle.oss.mysql.mysqld.plist"
SOCKET_PATH="/tmp/mysql.sock"
MYSQL_TMPDIR="/tmp"
MYSQL_ERROR_LOG="${MYSQL_BASE}/data/mysqld.local.err"
MYSQL_PID_FILE="${MYSQL_BASE}/data/mysqld.local.pid"

TEST_DB_NAME="${TEST_DB_NAME:-maphapay_backoffice_test}"
TEST_DB_USER="${TEST_DB_USER:-maphapay_test}"
TEST_DB_PASSWORD="${TEST_DB_PASSWORD:-maphapay_test_password}"
ROOT_PASSWORD="${ROOT_PASSWORD:-maphapay_root_local}"

for required in "$MYSQLD_BIN" "$MYSQLADMIN_BIN" "$MYSQL_BIN" "$LAUNCHD_PLIST"; do
    if [[ ! -e "$required" ]]; then
        echo "Missing required MySQL component: $required" >&2
        exit 1
    fi
done

INIT_SQL="$(mktemp /tmp/maphapay-mysql-init.XXXXXX)"
cleanup() {
    rm -f "$INIT_SQL"
}
trap cleanup EXIT

cat >"$INIT_SQL" <<SQL
FLUSH PRIVILEGES;
ALTER USER 'root'@'localhost' IDENTIFIED BY '${ROOT_PASSWORD}';
CREATE DATABASE IF NOT EXISTS \`${TEST_DB_NAME}\`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${TEST_DB_USER}'@'localhost' IDENTIFIED BY '${TEST_DB_PASSWORD}';
ALTER USER '${TEST_DB_USER}'@'localhost' IDENTIFIED BY '${TEST_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${TEST_DB_NAME}\`.* TO '${TEST_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
chmod 0644 "$INIT_SQL"

read -r -d '' RESET_COMMAND <<'SH' || true
set -euo pipefail

MYSQL_BASE="/usr/local/mysql"
MYSQLD_BIN="${MYSQL_BASE}/bin/mysqld"
MYSQLADMIN_BIN="${MYSQL_BASE}/bin/mysqladmin"
MYSQL_BIN="${MYSQL_BASE}/bin/mysql"
LAUNCHD_PLIST="/Library/LaunchDaemons/com.oracle.oss.mysql.mysqld.plist"
SOCKET_PATH="/tmp/mysql.sock"
MYSQL_TMPDIR="/tmp"
MYSQL_ERROR_LOG="${MYSQL_BASE}/data/mysqld.local.err"
MYSQL_PID_FILE="${MYSQL_BASE}/data/mysqld.local.pid"
ROOT_PASSWORD="__ROOT_PASSWORD__"

launchctl bootout system/com.oracle.oss.mysql.mysqld >/dev/null 2>&1 || true
pkill -f "/usr/local/mysql/bin/mysqld" >/dev/null 2>&1 || true
sleep 2
rm -f "${SOCKET_PATH}"

"${MYSQLD_BIN}" \
  --user=_mysql \
  --basedir=/usr/local/mysql \
  --datadir=/usr/local/mysql/data \
  --tmpdir="${MYSQL_TMPDIR}" \
  --plugin-dir=/usr/local/mysql/lib/plugin \
  --log-error=/usr/local/mysql/data/mysqld.local.err \
  --pid-file=/usr/local/mysql/data/mysqld.local.pid \
  --keyring-file-data=/usr/local/mysql/keyring/keyring \
  --early-plugin-load=keyring_file=keyring_file.so \
  --socket="${SOCKET_PATH}" \
  --skip-grant-tables \
  --skip-networking \
  --daemonize \
  >/tmp/maphapay-mysql-reset.stdout 2>/tmp/maphapay-mysql-reset.stderr

READY=0
for _ in $(seq 1 60); do
  if [[ -S "${SOCKET_PATH}" ]]; then
    PING_OUTPUT="$("${MYSQLADMIN_BIN}" --protocol=socket --socket="${SOCKET_PATH}" -uroot ping 2>&1 || true)"
    if [[ "${PING_OUTPUT}" == *"mysqld is alive"* || "${PING_OUTPUT}" == *"Access denied for user"* ]]; then
      READY=1
      break
    fi
  fi
  sleep 1
done

if [[ "${READY}" -ne 1 ]]; then
  echo "Temporary MySQL bootstrap instance did not become ready." >&2
  if [[ -f /tmp/maphapay-mysql-reset.stderr ]]; then
    cat /tmp/maphapay-mysql-reset.stderr >&2
  fi
  if [[ -f /tmp/maphapay-mysql-reset.stdout ]]; then
    cat /tmp/maphapay-mysql-reset.stdout >&2
  fi
  if [[ -f "${MYSQL_ERROR_LOG}" ]]; then
    echo "--- mysqld.local.err ---" >&2
    tail -n 120 "${MYSQL_ERROR_LOG}" >&2 || true
  fi
  launchctl bootstrap system "${LAUNCHD_PLIST}" >/dev/null 2>&1 || true
  launchctl kickstart -k system/com.oracle.oss.mysql.mysqld >/dev/null 2>&1 || true
  exit 1
fi

"${MYSQL_BIN}" --protocol=socket --socket="${SOCKET_PATH}" -uroot < "__INIT_SQL__"

if [[ -f "${MYSQL_PID_FILE}" ]]; then
  kill "$(cat "${MYSQL_PID_FILE}")"
else
  pkill -f "/usr/local/mysql/bin/mysqld.*--skip-grant-tables" >/dev/null 2>&1 || true
fi

sleep 2
launchctl bootstrap system "${LAUNCHD_PLIST}" >/dev/null 2>&1 || true
launchctl kickstart -k system/com.oracle.oss.mysql.mysqld

for _ in $(seq 1 30); do
  if "${MYSQLADMIN_BIN}" --protocol=tcp --host=127.0.0.1 --port=3306 -uroot --password="${ROOT_PASSWORD}" ping >/dev/null 2>&1; then
    exit 0
  fi
  sleep 1
done

echo "Original MySQL launch daemon did not come back up after reset." >&2
if [[ -f "${MYSQL_ERROR_LOG}" ]]; then
  echo "--- mysqld.local.err ---" >&2
  tail -n 120 "${MYSQL_ERROR_LOG}" >&2 || true
fi
exit 1
SH

RESET_COMMAND="${RESET_COMMAND/__INIT_SQL__/$INIT_SQL}"
RESET_COMMAND="${RESET_COMMAND/__ROOT_PASSWORD__/$ROOT_PASSWORD}"
APPLESCRIPT_COMMAND="$(printf '%s' "$RESET_COMMAND" | sed 's/\\/\\\\/g; s/"/\\"/g')"

echo "Resetting local MySQL root access and provisioning ${TEST_DB_USER}@localhost for ${TEST_DB_NAME}."
echo "macOS will prompt for an administrator password."

osascript <<APPLESCRIPT
do shell script "${APPLESCRIPT_COMMAND}" with administrator privileges
APPLESCRIPT

echo "Local MySQL reset complete."
echo "Root password: ${ROOT_PASSWORD}"
echo "Test database: ${TEST_DB_NAME}"
echo "Test user: ${TEST_DB_USER}"
