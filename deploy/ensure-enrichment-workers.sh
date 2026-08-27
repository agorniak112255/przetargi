#!/usr/bin/env bash
# Dwie pule workerów:
#   - enrich (domyślnie 16) — tylko vLLM / opisy
#   - prefetch (domyślnie 3) — SearXNG + HTML
# Limit „Ile zapytań AI naraz” w panelu zajmuje sloty enrich, bez restartu.
# Więcej LLM: WORKERS=20 bash deploy/ensure-enrichment-workers.sh
# Więcej wyszukiwań (ryzyko 429): PREFETCH_WORKERS=5 bash deploy/ensure-enrichment-workers.sh
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
BACKEND="$APP_ROOT/backend"
ENRICH_UNIT="przetargi-enrichment@"
PREFETCH_UNIT="przetargi-prefetch@"
LOG_FILE="/var/log/przetargi-enrichment.log"
SLOTS_MAX=32

if [[ ! -f "$BACKEND/artisan" ]]; then
  echo "ERR: brak $BACKEND/artisan" >&2
  exit 1
fi

WORKERS="${WORKERS:-16}"
PREFETCH_WORKERS="${PREFETCH_WORKERS:-3}"
echo "==> workery: LLM ${WORKERS} (kolejka enrich) + wyszukiwanie ${PREFETCH_WORKERS} (kolejka prefetch)"

if (( WORKERS < 1 || WORKERS > SLOTS_MAX )); then
  echo "ERR: WORKERS=$WORKERS poza zakresem 1-$SLOTS_MAX" >&2
  exit 1
fi
if (( PREFETCH_WORKERS < 1 || PREFETCH_WORKERS > 8 )); then
  echo "ERR: PREFETCH_WORKERS=$PREFETCH_WORKERS poza zakresem 1-8" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "UWAGA: brak systemd — workery trzeba uruchomić ręcznie:"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=enrich,default,embeddings --tries=3 --timeout=420 --max-time=3600 &"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=prefetch --tries=3 --timeout=180 --max-time=3600 &"
  exit 0
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "UWAGA: potrzebny root, żeby zarządzać usługami systemd — pomijam."
  exit 0
fi

write_unit() {
  local unit_file="$1"
  local description="$2"
  local queues="$3"
  local timeout="$4"
  cat > "$unit_file" <<EOF
[Unit]
Description=$description %i
After=network.target

[Service]
Type=simple
User=$OWNER
Group=$GROUP
WorkingDirectory=$BACKEND
ExecStart=$PHP_BIN artisan queue:work --queue=$queues --sleep=1 --tries=3 --timeout=$timeout --max-time=3600
Restart=always
RestartSec=5
StandardOutput=append:$LOG_FILE
StandardError=append:$LOG_FILE

[Install]
WantedBy=multi-user.target
EOF
}

echo "==> workery: zapis jednostek systemd"
write_unit "/etc/systemd/system/${ENRICH_UNIT}.service" \
  "Przetargi enrichment LLM worker" \
  "enrich,default,embeddings" \
  420
write_unit "/etc/systemd/system/${PREFETCH_UNIT}.service" \
  "Przetargi enrichment prefetch worker" \
  "prefetch" \
  180

touch "$LOG_FILE"
chown "$OWNER:$GROUP" "$LOG_FILE" || true

systemctl daemon-reload
systemctl reset-failed "${ENRICH_UNIT}"*.service 2>/dev/null || true
systemctl reset-failed "${PREFETCH_UNIT}"*.service 2>/dev/null || true

enable_pool() {
  local prefix="$1"
  local count="$2"
  local wanted=()
  local i
  for ((i = 1; i <= count; i++)); do
    wanted+=("${prefix}${i}.service")
  done
  systemctl enable --now "${wanted[@]}" >/dev/null 2>&1 \
    || echo "    część ${prefix} nie wstała — sprawdź: systemctl status ${prefix}1"
}

stop_surplus() {
  local prefix="$1"
  local keep="$2"
  local surplus=()
  local unit idx
  while read -r unit; do
    [[ -z "$unit" ]] && continue
    idx="${unit#"$prefix"}"
    idx="${idx%.service}"
    if [[ "$idx" =~ ^[0-9]+$ ]] && (( idx > keep )); then
      surplus+=("$unit")
    fi
  done < <(
    {
      systemctl list-units --all --plain --no-legend "${prefix}*.service" 2>/dev/null | awk '{print $1}'
      systemctl list-unit-files --plain --no-legend "${prefix}*.service" 2>/dev/null | awk '{print $1}'
    } | sort -u
  )
  if (( ${#surplus[@]} > 0 )); then
    echo "==> workery: wyłączam nadwyżkę ${prefix} (${#surplus[@]})"
    systemctl disable --now "${surplus[@]}" >/dev/null 2>&1 || true
  fi
}

echo "==> workery: uruchamiam pule"
enable_pool "$ENRICH_UNIT" "$WORKERS"
enable_pool "$PREFETCH_UNIT" "$PREFETCH_WORKERS"
stop_surplus "$ENRICH_UNIT" "$WORKERS"
stop_surplus "$PREFETCH_UNIT" "$PREFETCH_WORKERS"

echo "==> workery: sygnał restartu dla zadań w toku"
cd "$BACKEND"
"$PHP_BIN" artisan queue:restart || true

systemctl --no-pager --plain list-units "${ENRICH_UNIT}*" "${PREFETCH_UNIT}*" || true
echo "==> workery: OK (LLM $WORKERS + prefetch $PREFETCH_WORKERS, log: $LOG_FILE)"
echo "    Panel AI zmienia tylko sloty modelu (do $WORKERS). Więcej LLM: WORKERS=N $0"
echo "    Więcej wyszukiwań (ostrożnie, 429): PREFETCH_WORKERS=N $0"
