# Wysyla lokalne pliki storage (zdjecia/dokumenty produktow) na serwer.
param(
    [string]$SshHost = "supon.rzeszow.pl",
    [string]$SshUser = "root",
    [string]$RemoteRoot = "/var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$LocalPublic = Join-Path $Root "backend\storage\app\public"

if (-not (Test-Path $LocalPublic)) {
    throw "Brak katalogu $LocalPublic"
}

$dirs = @("products", "documents")
foreach ($dir in $dirs) {
    $local = Join-Path $LocalPublic $dir
    if (-not (Test-Path $local)) {
        Write-Host "Pomijam (brak lokalnie): $dir" -ForegroundColor Yellow
        continue
    }
    $remote = "${SshUser}@${SshHost}:${RemoteRoot}/backend/storage/app/public/"
    Write-Host "==> scp -r $local -> $remote" -ForegroundColor Cyan
    scp -r $local $remote
    if ($LASTEXITCODE -ne 0) { throw "scp $dir zakonczyl sie kodem $LASTEXITCODE" }
}

Write-Host "==> chown na serwerze" -ForegroundColor Cyan
ssh "${SshUser}@${SshHost}" "chown -R supon:psacln ${RemoteRoot}/backend/storage/app/public/products ${RemoteRoot}/backend/storage/app/public/documents 2>/dev/null; ls -la ${RemoteRoot}/backend/storage/app/public/"
if ($LASTEXITCODE -ne 0) { throw "ssh chown zakonczyl sie kodem $LASTEXITCODE" }

Write-Host "OK - storage wgrane na serwer." -ForegroundColor Green
