#!/usr/bin/env bash
# Serwer rozmów głosowych i wideo (LiveKit) w Dockerze — kontener `przetargi-livekit`, sieć hosta.
#
# Działa tylko, gdy backend/.env ma LIVEKIT_URL, LIVEKIT_API_KEY i LIVEKIT_API_SECRET — bez nich rozmowy są
# wyłączone (czat działa dalej). Konfiguracja trafia do /etc/livekit/livekit.yaml (z sekretem, tylko root).
#
# Porty (zapora): TCP 7881 i UDP 7882-7892 otwarte na świat (obraz i dźwięk). Port 7880 (sygnalizacja) NIE —
# dochodzi się do niego przez nginx: https://rtc.supon.rzeszow.pl → 127.0.0.1:7880 (deploy/nginx-livekit.conf).
#
# Kontener jest odtwarzany tylko po zmianie konfiguracji albo wersji — zwykłe wdrożenie nie przerywa trwających
# rozmów.
#
#   bash deploy/ensure-livekit.sh [katalog_aplikacji]
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
BACKEND="$APP_ROOT/backend"
IMAGE="livekit/livekit-server:v1.13.7"
NAME="przetargi-livekit"
CONF_DIR="/etc/livekit"
CONF="$CONF_DIR/livekit.yaml"

# Ostatnia niezakomentowana wartość z .env (cudzysłowy zdjęte); brak klucza = pusty napis (grep bez trafienia
# przy pipefail przerwałby skrypt bez słowa — zob. ensure-reverb.sh).
env_value() {
  local key="$1"
  { grep -E "^${key}=" "$BACKEND/.env" 2>/dev/null || true; } | tail -n 1 | cut -d= -f2- | tr -d '"'"'"' \r'
}

URL="$(env_value LIVEKIT_URL)"
KEY="$(env_value LIVEKIT_API_KEY)"
SECRET="$(env_value LIVEKIT_API_SECRET)"
if [[ -z "$URL" || -z "$KEY" || -z "$SECRET" ]]; then
  echo "==> rozmowy: brak LIVEKIT_URL / LIVEKIT_API_KEY / LIVEKIT_API_SECRET w .env — serwer rozmów pominięty"
  exit 0
