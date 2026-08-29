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
# Serwer nie buduje JS — bez commita backend/public pull zostawia stary panel.
# emptyOutDir=false: NIE dodawaj całego assets (zostają stare hashe XAMPP).

Write-Host "==> git add backend/public (tylko pliki z aktualnego index.html)" -ForegroundColor Cyan
$htmlPath = Join-Path $Root "backend\public\index.html"
git add -- backend/public/index.html
$html = Get-Content -Raw $htmlPath
$assetNames = [regex]::Matches($html, 'assets/([^"''\s>]+)') | ForEach-Object { $_.Groups[1].Value } | Select-Object -Unique
foreach ($name in $assetNames) {
    $assetPath = Join-Path $Root "backend\public\assets\$name"
    if (Test-Path $assetPath) {
        git add -- $assetPath
    }
}
$trackedAssets = git ls-files -- backend/public/assets
foreach ($tracked in $trackedAssets) {
    $leaf = Split-Path $tracked -Leaf
    if ($assetNames -notcontains $leaf) {
        git rm -q --ignore-unmatch -- $tracked
    }
}
if ($Commit) {
    git add -- frontend/.env.production deploy
}
git status --short
$staged = git diff --cached --name-only
if (-not [string]::IsNullOrWhiteSpace($staged)) {
    $commitMsg = if ($Commit) { $Message } else { "Wgrywa aktualny build produkcyjny frontendu na serwer." }
    git commit -m $commitMsg
} elseif ($Commit) {
    Write-Host "Brak zmian do commita." -ForegroundColor DarkGray
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
