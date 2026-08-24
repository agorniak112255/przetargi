#!/usr/bin/env bash
# Sprawdza po kolei każdy silnik SearXNG z IP tego serwera i mówi, który
# naprawdę zna produkt (SKU w URL/tytule), a który zwraca śmieci albo captcha.
#   bash deploy/searxng/probe-engines-server.sh [SKU] [fraza]
set -euo pipefail

PORT="${PORT:-8088}"
SKU="${1:-ROBFM}"
PHRASE="${2:-rękawice}"

ENGINES="google
bing
google cse
duckduckgo
qwant
brave
mojeek
yahoo
startpage
presearch
marginalia
stract
wiby
crowdview
seekr
right dao
yep
naver"

if ! command -v python3 >/dev/null 2>&1; then
  echo "Potrzebny python3 do oceny wyników."
  exit 1
fi

echo "zapytanie: $SKU $PHRASE"
printf '%-14s %-7s %s\n' 'SILNIK' 'TRAFNE' 'PRZYKŁAD / PROBLEM'

while IFS= read -r engine; do
  [[ -z "$engine" ]] && continue
  body="$(curl -fsS --max-time 40 --get "http://127.0.0.1:$PORT/search" \
    --data-urlencode "q=$SKU $PHRASE" \
    --data-urlencode 'format=json' \
    --data-urlencode 'language=pl' \
    --data-urlencode "engines=$engine" 2>/dev/null || true)"

  if [[ -z "$body" ]]; then
    printf '%-14s %-7s %s\n' "$engine" '-' 'brak odpowiedzi z SearXNG'
    continue
  fi

  BODY="$body" SKU="$SKU" ENGINE="$engine" python3 -c '
import json, os
sku = os.environ["SKU"].lower()
engine = os.environ["ENGINE"]
try:
    d = json.loads(os.environ["BODY"])
except Exception:
    print("%-14s %-7s %s" % (engine, "-", "odpowiedź nie jest JSON-em"))
    raise SystemExit
res = d.get("results", [])
hits = [r for r in res
        if sku in (r.get("url") or "").lower() or sku in (r.get("title") or "").lower()]
dead = d.get("unresponsive_engines") or []
if dead:
    why = "; ".join(str(x[1]) for x in dead if len(x) > 1) or "zablokowany"
    print("%-14s %-7s %s" % (engine, "0", "BLOKADA: " + why))
elif hits:
    print("%-14s %-7s %s" % (engine, "%d/%d" % (len(hits), len(res)), hits[0].get("url", "")))
elif res:
    print("%-14s %-7s %s" % (engine, "0/%d" % len(res), "śmieci, np. " + (res[0].get("url") or "")))
else:
    print("%-14s %-7s %s" % (engine, "0", "zero wyników"))
'
  sleep 2
done <<<"$ENGINES"

cat <<'MSG'

Kolumna TRAFNE = ile z wyników ma SKU w URL/tytule.
Silniki z wynikiem > 0 wpisz mi w odpowiedzi — zostawię w settings.yml tylko je.
MSG
