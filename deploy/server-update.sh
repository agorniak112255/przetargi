#!/usr/bin/env bash
# Uruchom na serwerze (najlepiej jako root).
# Aktualizuje kod z GitHub + migracje. NIE kopiuje lokalnej bazy.
# .htaccess na serwerze jest lokalny — pull go NIE nadpisuje.
#
#   bash deploy/server-update.sh              # sam kod (szybko)
#   bash deploy/server-update.sh --katalog    # + indeks sitemap sklepów (kilka–kilkanaście min)
#   bash deploy/server-update.sh --indeks     # + pełne przeliczenie search_blob produktów
#   bash deploy/server-update.sh /ścieżka
set -euo pipefail

usage() {
  cat <<'EOF'
Użycie: server-update.sh [--katalog|--indeks|--bez-katalogu] [katalog_aplikacji]

  (bez flagi)       pull, migracje, cache — bez pełnego skanu 20k produktów
  --indeks          products:rebuild-search-index (wolny pasek 0–100%)
  --katalog         dodatkowo catalog:index --missing-only (długo)
  --bez-katalogu    to samo co bez flagi (na wszelki wypadek)
  --help            ten tekst
EOF
}

APP_ROOT="/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl"
INDEX_CATALOG=0
INDEX_SEARCH=0

for arg in "$@"; do
  case "$arg" in
    --katalog|--catalog) INDEX_CATALOG=1 ;;
    --indeks|--search-index) INDEX_SEARCH=1 ;;
    --bez-katalogu|--skip-catalog) INDEX_CATALOG=0 ;;
    --help|-h) usage; exit 0 ;;
    /*) APP_ROOT="$arg" ;;
    *)
      echo "Nieznany argument: $arg" >&2
      usage >&2
      exit 1
      ;;
  esac
done

PHP_BIN="${PHP_BIN:-/opt/plesk/php/8.3/bin/php}"
OWNER="${OWNER:-supon}"
GROUP="${GROUP:-psacln}"
HTACCESS="$APP_ROOT/backend/public/.htaccess"
HTACCESS_BAK=""

cd "$APP_ROOT"

# Zachowaj serwerowy .htaccess (poza gitem) przed pull
if [[ -f "$HTACCESS" ]]; then
  HTACCESS_BAK="$(mktemp)"
  cp -a "$HTACCESS" "$HTACCESS_BAK"
  echo "==> zachowano lokalny .htaccess"
fi

# Lokalne poprawki po poprzednim deployu (sed /Przetargi/) nie mogą blokować pull
echo "==> reset lokalnych zmian w frontend build (poza .htaccess)"
git restore --worktree --staged -- backend/public/index.html backend/public/assets 2>/dev/null \
  || git checkout -- backend/public/index.html backend/public/assets 2>/dev/null \
  || true

echo "==> git pull"
git pull --ff-only origin main

# Przywróć / doinstaluj .htaccess (nigdy nie bierz wersji z XAMPP z repo)
if [[ -n "$HTACCESS_BAK" && -f "$HTACCESS_BAK" ]]; then
  cp -a "$HTACCESS_BAK" "$HTACCESS"
  rm -f "$HTACCESS_BAK"
  echo "==> przywrócono lokalny .htaccess"
elif [[ ! -f "$HTACCESS" && -f "$APP_ROOT/deploy/htaccess.production" ]]; then
  cp "$APP_ROOT/deploy/htaccess.production" "$HTACCESS"
  echo "==> skopiowano deploy/htaccess.production → backend/public/.htaccess"
fi

echo "==> uprawnienia"
chown -R "$OWNER:$GROUP" "$APP_ROOT" || true
chmod -R ug+rwx "$APP_ROOT/backend/storage" "$APP_ROOT/backend/bootstrap/cache" || true
if [[ -f "$HTACCESS" ]]; then
  chown "$OWNER:$GROUP" "$HTACCESS" || true
fi

if [[ -f "$APP_ROOT/backend/public/index.html" ]]; then
  # awaryjnie: stare buildy z /Przetargi/
  sed -i 's|/Przetargi/|/|g' "$APP_ROOT/backend/public/index.html" || true
  find "$APP_ROOT/backend/public/assets" -name 'index-*.js' -print0 2>/dev/null \
    | xargs -0 -r sed -i 's|/Przetargi||g' || true
fi

cd "$APP_ROOT/backend"

if [[ ! -d vendor ]]; then
  echo "==> composer install"
  if command -v composer >/dev/null 2>&1; then
    "$PHP_BIN" "$(command -v composer)" install --no-dev --optimize-autoloader
  elif [[ -f /usr/local/psa/var/modules/composer/composer.phar ]]; then
    "$PHP_BIN" /usr/local/psa/var/modules/composer/composer.phar install --no-dev --optimize-autoloader
  else
    echo "Brak composera — zainstaluj zależności w Plesku (PHP Composer)."
  fi
fi

echo "==> migrate"
"$PHP_BIN" artisan migrate --force

echo "==> cache"
"$PHP_BIN" artisan config:cache || true
"$PHP_BIN" artisan route:cache || true
"$PHP_BIN" artisan view:cache || true

# Pełny skan 20k kart trwa minutami nawet gdy nic się nie zmienia.
# Indeks był już liczony — odpalaj tylko po migracji kolumny albo --indeks.
if [[ "$INDEX_SEARCH" -eq 1 ]]; then
  echo "==> indeks wyszukiwania produktów"
  "$PHP_BIN" artisan products:rebuild-search-index || true
else
  echo "==> indeks wyszukiwania produktów pominięty (szybszy update)"
  echo "    gdy trzeba:  bash $APP_ROOT/deploy/server-update.sh --indeks"
fi

if [[ "$INDEX_CATALOG" -eq 1 ]]; then
  echo "==> indeks kart sklepów (tylko nowe domeny)"
  "$PHP_BIN" artisan catalog:index --missing-only --seconds=180 --max=20000 || true
else
  echo "==> indeks kart sklepów pominięty (szybszy update)"
  echo "    gdy trzeba:  bash $APP_ROOT/deploy/server-update.sh --katalog"
  echo "    albo jedna domena:  $PHP_BIN artisan catalog:index bpbhp.pl"
fi

echo "==> laravel scheduler (cron schedule:run)"
if [[ -x "$APP_ROOT/deploy/ensure-laravel-scheduler.sh" ]]; then
  bash "$APP_ROOT/deploy/ensure-laravel-scheduler.sh" "$APP_ROOT" || true
elif [[ -f "$APP_ROOT/deploy/ensure-laravel-scheduler.sh" ]]; then
  bash "$APP_ROOT/deploy/ensure-laravel-scheduler.sh" "$APP_ROOT" || true
else
  echo "UWAGA: brak deploy/ensure-laravel-scheduler.sh — cron schedule:run nie został ustawiony."
fi

echo "==> workery kolejki (enrichment)"
if [[ -f "$APP_ROOT/deploy/ensure-enrichment-workers.sh" ]]; then
  bash "$APP_ROOT/deploy/ensure-enrichment-workers.sh" "$APP_ROOT" || true
else
  echo "UWAGA: brak deploy/ensure-enrichment-workers.sh — enrichment nie ma kto przetwarzać."
fi

echo "==> gotowe: https://przetargi.supon.rzeszow.pl"
