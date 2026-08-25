#!/usr/bin/env bash
# Instaluje / odświeża workery kolejki (systemd). Liczba równolegle
# przetwarzanych produktów = liczba workerów, wspólna dla wszystkich cenników.
# Zmiana liczby: WORKERS=12 bash deploy/ensure-enrichment-workers.sh
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
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

# Domyślnie tyle workerów, ile ustawiono w panelu („Ile zapytań AI naraz”).
if [[ -z "${WORKERS:-}" ]]; then
  WORKERS="$("$PHP_BIN" "$BACKEND/artisan" enrichment:concurrency 2>/dev/null | tr -cd '0-9')"
  echo "==> workery: limit z Ustawień AI = ${WORKERS:-brak odczytu}"
fi
WORKERS="${WORKERS:-8}"

if (( WORKERS < 1 || WORKERS > SLOTS_MAX )); then
  echo "ERR: WORKERS=$WORKERS poza zakresem 1-$SLOTS_MAX" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "UWAGA: brak systemd — workery trzeba uruchomić ręcznie, np. $WORKERS razy:"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=default,embeddings --tries=3 --timeout=420 --max-time=3600 &"
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
# kolejność kolejek = priorytet: opisy zawsze przed reindeksem embeddingów
ExecStart=$PHP_BIN artisan queue:work --queue=default,embeddings --sleep=2 --tries=3 --timeout=420 --max-time=3600
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
wanted=()
for ((i = 1; i <= WORKERS; i++)); do
  wanted+=("${UNIT_NAME}${i}.service")
done
systemctl enable --now "${wanted[@]}" >/dev/null 2>&1 \
  || echo "    część workerów nie wstała — sprawdź: systemctl status ${UNIT_NAME}1"

# Nadwyżkę wyłączamy tylko wtedy, gdy jednostka faktycznie istnieje. Wołanie
# systemctl po wszystkich 32 slotach kosztowało kilkanaście sekund na pusto.
surplus=()
while read -r unit; do
  [[ -z "$unit" ]] && continue
  idx="${unit#"$UNIT_NAME"}"
  idx="${idx%.service}"
  if [[ "$idx" =~ ^[0-9]+$ ]] && (( idx > WORKERS )); then
    surplus+=("$unit")
  fi
done < <(
  {
    systemctl list-units --all --plain --no-legend "${UNIT_NAME}*.service" 2>/dev/null | awk '{print $1}'
    systemctl list-unit-files --plain --no-legend "${UNIT_NAME}*.service" 2>/dev/null | awk '{print $1}'
  } | sort -u
)
if (( ${#surplus[@]} > 0 )); then
  echo "==> workery: wyłączam nadwyżkę (${#surplus[@]})"
  systemctl disable --now "${surplus[@]}" >/dev/null 2>&1 || true
fi

# Nowy kod wchodzi przez queue:restart — worker kończy bieżące zadanie i wychodzi,
# a Restart=always podnosi go z aktualnym kodem. „systemctl restart” blokowałby deploy
# do końca zadania enrichmentu (timeout 420 s), po kolei dla każdego workera.
echo "==> workery: sygnał restartu dla zadań w toku"
cd "$BACKEND"
"$PHP_BIN" artisan queue:restart || true

systemctl --no-pager --plain list-units "${UNIT_NAME}*" || true
echo "==> workery: OK ($WORKERS równolegle, log: $LOG_FILE)"
echo "    Obniżenie limitu w Ustawieniach AI działa od razu; podniesienie powyżej"
echo "    $WORKERS wymaga ponownego uruchomienia tego skryptu."
