# Lokalnie (Windows): build produkcyjny frontendu + push na GitHub.
# Bazy NIE wysyła. Commit rób wcześniej albo odkomentuj sekcję commit.
param(
    [switch]$Commit,
    [string]$Message = "Aktualizacja aplikacji"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

Write-Host "==> build frontend (produkcja VITE_BASE=/)" -ForegroundColor Cyan
Set-Location "$Root\frontend"
npm run build:prod

Set-Location $Root

# .htaccess nie jest w git — lokalny XAMPP / serwer trzymają własne kopie (deploy/htaccess.*)

if ($Commit) {
    Write-Host "==> git add + commit" -ForegroundColor Cyan
    git add backend/public frontend/.env.production deploy
    git status --short
    git commit -m $Message
}

Write-Host "==> git push" -ForegroundColor Cyan
git push origin main

Write-Host ""
Write-Host "Na serwerze uruchom:" -ForegroundColor Green
Write-Host "  bash /var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl/deploy/server-update.sh"
Write-Host ""
