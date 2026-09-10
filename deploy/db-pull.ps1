# Dump bazy na serwerze + import do lokalnego XAMPP.
# Polaczenie zdalne: deploy/.env.db-remote (nie commituj).
# Polaczenie lokalne: backend/.env (musi byc 127.0.0.1 / localhost).
param(
    [string]$RemoteEnv = "",
    [string]$OutFile = ""
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Get-DotEnvValue([string]$path, [string]$key) {
    $line = Get-Content $path | Where-Object { $_ -match "^\s*$key\s*=" } | Select-Object -First 1
    if (-not $line) { return "" }
    return ($line -split "=", 2)[1].Trim().Trim('"').Trim("'")
}

function Test-LocalMysqlHost([string]$dbHost) {
    $h = $dbHost.Trim().ToLowerInvariant()
    return ($h -eq "127.0.0.1" -or $h -eq "localhost" -or $h -eq "::1")
}

$LocalEnv = Join-Path $Root "backend\.env"
if (-not $RemoteEnv) {
    $RemoteEnv = Join-Path $PSScriptRoot ".env.db-remote"
}

if (-not (Test-Path $LocalEnv)) {
    throw "Brak pliku $LocalEnv"
}
if (-not (Test-Path $RemoteEnv)) {
    throw "Brak $RemoteEnv. Skopiuj deploy\db-remote.env.example i uzupelnij dane serwera."
}

$mysqldump = "c:\xampp\mysql\bin\mysqldump.exe"
$mysql = "c:\xampp\mysql\bin\mysql.exe"
if (-not (Test-Path $mysqldump) -or -not (Test-Path $mysql)) {
    throw "Nie znaleziono mysql/mysqldump w c:\xampp\mysql\bin. Uruchom XAMPP MySQL."
}

$remoteHost = Get-DotEnvValue $RemoteEnv "DB_HOST"
$remotePort = Get-DotEnvValue $RemoteEnv "DB_PORT"
$remoteDb = Get-DotEnvValue $RemoteEnv "DB_DATABASE"
$remoteUser = Get-DotEnvValue $RemoteEnv "DB_USERNAME"
$remotePass = Get-DotEnvValue $RemoteEnv "DB_PASSWORD"
if (-not $remotePort) { $remotePort = "3306" }

$localHost = Get-DotEnvValue $LocalEnv "DB_HOST"
$localPort = Get-DotEnvValue $LocalEnv "DB_PORT"
$localDb = Get-DotEnvValue $LocalEnv "DB_DATABASE"
$localUser = Get-DotEnvValue $LocalEnv "DB_USERNAME"
$localPass = Get-DotEnvValue $LocalEnv "DB_PASSWORD"
if (-not $localHost) { $localHost = "127.0.0.1" }
if (-not $localPort) { $localPort = "3306" }

if (-not $remoteHost -or -not $remoteDb -or -not $remoteUser) {
    throw "W $RemoteEnv brakuje DB_HOST / DB_DATABASE / DB_USERNAME."
}
if (-not $localDb -or -not $localUser) {
    throw "W $LocalEnv brakuje DB_DATABASE / DB_USERNAME."
}
if (-not (Test-LocalMysqlHost $localHost)) {
    throw "backend\.env wskazuje zdalny host '$localHost'. db-pull importuje tylko do 127.0.0.1/localhost."
}
if (Test-LocalMysqlHost $remoteHost) {
    throw "deploy\.env.db-remote wskazuje lokalny host. To ma byc baza na serwerze."
}

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
if (-not $OutFile) {
    $OutDir = Join-Path $Root "deploy\dumps"
    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    $OutFile = Join-Path $OutDir "przetargi_remote_$stamp.sql"
}

Write-Host "==> 1/3 dump serwer $remoteHost / $remoteDb" -ForegroundColor Cyan
$env:MYSQL_PWD = $remotePass
try {
    & $mysqldump @(
        "--host=$remoteHost",
        "--port=$remotePort",
        "--user=$remoteUser",
        "--single-transaction",
        "--routines",
        "--triggers",
        "--default-character-set=utf8mb4",
        "--result-file=$OutFile",
        $remoteDb
    )
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump z serwera zakonczyl sie kodem $LASTEXITCODE"
    }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

if (-not (Test-Path $OutFile) -or (Get-Item $OutFile).Length -lt 100) {
    throw "Dump wyglada na pusty / nieudany: $OutFile"
}
$sizeKb = [math]::Round((Get-Item $OutFile).Length / 1KB, 1)
Write-Host "    $OutFile ($sizeKb KB)"

Write-Host "==> 2/3 CREATE DATABASE IF NOT EXISTS $localDb" -ForegroundColor Cyan
$createSql = "CREATE DATABASE IF NOT EXISTS ``$localDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($localPass -ne "") { $env:MYSQL_PWD = $localPass }
try {
    & $mysql @(
        "--host=$localHost",
        "--port=$localPort",
        "--user=$localUser",
        "--default-character-set=utf8mb4",
        "-e", $createSql
    )
    if ($LASTEXITCODE -ne 0) {
        throw "CREATE DATABASE zakonczyl sie kodem $LASTEXITCODE"
    }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

Write-Host "==> 3/3 import do lokalnej $localHost / $localDb" -ForegroundColor Cyan
if ($localPass -ne "") { $env:MYSQL_PWD = $localPass }
try {
    $argLine = "--host=$localHost --port=$localPort --user=$localUser --default-character-set=utf8mb4 $localDb"
    $proc = Start-Process -FilePath $mysql -ArgumentList $argLine -RedirectStandardInput $OutFile -Wait -PassThru -NoNewWindow
    if ($proc.ExitCode -ne 0) {
        throw "import lokalny zakonczyl sie kodem $($proc.ExitCode)"
    }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

Write-Host "OK - baza z serwera wgrana lokalnie ($localDb)." -ForegroundColor Green
Write-Host $OutFile
