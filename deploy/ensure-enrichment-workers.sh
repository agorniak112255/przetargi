#!/usr/bin/env bash
# Trzy pule workerów:
#   - enrich (domyślnie 16) — tylko vLLM / opisy
#   - prefetch (domyślnie tyle, ile publicznych IP, min. 5, maks. 16) — SearXNG + HTML
#   - embeddings (domyślnie 3) — wektory do Qdranta
# Kolejka embeddings ma własną, małą pulę: gdy chodziła razem z enrich, wszystkie 16 workerów
# pobierało z niej zadania w kółko (jedno zadanie to jeden krótki zapis), a `select ... for update`
# na tabeli `jobs` zakleszczał się z kasowaniem wykonanych wierszy — 22.09.2026 dało to 620 zadań
# w failed_jobs z MaxAttemptsExceededException. Tempo wektorów ogranicza API osadzeń, nie liczba workerów.
# Więcej wektorów naraz: EMBEDDING_WORKERS=6 bash deploy/ensure-enrichment-workers.sh
# Limit „Ile zapytań AI naraz” w panelu zajmuje sloty enrich, bez restartu.
# SearXNG rotuje zapytania po publicznych IP hosta (install-on-server.sh → source_ips),
# więc workery dostają liczbę adresów i szukają tyle razy szybciej — każdy adres
# nadal widzi jedno zapytanie co ENRICHMENT_SEARCH_MIN_INTERVAL.
# Więcej LLM: WORKERS=20 bash deploy/ensure-enrichment-workers.sh
# Mniej/więcej wyszukiwań (ryzyko 429): PREFETCH_WORKERS=3 bash deploy/ensure-enrichment-workers.sh
# Inna liczba adresów niż wykryta: SEARCH_LANES=4 bash deploy/ensure-enrichment-workers.sh
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
BACKEND="$APP_ROOT/backend"
ENRICH_UNIT="przetargi-enrichment@"
PREFETCH_UNIT="przetargi-prefetch@"
EMBEDDING_UNIT="przetargi-embeddings@"
LOG_FILE="/var/log/przetargi-enrichment.log"
SLOTS_MAX=32

if [[ ! -f "$BACKEND/artisan" ]]; then
  echo "ERR: brak $BACKEND/artisan" >&2
  exit 1
fi

# te same publiczne IPv4 co w deploy/searxng/install-on-server.sh (source_ips)
PUBLIC_IP_COUNT="$(ip -4 -o addr show scope global 2>/dev/null \
  | awk '{print $4}' | cut -d/ -f1 \
  | grep -Ev '^(10\.|127\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' \
  | sort -u | wc -l | tr -d ' ' || true)"
SEARCH_LANES="${SEARCH_LANES:-${PUBLIC_IP_COUNT:-1}}"
if [[ ! "$SEARCH_LANES" =~ ^[0-9]+$ ]] || (( SEARCH_LANES < 1 )); then
  SEARCH_LANES=1
fi
if (( SEARCH_LANES > 16 )); then
  SEARCH_LANES=16
fi

WORKERS="${WORKERS:-16}"
PREFETCH_WORKERS="${PREFETCH_WORKERS:-$(( SEARCH_LANES > 5 ? SEARCH_LANES : 5 ))}"
EMBEDDING_WORKERS="${EMBEDDING_WORKERS:-3}"
echo "==> workery: LLM ${WORKERS} (kolejka enrich) + wyszukiwanie ${PREFETCH_WORKERS} (kolejka prefetch)"
echo "    wektory ${EMBEDDING_WORKERS} (kolejka embeddings)"
echo "    adresy IP wyszukiwarki: ${SEARCH_LANES} (odstęp na adres bez zmian, razem ${SEARCH_LANES}× szybciej)"

if (( WORKERS < 1 || WORKERS > SLOTS_MAX )); then
  echo "ERR: WORKERS=$WORKERS poza zakresem 1-$SLOTS_MAX" >&2
  exit 1
