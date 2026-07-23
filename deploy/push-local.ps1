# Lokalnie (Windows): build produkcyjny frontendu + push na GitHub,
# potem przywrocenie buildu XAMPP (VITE_BASE=/Przetargi/), zeby lokalnie nie bylo bialej strony.
# Bazy NIE wysyla. Commit rob wczesniej albo uzyj -Commit.
param(
    [switch]$Commit,
    [string]$Message = "Aktualizacja aplikacji",
    [switch]$SkipLocalRestore
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

Write-Host "==> build frontend (produkcja VITE_BASE=/)" -ForegroundColor Cyan
Set-Location "$Root\frontend"
npm run build:prod

Set-Location $Root

# .htaccess nie jest w git - lokalny XAMPP / serwer trzymaja wlasne kopie (deploy/htaccess.*)

if ($Commit) {
    Write-Host "==> git add + commit" -ForegroundColor Cyan
    git add backend/public frontend/.env.production deploy
    git status --short
    git commit -m $Message
}

Write-Host "==> git push" -ForegroundColor Cyan
git push origin main

if (-not $SkipLocalRestore) {
    Write-Host "==> przywracanie lokalnego frontendu (build:xampp, VITE_BASE=/Przetargi/)" -ForegroundColor Cyan
    Set-Location "$Root\frontend"
    npm run build:xampp
    Set-Location $Root

    $htaccessSrc = Join-Path $Root "deploy\htaccess.xampp"
    $htaccessDst = Join-Path $Root "backend\public\.htaccess"
    if (Test-Path $htaccessSrc) {
        Copy-Item -Force $htaccessSrc $htaccessDst
        Write-Host "    skopiowano deploy/htaccess.xampp -> backend/public/.htaccess" -ForegroundColor DarkGray
    }

    Write-Host ""
    Write-Host "Lokalnie: odswiez przegladarke (Ctrl+F5) na http://localhost/Przetargi/" -ForegroundColor Yellow
    Write-Host "Working tree moze pokazywac zmiany w backend/public (build xampp) - NIE commituj ich na produkcje." -ForegroundColor DarkGray
}

Write-Host ""
Write-Host "Na serwerze uruchom:" -ForegroundColor Green
Write-Host "  bash /var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl/deploy/server-update.sh"
Write-Host ""
