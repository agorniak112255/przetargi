#!/usr/bin/env bash
# Uruchom na serwerze (najlepiej jako root).
# Aktualizuje kod z GitHub, zależności wg composer.lock + migracje. NIE kopiuje lokalnej bazy.
# .htaccess na serwerze jest lokalny — pull go NIE nadpisuje.
#
#   bash deploy/server-update.sh              # sam kod (szybko)
#   bash deploy/server-update.sh --katalog    # + indeks sitemap sklepów (kilka–kilkanaście min)
#   bash deploy/server-update.sh --indeks     # + pełne przeliczenie search_blob produktów
#   bash deploy/server-update.sh --tokeny     # + przeliczenie tokenów indeksu (po zmianie reguł)
#   bash deploy/server-update.sh /ścieżka
set -euo pipefail

usage() {
  cat <<'EOF'
Użycie: server-update.sh [--katalog|--indeks|--tokeny|--bez-katalogu] [katalog_aplikacji]

  (bez flagi)       pull, migracje, cache — bez pełnego skanu 20k produktów
  --indeks          products:rebuild-search-index (wolny pasek 0–100%)
  --katalog         dodatkowo catalog:index --missing-only (długo)
  --tokeny          catalog:tokens --refresh — przelicza tokeny wyszukiwania
                    istniejących stron po zmianie reguł tokenizacji (długo)
  --bez-katalogu    to samo co bez flagi (na wszelki wypadek)
  --help            ten tekst
EOF
}

APP_ROOT="/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl"
INDEX_CATALOG=0
INDEX_SEARCH=0
REFRESH_TOKENS=0

for arg in "$@"; do
  case "$arg" in
    --katalog|--catalog) INDEX_CATALOG=1 ;;
    --indeks|--search-index) INDEX_SEARCH=1 ;;
    --tokeny|--tokens) REFRESH_TOKENS=1 ;;
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

# Skrypt zwykle idzie z konta root, więc migracje, cache i testowy schedule:run zostawiają w storage pliki
# roota — cron i kolejki (jako $OWNER) przestałyby działać. Trap: właściciel wraca także wtedy, gdy skrypt
# przerwie się w połowie (set -e) albo ktoś go zatrzyma.
fix_owner() {
  chown -R "$OWNER:$GROUP" "$APP_ROOT/backend/storage" "$APP_ROOT/backend/bootstrap/cache" 2>/dev/null || true
}
trap fix_owner EXIT

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

# Zawsze, nie tylko gdy brak vendor: dotąd serwer został przy paczkach z 19.07.2026 mimo nowego composer.lock
# (guzzlehttp/promises 2.5.1 zrywał Http::pool z limitem równoległości — „Invoking the wait callback did not
# resolve the promise”, 27.09.2026 — i nie doszły poprawki bezpieczeństwa z 12.09). Przy zgodnym vendor
# composer kończy po kilku sekundach bez zmian. Przed chown, żeby nowe pliki vendor dostały właściciela aplikacji.
# Tylko plik PHP (phar): `composer` w PATH to u Pleska skrypt Bash, który puszcza phar systemowym PHP 7.4, a podany
# do "$PHP_BIN" zostaje tylko wypisany z kodem 0 — composer nie ruszał, vendor został stary (27.09.2026).
is_php_file() {
  local first=""
  [[ -f "$1" ]] || return 1
  IFS= read -r first < "$1" || true
  [[ "$first" == "<?php"* || "$first" == "#!"*php* ]]
}
echo "==> composer install (vendor zgodny z composer.lock)"
cd "$APP_ROOT/backend"
COMPOSER_BIN=""
for candidate in /usr/local/psa/var/modules/composer/composer.phar /usr/lib/plesk-9.0/composer.phar "$(command -v composer 2>/dev/null || true)"; do
  if [[ -n "$candidate" ]] && is_php_file "$candidate"; then
    COMPOSER_BIN="$candidate"
    break
  fi