fi
if (( PREFETCH_WORKERS < 1 || PREFETCH_WORKERS > 16 )); then
  echo "ERR: PREFETCH_WORKERS=$PREFETCH_WORKERS poza zakresem 1-16" >&2
  exit 1
fi
if (( EMBEDDING_WORKERS < 1 || EMBEDDING_WORKERS > 8 )); then
  echo "ERR: EMBEDDING_WORKERS=$EMBEDDING_WORKERS poza zakresem 1-8" >&2
  exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
  echo "UWAGA: brak systemd — workery trzeba uruchomić ręcznie:"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=enrich --tries=3 --timeout=420 --max-time=3600 &"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=prefetch,default --tries=3 --timeout=180 --max-time=3600 &"
  echo "  cd $BACKEND && $PHP_BIN artisan queue:work --queue=embeddings --sleep=3 --tries=3 --timeout=180 --max-time=3600 &"
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
  local pool="$5"
  local count="$6"
  local sleep_seconds="${7:-1}"
  cat > "$unit_file" <<EOF
[Unit]
Description=$description %i
After=network.target

[Service]
Type=simple
User=$OWNER
Group=$GROUP
WorkingDirectory=$BACKEND
Environment=QUEUE_WORKER_POOL=$pool
Environment=QUEUE_WORKER_INDEX=%i
Environment=QUEUE_WORKER_COUNT=$count
Environment=ENRICHMENT_SEARCH_LANES=$SEARCH_LANES
Environment=ENRICHMENT_PREFETCH_CONCURRENCY=$PREFETCH_WORKERS
ExecStart=$PHP_BIN artisan queue:work --queue=$queues --sleep=$sleep_seconds --tries=3 --timeout=$timeout --max-time=3600
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
  "enrich" \
  420 \
  enrich \
  "$WORKERS"
write_unit "/etc/systemd/system/${PREFETCH_UNIT}.service" \
  "Przetargi enrichment prefetch worker" \
  "prefetch,default" \
  180 \
  prefetch \
  "$PREFETCH_WORKERS"
# --sleep=3: pusta kolejka wektorów ma odpytywać tabelę `jobs` rzadko,
# a nie co sekundę razy liczba workerów
write_unit "/etc/systemd/system/${EMBEDDING_UNIT}.service" \
  "Przetargi embeddings worker" \
  "embeddings" \
  180 \
  embeddings \
  "$EMBEDDING_WORKERS" \
  3

touch "$LOG_FILE"
chown "$OWNER:$GROUP" "$LOG_FILE" || true

systemctl daemon-reload
systemctl reset-failed "${ENRICH_UNIT}"*.service 2>/dev/null || true
systemctl reset-failed "${PREFETCH_UNIT}"*.service 2>/dev/null || true
systemctl reset-failed "${EMBEDDING_UNIT}"*.service 2>/dev/null || true

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
enable_pool "$EMBEDDING_UNIT" "$EMBEDDING_WORKERS"
stop_surplus "$ENRICH_UNIT" "$WORKERS"
stop_surplus "$PREFETCH_UNIT" "$PREFETCH_WORKERS"
stop_surplus "$EMBEDDING_UNIT" "$EMBEDDING_WORKERS"

echo "==> workery: sygnał restartu dla zadań w toku"
cd "$BACKEND"
"$PHP_BIN" artisan queue:restart || true

systemctl --no-pager --plain list-units "${ENRICH_UNIT}*" "${PREFETCH_UNIT}*" "${EMBEDDING_UNIT}*" || true
echo "==> workery: OK (LLM $WORKERS + prefetch $PREFETCH_WORKERS + wektory $EMBEDDING_WORKERS, IP wyszukiwarki $SEARCH_LANES, log: $LOG_FILE)"
echo "    Panel AI zmienia tylko sloty modelu (do $WORKERS). Więcej LLM: WORKERS=N $0"
echo "    Więcej wyszukiwań (ostrożnie, 429): PREFETCH_WORKERS=N $0"
