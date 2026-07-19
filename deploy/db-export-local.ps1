# Eksport lokalnej bazy MySQL (XAMPP) do pliku SQL.
# Nie wysyla na serwer - tylko tworzy dump.
param(
    [string]$OutFile = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$EnvFile = Join-Path $Root "backend\.env"

function Get-DotEnvValue([string]$path, [string]$key) {
    $line = Get-Content $path | Where-Object { $_ -match "^\s*$key\s*=" } | Select-Object -First 1
    if (-not $line) { return "" }
    return ($line -split "=", 2)[1].Trim().Trim('"').Trim("'")
}

if (-not (Test-Path $EnvFile)) {
    throw "Brak pliku $EnvFile"
}

$db = Get-DotEnvValue $EnvFile "DB_DATABASE"
$user = Get-DotEnvValue $EnvFile "DB_USERNAME"
$pass = Get-DotEnvValue $EnvFile "DB_PASSWORD"
$dbHost = Get-DotEnvValue $EnvFile "DB_HOST"
if (-not $dbHost) { $dbHost = "127.0.0.1" }
$port = Get-DotEnvValue $EnvFile "DB_PORT"
if (-not $port) { $port = "3306" }

$mysqldump = "c:\xampp\mysql\bin\mysqldump.exe"
if (-not (Test-Path $mysqldump)) {
    throw "Nie znaleziono $mysqldump - uruchom XAMPP MySQL."
}

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
if (-not $OutFile) {
    $OutDir = Join-Path $Root "deploy\dumps"
    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    $OutFile = Join-Path $OutDir "przetargi_local_$stamp.sql"
}

Write-Host "==> dump bazy '$db' -> $OutFile" -ForegroundColor Cyan

$dumpArgs = @(
    "-h$dbHost",
    "-P$port",
    "-u$user",
    "--single-transaction",
    "--routines",
    "--triggers",
    "--default-character-set=utf8mb4",
    "--result-file=$OutFile",
    $db
)
if ($pass -ne "") {
    $dumpArgs = @("-p$pass") + $dumpArgs
}

& $mysqldump @dumpArgs
if ($LASTEXITCODE -ne 0) {
    throw "mysqldump zakonczyl sie kodem $LASTEXITCODE"
}

if (-not (Test-Path $OutFile) -or (Get-Item $OutFile).Length -lt 100) {
    throw "Dump wyglada na pusty / nieudany: $OutFile"
}

Write-Host "OK: $OutFile ($([math]::Round((Get-Item $OutFile).Length/1KB,1)) KB)" -ForegroundColor Green
Write-Host ""
Write-Host "Na serwer:" -ForegroundColor Yellow
Write-Host "  1) wgraj plik (SCP/FTP/Plesk) do np. /tmp/przetargi.sql"
Write-Host "  2) bash deploy/db-import-server.sh /tmp/przetargi.sql"
Write-Host ""
Write-Host $OutFile
