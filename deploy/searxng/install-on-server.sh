#!/usr/bin/env bash
# SearXNG dla enrichmentu produktów — kontener na 127.0.0.1:8088 (dostępny tylko
# dla PHP z tego samego hosta, nie z internetu). Uruchom na serwerze jako root:
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

SECRET="$(openssl rand -hex 32)"
mkdir -p "$CONF_DIR"
sed "s|zmien-mnie-na-losowy-ciag-znakow|$SECRET|g" "$SRC" >"$CONF_DIR/settings.yml"
chmod 644 "$CONF_DIR/settings.yml"

echo "==> obraz searxng/searxng"
docker pull searxng/searxng:latest

echo "==> (re)start kontenera $NAME na 127.0.0.1:$PORT"
docker rm -f "$NAME" >/dev/null 2>&1 || true
docker run -d --name "$NAME" --restart unless-stopped \
  -p "127.0.0.1:$PORT:8080" \
  -v "$CONF_DIR/settings.yml:/etc/searxng/settings.yml:ro" \
  -e "SEARXNG_BASE_URL=http://127.0.0.1:$PORT/" \
  -e "SEARXNG_SECRET=$SECRET" \
  -e UWSGI_WORKERS=8 \
  -e UWSGI_THREADS=4 \
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

echo "==> test na prawdziwym SKU (ROBFM) — z IP tego serwera"
BODY="$(curl -fsS --get "http://127.0.0.1:$PORT/search" \
  --data-urlencode 'q=ROBFM rękawice' \
  --data-urlencode 'format=json' \
  --data-urlencode 'language=pl')"

if command -v python3 >/dev/null 2>&1; then
  BODY="$BODY" python3 -c '
import json, os
d = json.loads(os.environ["BODY"])
res = d.get("results", [])
print("wynikow:", len(res))
for r in res[:6]:
    print(" -", r.get("url"))
eng = {}
for r in res:
    for e in (r.get("engines") or []):
        eng[e] = eng.get(e, 0) + 1
print("silniki z wynikami:", ", ".join(f"{k}={v}" for k, v in sorted(eng.items())) or "brak")
print("silniki zablokowane:", d.get("unresponsive_engines") or "brak")
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
Statystyki silników: docker exec $NAME true; curl -s http://127.0.0.1:$PORT/stats
MSG
