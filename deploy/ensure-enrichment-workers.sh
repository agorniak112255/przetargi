#!/usr/bin/env bash
# Instaluje / odświeża workery kolejki (systemd). Liczba równolegle
# przetwarzanych produktów = liczba workerów, wspólna dla wszystkich cenników.
# Zmiana liczby: WORKERS=12 bash deploy/ensure-enrichment-workers.sh
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
WORKERS="${WORKERS:-8}"
BACKEND="$APP_ROOT/backend"
UNIT_NAME="przetargi-enrichment@"
UNIT_FILE="/etc/systemd/system/${UNIT_NAME}.service"
LOG_FILE="/var/log/przetargi-enrichment.log"
# Górna granica pętli wyłączającej nadwyżkę po zmniejszeniu WORKERS
SLOTS_MAX=32

if [[ ! -f "$BACKEND/artisan" ]]; then
  echo "ERR: brak $BACKEND/artisan" >&2
  exit 1
fi

if (( WORKERS < 1 || WORKERS > SLOTS_MAX )); then
  echo "ERR: WORKERS=$WORKERS poza zakresem 1-$SLOTS_MAX" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "UWAGA: brak systemd — workery trzeba uruchomić ręcznie, np. $WORKERS razy:"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --tries=3 --timeout=420 --max-time=3600 &"
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "UWAGA: potrzebny root, żeby zarządzać usługami systemd — pomijam."
  exit 0
fi

echo "==> workery: zapis $UNIT_FILE"
cat > "$UNIT_FILE" <<EOF
[Unit]
Description=Przetargi enrichment queue worker %i
After=network.target

[Service]
Type=simple
User=$OWNER
Group=$GROUP
WorkingDirectory=$BACKEND
# --max-time: worker kończy się co godzinę, systemd wstaje z nowym kodem i czystą pamięcią
ExecStart=$PHP_BIN artisan queue:work --queue=default --sleep=2 --tries=3 --timeout=420 --max-time=3600
Restart=always
RestartSec=5
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

[Install]
WantedBy=multi-user.target
EOF

touch "$LOG_FILE"
chown "$OWNER:$GROUP" "$LOG_FILE" || true

systemctl daemon-reload

echo "==> workery: uruchamiam $WORKERS instancji"
for ((i = 1; i <= SLOTS_MAX; i++)); do
  if (( i <= WORKERS )); then
    systemctl enable --now "${UNIT_NAME}${i}.service" >/dev/null 2>&1 \
      || echo "    nie udało się wystartować ${UNIT_NAME}${i}"
    systemctl restart "${UNIT_NAME}${i}.service" >/dev/null 2>&1 || true
  else
    systemctl disable --now "${UNIT_NAME}${i}.service" >/dev/null 2>&1 || true
  fi
done

echo "==> workery: sygnał restartu dla zadań w toku"
cd "$BACKEND"
"$PHP_BIN" artisan queue:restart || true

systemctl --no-pager --plain list-units "${UNIT_NAME}*" || true
echo "==> workery: OK ($WORKERS równolegle, log: $LOG_FILE)"
