#!/usr/bin/env bash
# Import dumpa SQL do bazy z backend/.env na serwerze.
# UWAGA: nadpisuje dane w bazie produkcyjnej.
# Użycie:
#   bash deploy/db-import-server.sh /tmp/przetargi_local.sql
set -euo pipefail

DUMP="${1:-}"
APP_ROOT="${APP_ROOT:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"

if [[ -z "$DUMP" || ! -f "$DUMP" ]]; then
  echo "Użycie: $0 /sciezka/do/dump.sql"
  exit 1
fi

ENV_FILE="$APP_ROOT/backend/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo "Brak $ENV_FILE"
  exit 1
fi

get_env() {
  local key="$1"
  grep -E "^${key}=" "$ENV_FILE" | head -n1 | cut -d= -f2- | tr -d '"' | tr -d "'"
}

DB_HOST="$(get_env DB_HOST)"
DB_PORT="$(get_env DB_PORT)"
DB_DATABASE="$(get_env DB_DATABASE)"
DB_USERNAME="$(get_env DB_USERNAME)"
DB_PASSWORD="$(get_env DB_PASSWORD)"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
  echo "Brak DB_DATABASE / DB_USERNAME w .env"
  exit 1
fi

echo "==> Import do bazy '$DB_DATABASE' (user: $DB_USERNAME)"
echo "    Plik: $DUMP"
confirm="${CONFIRM:-}"
if [[ -z "$confirm" ]]; then
  read -r -p "Na pewno nadpisac baze na SERWERZE? wpisz TAK: " confirm
fi
if [[ "$confirm" != "TAK" ]]; then
  echo "Anulowano. (ustaw CONFIRM=TAK aby pominac pytanie)"
  exit 1
fi

export MYSQL_PWD="$DB_PASSWORD"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" "$DB_DATABASE" < "$DUMP"
unset MYSQL_PWD

echo "==> cache clear"
cd "$APP_ROOT/backend"
"$PHP_BIN" artisan config:clear || true
"$PHP_BIN" artisan cache:clear || true

echo "==> gotowe"
