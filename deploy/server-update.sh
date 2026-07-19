#!/usr/bin/env bash
# Uruchom na serwerze (najlepiej jako root), w katalogu projektu lub podaj ścieżkę.
# Aktualizuje kod z GitHub + migracje. NIE kopiuje lokalnej bazy.
# .htaccess na serwerze jest lokalny — pull go NIE nadpisuje.
set -euo pipefail

APP_ROOT="${1:-/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl}"
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

echo "==> gotowe: https://przetargi.supon.rzeszow.pl"
