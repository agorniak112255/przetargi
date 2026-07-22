#!/usr/bin/env bash
# Instaluje / weryfikuje cron Laravel: co minutę artisan schedule:run
# (m.in. activity-logs:prune codziennie o 02:15).
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
BACKEND="$APP_ROOT/backend"
MARKER="artisan schedule:run"
CRON_LINE="* * * * * cd \"$BACKEND\" && \"$PHP_BIN\" artisan schedule:run >> /dev/null 2>&1"

if [[ ! -f "$BACKEND/artisan" ]]; then
  echo "ERR: brak $BACKEND/artisan" >&2
  exit 1
fi

echo "==> scheduler: lista zadań Laravel"
"$PHP_BIN" "$BACKEND/artisan" schedule:list || true

install_for_user() {
  local user="$1"
  local existing
  existing="$(crontab -u "$user" -l 2>/dev/null || true)"
  if printf '%s\n' "$existing" | grep -Fq "$MARKER"; then
    echo "==> scheduler: cron już jest (user=$user)"
    printf '%s\n' "$existing" | grep -F "$MARKER" || true
    return 0
  fi

  {
    printf '%s\n' "$existing"
    printf '%s\n' "$CRON_LINE"
  } | grep -v '^$' | crontab -u "$user" -
  echo "==> scheduler: dodano cron (user=$user)"
  echo "    $CRON_LINE"
}

if [[ "$(id -u)" -eq 0 ]]; then
  install_for_user "$OWNER"
else
  existing="$(crontab -l 2>/dev/null || true)"
  if printf '%s\n' "$existing" | grep -Fq "$MARKER"; then
    echo "==> scheduler: cron już jest (bieżący użytkownik)"
    printf '%s\n' "$existing" | grep -F "$MARKER" || true
  else
    {
      printf '%s\n' "$existing"
      printf '%s\n' "$CRON_LINE"
    } | grep -v '^$' | crontab -
    echo "==> scheduler: dodano cron (bieżący użytkownik)"
    echo "    $CRON_LINE"
  fi
fi

echo "==> scheduler: jednorazowy test schedule:run"
cd "$BACKEND"
"$PHP_BIN" artisan schedule:run -v || true
echo "==> scheduler: OK"
