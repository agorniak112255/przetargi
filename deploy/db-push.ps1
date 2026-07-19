# Dump lokalny + wysłanie SCP + import na serwerze przez SSH.
# Wymaga: ssh/scp (OpenSSH) oraz hasła/klucza do konta SSH.
param(
    [string]$SshHost = "supon.rzeszow.pl",
    [string]$SshUser = "root",
    [string]$RemoteDump = "/tmp/przetargi_from_local.sql"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

Write-Host "==> 1/3 export lokalny" -ForegroundColor Cyan
$dumpLine = & "$PSScriptRoot\db-export-local.ps1" | Select-Object -Last 1
$localDump = "$dumpLine".Trim()
if (-not (Test-Path $localDump)) {
    throw "Nie udało się utworzyć dumpa."
}

$target = "${SshUser}@${SshHost}:${RemoteDump}"
Write-Host "==> 2/3 scp $localDump -> $target" -ForegroundColor Cyan
scp $localDump $target

Write-Host "==> 3/3 import na serwerze (wpisz TAK gdy zapyta)" -ForegroundColor Cyan
ssh "${SshUser}@${SshHost}" "bash /var/www/vhosts/supon.rzeszow.pl/przetargi.supon.rzeszow.pl/deploy/db-import-server.sh $RemoteDump"

Write-Host "OK — baza lokalna wgrana na serwer." -ForegroundColor Green
