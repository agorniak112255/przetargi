#!/usr/bin/env bash
# Serwer wiadomości czatu (Laravel Reverb) jako usługa systemd `przetargi-reverb`.
#
# Działa tylko wtedy, gdy backend/.env ma BROADCAST_CONNECTION=reverb — bez tego czat chodzi na odpytywaniu
# (aplikacja co 10–30 s, dodatek Thunderbirda co 60 s) i usługa jest zbędna.
#
# Reverb słucha wyłącznie na 127.0.0.1:8080. Z zewnątrz dochodzi się do niego przez nginx w Plesku
# (deploy/nginx-reverb.conf → „Additional nginx directives” domeny przetargi), a aplikacja publikuje
# zdarzenia lokalnie (REVERB_HOST=127.0.0.1, REVERB_PORT=8080, REVERB_SCHEME=http).
#
# Długo żyjący proces PHP: restart co dobę (RuntimeMaxSec) i limit pamięci, a przy każdym wdrożeniu restart,
# żeby wczytał nowy kod i konfigurację.
#
#   bash deploy/ensure-reverb.sh [katalog_aplikacji]
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
BACKEND="$APP_ROOT/backend"
UNIT="przetargi-reverb"
UNIT_FILE="/etc/systemd/system/${UNIT}.service"
LOG_FILE="/var/log/przetargi-reverb.log"

if [[ ! -f "$BACKEND/artisan" ]]; then
  echo "ERR: brak $BACKEND/artisan" >&2
  exit 1
fi

# Ostatnia niezakomentowana wartość z .env (cudzysłowy zdjęte); brak klucza = pusty napis. `|| true` jest konieczne:
# grep bez trafienia kończy się kodem 1, a przy `set -euo pipefail` przypisanie HOST="$(env_value …)" przerywało
# skrypt bez słowa (03.10.2026 — usługa nie powstała, bo .env nie ma REVERB_SERVER_PORT).
env_value() {
  local key="$1"
  { grep -E "^${key}=" "$BACKEND/.env" 2>/dev/null || true; } | tail -n 1 | cut -d= -f2- | tr -d '"'"'"' \r'
}

# Adres i port z .env (te same, pod które aplikacja publikuje zdarzenia: REVERB_HOST/REVERB_PORT) — rozjazd
# z usługą znaczyłby, że każde zdarzenie przepada, choć przeglądarki są „połączone”.
HOST="$(env_value REVERB_SERVER_HOST)"; HOST="${HOST:-127.0.0.1}"
PORT="$(env_value REVERB_SERVER_PORT)"; PORT="${PORT:-8080}"
PUBLISH_PORT="$(env_value REVERB_PORT)"
if [[ -n "$PUBLISH_PORT" && "$PUBLISH_PORT" != "$PORT" ]]; then
  echo "UWAGA: REVERB_PORT=$PUBLISH_PORT (publikowanie) różni się od portu usługi $PORT — zdarzenia czatu nie dojdą." >&2
fi
if [[ "$PORT" != "8080" ]]; then
  echo "UWAGA: port $PORT — popraw też proxy_pass w dyrektywach nginx (deploy/nginx-reverb.conf ma 8080)." >&2
fi

if [[ "$(env_value BROADCAST_CONNECTION)" != "reverb" ]]; then
  echo "==> czat: BROADCAST_CONNECTION w .env to nie „reverb” — serwer wiadomości pominięty (czat na odpytywaniu)"
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$UNIT" 2>/dev/null; then
    echo "    UWAGA: usługa $UNIT nadal działa; zatrzymanie: systemctl disable --now $UNIT"
  fi
  exit 0
fi

for key in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
  if [[ -z "$(env_value "$key")" ]]; then
    echo "ERR: brak $key w $BACKEND/.env — serwer wiadomości nie wystartuje" >&2
    exit 1
  fi
done

if ! command -v systemctl >/dev/null 2>&1; then
  echo "UWAGA: brak systemd — uruchom ręcznie:"
  echo "  cd $BACKEND && $PHP_BIN artisan reverb:start --host=$HOST --port=$PORT &"
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "UWAGA: potrzebny root, żeby zarządzać usługą systemd — pomijam."
  exit 0
fi

echo "==> czat: usługa $UNIT (Reverb na $HOST:$PORT)"
cat > "$UNIT_FILE" <<EOF
[Unit]
Description=Przetargi — serwer wiadomości czatu (Laravel Reverb)
After=network.target

[Service]
Type=simple
User=$OWNER
Group=$GROUP
WorkingDirectory=$BACKEND
ExecStart=$PHP_BIN artisan reverb:start --host=$HOST --port=$PORT --no-interaction
Restart=always
RestartSec=3
RuntimeMaxSec=1d
MemoryMax=256M
LimitNOFILE=10000
TimeoutStopSec=15
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

[Install]
WantedBy=multi-user.target
EOF

touch "$LOG_FILE"
chown "$OWNER:$GROUP" "$LOG_FILE" || true

systemctl daemon-reload
systemctl enable "$UNIT" >/dev/null 2>&1 || true
# restart zamiast start: po wdrożeniu proces musi wczytać nowy kod i konfigurację
systemctl restart "$UNIT"
sleep 2
if systemctl is-active --quiet "$UNIT"; then
  echo "    działa (log: $LOG_FILE)"
else
  echo "    NIE wstała — sprawdź: systemctl status $UNIT; tail -n 50 $LOG_FILE" >&2
fi
