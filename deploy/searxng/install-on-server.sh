#!/usr/bin/env bash
# SearXNG dla enrichmentu produktów — kontener słucha tylko na 127.0.0.1:8088,
# a zapytania do wyszukiwarek wychodzą losowo ze wszystkich publicznych IP hosta
# (dlatego --network host: w trybie bridge kontener nie widziałby tych adresów).
# Uruchom na serwerze jako root:
#   bash /var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl/deploy/searxng/install-on-server.sh
set -euo pipefail

APP_ROOT="${APP_ROOT:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
PORT="${PORT:-8088}"
NAME="${NAME:-przetargi-searxng}"
CONF_DIR="${CONF_DIR:-/etc/searxng-przetargi}"

if ! command -v docker >/dev/null 2>&1; then
  cat <<'MSG'
Brak polecenia docker.
Plesk: Rozszerzenia -> Docker (zainstaluj), albo na Debian/Ubuntu:
  curl -fsSL https://get.docker.com | sh
Potem uruchom ten skrypt ponownie.
MSG
  exit 1
fi

SRC="$APP_ROOT/deploy/searxng/settings.yml"
if [[ ! -f "$SRC" ]]; then
  echo "Brak $SRC — zrób najpierw git pull (server-update.sh)."
  exit 1
fi

echo "==> publiczne adresy IPv4 hosta (rotacja zapytań)"
PUBLIC_IPS="$(ip -4 -o addr show scope global 2>/dev/null \
  | awk '{print $4}' | cut -d/ -f1 \
  | grep -Ev '^(10\.|127\.|169\.254\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)' \
  | sort -u || true)"

if [[ -z "$PUBLIC_IPS" ]]; then
  echo "    nie wykryłem żadnego — SearXNG użyje domyślnego adresu wychodzącego"
else
  echo "$PUBLIC_IPS" | sed 's/^/    /'
fi

SECRET="$(openssl rand -hex 32)"
mkdir -p "$CONF_DIR"

# secret + lista source_ips w miejsce znacznika
sed "s|zmien-mnie-na-losowy-ciag-znakow|$SECRET|g" "$SRC" | while IFS= read -r line; do
  if [[ "$line" == *'# SOURCE_IPS_PLACEHOLDER'* ]]; then
    if [[ -n "$PUBLIC_IPS" ]]; then
      echo "  source_ips:"
      echo "$PUBLIC_IPS" | sed 's/^/    - /'
    fi
  else
    printf '%s\n' "$line"
  fi
done >"$CONF_DIR/settings.yml"
chmod 644 "$CONF_DIR/settings.yml"

echo "==> obraz searxng/searxng"
docker pull searxng/searxng:latest

echo "==> (re)start kontenera $NAME (nasłuch 127.0.0.1:$PORT, sieć hosta)"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --restart unless-stopped \
  --network host \
  -v "$CONF_DIR:/etc/searxng" \
  -e "GRANIAN_HOST=127.0.0.1" \
  -e "GRANIAN_PORT=$PORT" \
  -e "GRANIAN_WORKERS=4" \
  -e "SEARXNG_BASE_URL=http://127.0.0.1:$PORT/" \
  -e "SEARXNG_SECRET=$SECRET" \
  searxng/searxng:latest >/dev/null

echo "==> czekam na API json"
ready=0
for i in $(seq 1 40); do
  sleep 3
  if curl -fsS "http://127.0.0.1:$PORT/search?q=test&format=json" >/dev/null 2>&1; then
    ready=1
    break
  fi
done
if [[ "$ready" -ne 1 ]]; then
  echo "SearXNG nie odpowiada na format=json. Logi:"
  docker logs --tail 40 "$NAME"
  exit 1
fi
echo "OK: http://127.0.0.1:$PORT"

echo "==> kontrola: port nie może być widoczny z internetu"
if command -v ss >/dev/null 2>&1; then
  LISTEN="$(ss -ltn "sport = :$PORT" 2>/dev/null | tail -n +2 || true)"
  echo "$LISTEN" | sed 's/^/    /'
  if echo "$LISTEN" | grep -qE '(0\.0\.0\.0|\[::\]):'"$PORT"; then
    echo "    !!! UWAGA: nasłuch na wszystkich interfejsach — zablokuj port $PORT w firewallu."
  fi
fi

echo "==> test na prawdziwym SKU (ROBFM)"
BODY="$(curl -fsS --get "http://127.0.0.1:$PORT/search" \
  --data-urlencode 'q=ROBFM rękawice' \
  --data-urlencode 'format=json' \
  --data-urlencode 'language=pl')"

if command -v python3 >/dev/null 2>&1; then
  BODY="$BODY" python3 -c '
import json, os
d = json.loads(os.environ["BODY"])
res = d.get("results", [])
good = [r for r in res if "robfm" in ((r.get("url") or "") + (r.get("title") or "")).lower()]
print("wynikow:", len(res), "| z SKU w URL/tytule:", len(good))
for r in good[:6]:
    print(" -", r.get("url"))
eng = {}
for r in res:
    for e in (r.get("engines") or []):
        eng[e] = eng.get(e, 0) + 1
print("silniki:", ", ".join(f"{k}={v}" for k, v in sorted(eng.items())) or "brak")
print("zablokowane:", d.get("unresponsive_engines") or "brak")
'
else
  printf '%s' "$BODY" | head -c 800
  echo
fi

cat <<MSG

Gotowe. W aplikacji: Ustawienia AI -> Szukanie w internecie = SearXNG,
adres instancji: http://127.0.0.1:$PORT

Config: $CONF_DIR/settings.yml (po edycji: docker restart $NAME)
Logi:   docker logs -f $NAME
MSG