done
if [[ -z "$COMPOSER_BIN" ]]; then
  echo "Brak composer.phar — vendor zostałby niezgodny z composer.lock. Zainstaluj composera albo zależności w Plesku (PHP Composer) i uruchom skrypt ponownie." >&2
  exit 1
fi
echo "    $PHP_BIN $COMPOSER_BIN"
COMPOSER_ALLOW_SUPERUSER=1 "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
cd "$APP_ROOT"

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

echo "==> storage:link"
if [[ -L "$APP_ROOT/backend/public/storage" ]]; then
  echo "    już istnieje"
else
  "$PHP_BIN" artisan storage:link || true
fi

echo "==> migrate"
"$PHP_BIN" artisan migrate --force

# Marża przetargów od ceny standardowej (dla użytkowników bez podglądu cen specjalnych B2B): migracja kopiuje ją
# tam, gdzie karta nie ma ceny specjalnej; resztę liczy to polecenie. Liczy tylko braki — kolejne wdrożenia nic
# nie zmieniają.
echo "==> marże przetargów od ceny standardowej"
"$PHP_BIN" artisan tenders:backfill-standard-margins --apply || true

# Uprawnienia dopisane w kodzie trzeba dosypać do bazy, inaczej nowej opcji nie
# ma nikt — także admin. Seedera rol tu NIE wołamy: robi syncPermissions, czyli
# skasowałby ręczne zmiany z panelu „Role”. To polecenie tylko dodaje nowe.
echo "==> uprawnienia"
"$PHP_BIN" artisan permissions:sync --apply || true

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
  echo "    Aby zaindeksować sklepy (nowe domeny z retailer_domains), wykonaj:"
  echo "      bash $APP_ROOT/deploy/server-update.sh --katalog"
  echo "    albo tylko indeks:"
  echo "      cd $APP_ROOT/backend && $PHP_BIN artisan catalog:index --missing-only --seconds=180 --max=20000"
  echo "    jedna domena:  $PHP_BIN artisan catalog:index bhpstar.pl"
fi

if [[ "$REFRESH_TOKENS" -eq 1 ]]; then
  echo "==> przeliczenie tokenów indeksu (kod z końcówki adresu przed tytułem)"
  "$PHP_BIN" artisan catalog:tokens --refresh || true
else
  echo "==> tokeny indeksu bez zmian (po zmianie reguł tokenizacji uruchom raz):"
  echo "      bash $APP_ROOT/deploy/server-update.sh --tokeny"
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

# Czat: serwer wiadomości (tylko przy BROADCAST_CONNECTION=reverb) — restart po każdym wdrożeniu,
# bo długo żyjący proces trzyma stary kod i konfigurację.
echo "==> czat: serwer wiadomości (Reverb)"
if [[ -f "$APP_ROOT/deploy/ensure-reverb.sh" ]]; then
  bash "$APP_ROOT/deploy/ensure-reverb.sh" "$APP_ROOT" || true
fi

# Rozmowy głosowe i wideo: serwer LiveKit (tylko przy LIVEKIT_* w .env); kontener odtwarzany tylko po zmianie
# konfiguracji lub wersji — wdrożenie nie przerywa trwających rozmów.
echo "==> rozmowy: serwer rozmów (LiveKit)"
if [[ -f "$APP_ROOT/deploy/ensure-livekit.sh" ]]; then
  bash "$APP_ROOT/deploy/ensure-livekit.sh" "$APP_ROOT" || true
fi

# migrate / cache / testowy schedule:run szły jako root — pliki root-owned w storage blokowały cron
# i aplikację działające jako $OWNER (to samo robi trap na wyjściu)
echo "==> uprawnienia storage po komendach artisan"
fix_owner

echo "==> gotowe: https://przetargi.supon.rzeszow.pl"
if [[ "$INDEX_CATALOG" -eq 0 ]]; then
  echo "==> Aby zaindeksować sklepy, wykonaj:"
  echo "    bash $APP_ROOT/deploy/server-update.sh --katalog"
fi
