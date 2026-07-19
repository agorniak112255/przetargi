# Dump lokalny + wyslanie SCP + import na serwerze przez SSH.
# Wymaga: ssh/scp (OpenSSH) oraz hasla/klucza do konta SSH.
param(
    [string]$SshHost = "supon.rzeszow.pl",
    [string]$SshUser = "root",
    [string]$RemoteDump = "/tmp/przetargi_from_local.sql"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

Write-Host "==> 1/3 export lokalny" -ForegroundColor Cyan
& "$PSScriptRoot\db-export-local.ps1"
$localDump = Get-ChildItem (Join-Path $Root "deploy\dumps\przetargi_local_*.sql") |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1 -ExpandProperty FullName
if (-not $localDump -or -not (Test-Path $localDump)) {
    throw "Nie udalo sie utworzyc dumpa."
}

$target = "${SshUser}@${SshHost}:${RemoteDump}"
Write-Host "==> 2/3 scp $localDump -> $target" -ForegroundColor Cyan
scp $localDump $target
if ($LASTEXITCODE -ne 0) { throw "scp zakonczyl sie kodem $LASTEXITCODE" }

# Skrypt importu moze jeszcze nie byc na serwerze po samym commitcie - wyslij tez lokalna kopie.
$remoteImport = "/tmp/db-import-server.sh"
$localImport = Join-Path $PSScriptRoot "db-import-server.sh"
Write-Host "==> 2b/3 scp import script -> ${SshUser}@${SshHost}:${remoteImport}" -ForegroundColor Cyan
scp $localImport "${SshUser}@${SshHost}:${remoteImport}"
if ($LASTEXITCODE -ne 0) { throw "scp skryptu importu zakonczyl sie kodem $LASTEXITCODE" }

Write-Host "==> 3/3 import na serwerze" -ForegroundColor Cyan
# CONFIRM=TAK pomija interaktywne pytanie (celowe nadpisanie na zadanie uzytkownika)
ssh "${SshUser}@${SshHost}" "CONFIRM=TAK bash $remoteImport $RemoteDump"
if ($LASTEXITCODE -ne 0) { throw "import na serwerze zakonczyl sie kodem $LASTEXITCODE" }

Write-Host "OK - baza lokalna wgrana na serwer." -ForegroundColor Green
Write-Host "Uwaga: zdjecia/dokumenty NIE sa w dumpie SQL. W razie braku obrazkow uruchom:" -ForegroundColor Yellow
Write-Host "  .\deploy\storage-push.ps1" -ForegroundColor Yellow