fi
if (( ${#SECRET} < 32 )); then
  echo "ERR: LIVEKIT_API_SECRET ma mniej niż 32 znaki — LiveKit go nie przyjmie" >&2
  exit 1
fi
# Klucz i sekret trafiają do YAML i do nagłówków JWT: tylko litery, cyfry i znaki base64 — inne (spacja, cudzysłów,
# &, *, {, …) rozjechałyby się między Laravelem a LiveKit albo zepsuły plik konfiguracji.
if [[ ! "$KEY" =~ ^[A-Za-z0-9_-]+$ || ! "$SECRET" =~ ^[A-Za-z0-9_+/=-]+$ ]]; then
  echo "ERR: LIVEKIT_API_KEY / LIVEKIT_API_SECRET mogą mieć tylko litery, cyfry i znaki - _ (sekret także + / =)" >&2
  exit 1
fi

MAX="$(env_value LIVEKIT_MAX_PARTICIPANTS)"; MAX="${MAX:-50}"
if [[ ! "$MAX" =~ ^[0-9]+$ ]] || (( MAX < 2 || MAX > 500 )); then
  echo "ERR: LIVEKIT_MAX_PARTICIPANTS=$MAX — oczekiwana liczba od 2 do 500" >&2
  exit 1
fi
APP_URL="$(env_value APP_URL)"
WEBHOOK="$(env_value LIVEKIT_WEBHOOK_URL)"; WEBHOOK="${WEBHOOK:-${APP_URL%/}/api/chat/livekit/webhook}"

# Publiczny adres, który LiveKit podaje przeglądarkom do obrazu i dźwięku — serwer ma kilka IP, a STUN mógłby wykryć
# inny. Musi to być adres łącza, którym serwer WYSYŁA (trasa domyślna, `ip route get 1.1.1.1` → src): 03.10.2026
# adres z DNS (91.246.70.120, ens40) dawał „could not establish pc connection”, bo odpowiedzi wychodziły innym
# łączem (ens41) z cudzym adresem nadawcy. Na produkcji LIVEKIT_NODE_IP=91.189.223.29; bez niego — adres z DNS.
NODE_IP="$(env_value LIVEKIT_NODE_IP)"
if [[ -z "$NODE_IP" ]]; then
  HOST="${URL#*://}"; HOST="${HOST%%/*}"; HOST="${HOST%%:*}"
  NODE_IP="$(getent ahostsv4 "$HOST" 2>/dev/null | awk 'NR==1 {print $1}' || true)"
fi
if [[ -z "$NODE_IP" ]]; then
  echo "ERR: nie znam publicznego IP rozmów — brak DNS dla adresu z LIVEKIT_URL i brak LIVEKIT_NODE_IP w .env" >&2
  exit 1
fi
if [[ ! "$NODE_IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  echo "ERR: LIVEKIT_NODE_IP=$NODE_IP to nie jest adres IPv4" >&2
  exit 1
fi
# Ostrzeżenie: adres obrazu i dźwięku na innym łączu niż trasa domyślna = odpowiedzi wracają inną drogą.
ROUTE_SRC="$(ip route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -n 1 || true)"
if [[ -n "$ROUTE_SRC" && "$ROUTE_SRC" != "$NODE_IP" ]]; then
  echo "UWAGA: LIVEKIT_NODE_IP=$NODE_IP, a serwer wysyła z $ROUTE_SRC (trasa domyślna) — rozmowy mogą się zrywać." >&2
  echo "       Ustaw w .env LIVEKIT_NODE_IP=$ROUTE_SRC i uruchom ten skrypt ponownie." >&2
fi
if [[ ! "$WEBHOOK" =~ ^https?://[^[:space:]\"]+$ ]]; then
  echo "ERR: adres webhooka „$WEBHOOK” jest niepoprawny — ustaw APP_URL albo LIVEKIT_WEBHOOK_URL w .env" >&2
  exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "ERR: brak polecenia docker" >&2
  exit 1
fi
if [[ "$(id -u)" -ne 0 ]]; then
  echo "UWAGA: potrzebny root (Docker i /etc/livekit) — pomijam."
  exit 0
fi

echo "==> rozmowy: konfiguracja LiveKit (IP $NODE_IP, najwyżej $MAX osób, webhook $WEBHOOK)"
mkdir -p "$CONF_DIR"
chmod 700 "$CONF_DIR"
NEW_CONF="$(mktemp)"
cat > "$NEW_CONF" <<EOF
# Generowane przez deploy/ensure-livekit.sh — zmiany wprowadzaj w backend/.env (LIVEKIT_*), nie tutaj.
port: 7880
rtc:
  tcp_port: 7881
  udp_port: 7882-7892
  use_external_ip: false
  node_ip: "$NODE_IP"
  # tylko ten adres — serwer ma kilka łączy, a obraz i dźwięk muszą wracać tą samą drogą, którą przyszły
  ips:
    includes:
      - "$NODE_IP/32"
keys:
  "$KEY": "$SECRET"
webhook:
  api_key: "$KEY"
  urls:
    - "$WEBHOOK"
room:
  # pokoje zakłada wyłącznie aplikacja (POST /chat/conversations/{id}/calls) — ważny token nie odtworzy
  # zakończonej rozmowy
  auto_create: false
  max_participants: $MAX
  empty_timeout: 60
  departure_timeout: 20
logging:
  level: info
EOF
chmod 600 "$NEW_CONF"

HASH="$( { cat "$NEW_CONF"; echo "$IMAGE"; } | sha256sum | cut -d' ' -f1)"
CURRENT="$(docker inspect -f '{{ index .Config.Labels "przetargi.config-hash" }}' "$NAME" 2>/dev/null || true)"
RUNNING="$(docker inspect -f '{{ .State.Running }}' "$NAME" 2>/dev/null || true)"

if [[ "$CURRENT" == "$HASH" && "$RUNNING" == "true" ]]; then
  rm -f "$NEW_CONF"
  echo "    bez zmian — działa (trwające rozmowy nie zostały przerwane)"
  exit 0
fi

mv "$NEW_CONF" "$CONF"
echo "    docker pull $IMAGE"
docker pull -q "$IMAGE" >/dev/null
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --restart unless-stopped --network host \
  --label "przetargi.config-hash=$HASH" \
  -v "$CONF:/etc/livekit.yaml:ro" \
  "$IMAGE" --config /etc/livekit.yaml >/dev/null

sleep 3
if [[ "$(docker inspect -f '{{ .State.Running }}' "$NAME" 2>/dev/null || true)" == "true" ]]; then
  echo "    działa (logi: docker logs --tail 50 $NAME)"
else
  echo "    NIE wstał — sprawdź: docker logs --tail 50 $NAME" >&2
  exit 1
fi
