#!/usr/bin/env bash
# Idempotent repository bootstrap for the SUPON AI / Przetargi project.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Backend: composer install"
cd "$ROOT/backend"
composer install --no-interaction --prefer-dist

if [ ! -f .env ]; then
  echo "==> Backend: creating .env (SQLite dev config)"
  cp .env.example .env
  sed -i 's/^DB_CONNECTION=mysql/DB_CONNECTION=sqlite/' .env
  sed -i 's/^DB_DATABASE=przetargi/# DB_DATABASE=przetargi/' .env
  sed -i 's/^DB_HOST=/# DB_HOST=/' .env
  sed -i 's/^DB_PORT=3306/# DB_PORT=3306/' .env
  sed -i 's/^DB_USERNAME=root/# DB_USERNAME=root/' .env
  sed -i 's/^DB_PASSWORD=/# DB_PASSWORD=/' .env
  sed -i 's#^SESSION_DRIVER=database#SESSION_DRIVER=file#' .env
  sed -i 's#^CACHE_STORE=database#CACHE_STORE=file#' .env
  sed -i 's#^QUEUE_CONNECTION=database#QUEUE_CONNECTION=sync#' .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "==> Backend: generating APP_KEY"
  php artisan key:generate --force
fi

touch database/database.sqlite

echo "==> Backend: running migrations"
php artisan migrate --force

if [ "$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tail -n1)" = "0" ]; then
  echo "==> Backend: seeding demo data"
  php artisan db:seed --force
fi

echo "==> Frontend: npm install"
cd "$ROOT/frontend"
npm install

echo "==> Install complete."
