# Sciaga indeks katalogowy (catalog_pages) z serwera do lokalnego XAMPP.
# db-pull.ps1 celowo pomija catalog_pages i catalog_page_tokens, wiec po kazdym
# pull-u licznik catalog_hosts.pages_count pokazuje stan serwera, a lokalnie
# tych stron nie ma wcale — enrichment musi wtedy isc przez wyszukiwarki.
#
# Tokenow NIE przesylamy: catalog_page_tokens da sie odtworzyc lokalnie
# (catalog:tokens), a wazy 4x wiecej niz same strony. Dzieki temu nie ma tez
# problemu ze zgodnoscia ID miedzy tabelami.
#
# Scalanie, nie nadpisywanie: strony ida przez baze przejsciowa i wchodza
# INSERT IGNORE po unikalnym url_hash, wiec MySQL nadaje wlasne ID, a lokalnie
# zaindeksowane hosty (np. szortbhp.pl) zostaja nietkniete.
#
#   powershell -File deploy\catalog-pull.ps1
#   powershell -File deploy\catalog-pull.ps1 -Hosts "ansell.com,icd.pl"
#   powershell -File deploy\catalog-pull.ps1 -SkipTokens
#   powershell -File deploy\catalog-pull.ps1 -Force        # po przerwanym przebiegu
#
# Polaczenie zdalne: deploy\.env.db-remote (nie commituj).
# Polaczenie lokalne: backend\.env (musi byc 127.0.0.1 / localhost).
param(
    [string]$RemoteEnv = "",
    [string]$OutFile = "",
    [string]$Hosts = "",
    [switch]$SkipTokens,
    [switch]$Force
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

# Zwraca pojedyncza wartosc z lokalnego MySQL-a (bez naglowka kolumny).
function Get-LocalScalar([string]$sql) {
    if ($script:localPass -ne "") { $env:MYSQL_PWD = $script:localPass }
    try {
        $out = & $script:mysql @(
            "--host=$script:localHost",
            "--port=$script:localPort",
            "--user=$script:localUser",
            "--default-character-set=utf8mb4",
            "--batch",
            "--skip-column-names",
            "-e", $sql
        )
        if ($LASTEXITCODE -ne 0) { throw "zapytanie lokalne zakonczylo sie kodem $LASTEXITCODE" }
        return ($out | Select-Object -First 1)
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}

function Invoke-LocalSql([string]$sql) {
    if ($script:localPass -ne "") { $env:MYSQL_PWD = $script:localPass }
    try {
        & $script:mysql @(
            "--host=$script:localHost",
            "--port=$script:localPort",
            "--user=$script:localUser",
            "--default-character-set=utf8mb4",
            "-e", $sql
        )
        if ($LASTEXITCODE -ne 0) { throw "polecenie lokalne zakonczylo sie kodem $LASTEXITCODE" }
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
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
    throw "backend\.env wskazuje zdalny host '$localHost'. catalog-pull importuje tylko do 127.0.0.1/localhost."
}
if (Test-LocalMysqlHost $remoteHost) {
    throw "deploy\.env.db-remote wskazuje lokalny host. To ma byc baza na serwerze."
}

$stagingDb = "${localDb}_catalog_import"
if ($stagingDb -eq $localDb) {
    throw "Baza przejsciowa nie moze nazywac sie tak samo jak docelowa ($localDb)."
}

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
if (-not $OutFile) {
    $OutDir = Join-Path $Root "deploy\dumps"
    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    $OutFile = Join-Path $OutDir "catalog_pages_remote_$stamp.sql"
}

# Lista hostow -> warunek WHERE. Apostrofy w nazwie hosta sa niemozliwe
# (varchar z domena), ale podwajamy je na wszelki wypadek.
$hostList = @()
foreach ($h in ($Hosts -split ",")) {
    $h = $h.Trim().ToLowerInvariant()
    if ($h -ne "") { $hostList += $h }
}
$whereClause = ""
if ($hostList.Count -gt 0) {
    $quoted = ($hostList | ForEach-Object { "'" + $_.Replace("'", "''") + "'" }) -join ","
    $whereClause = "host IN ($quoted)"
}

$scope = if ($hostList.Count -gt 0) { "hosty: $($hostList -join ', ')" } else { "wszystkie hosty" }
Write-Host "==> 1/5 dump catalog_pages z serwera $remoteHost / $remoteDb ($scope)" -ForegroundColor Cyan
Write-Host "    catalog_page_tokens pomijamy - odtworzymy je lokalnie"
$env:MYSQL_PWD = $remotePass
try {
    $dumpArgs = @(
        "--host=$remoteHost",
        "--port=$remotePort",
        "--user=$remoteUser",
        "--single-transaction",
        "--no-create-db",
        "--skip-add-drop-table",
        "--default-character-set=utf8mb4",
        "--result-file=$OutFile"
    )
    if ($whereClause -ne "") {
        $dumpArgs += "--where=$whereClause"
    }
    $dumpArgs += $remoteDb
    $dumpArgs += "catalog_pages"
    & $mysqldump @dumpArgs
    if ($LASTEXITCODE -ne 0) {
        throw "mysqldump z serwera zakonczyl sie kodem $LASTEXITCODE"
    }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}

if (-not (Test-Path $OutFile) -or (Get-Item $OutFile).Length -lt 100) {
    throw "Dump wyglada na pusty / nieudany: $OutFile"
}
$sizeMb = [math]::Round((Get-Item $OutFile).Length / 1MB, 1)
Write-Host "    $OutFile ($sizeMb MB)"

Write-Host "==> 2/5 baza przejsciowa $stagingDb" -ForegroundColor Cyan
# Dwa rownolegle przebiegi kasowalyby sobie nawzajem staging w trakcie scalania,
# a blok finally jednego zdjalby tabele spod drugiego. Lepiej stanac od razu.
$stagingExists = Get-LocalScalar "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$stagingDb';"
if ([int]$stagingExists -gt 0) {
    if (-not $Force) {
        throw "Baza przejsciowa $stagingDb juz istnieje - albo trwa drugi przebieg catalog-pull, albo poprzedni przerwano. Poczekaj na jego koniec, a jesli nic nie dziala, powtorz z -Force."
    }
    Write-Host "    -Force: usuwam pozostalosc po przerwanym przebiegu" -ForegroundColor Yellow
    Invoke-LocalSql "DROP DATABASE IF EXISTS ``$stagingDb``;"
}
Invoke-LocalSql "CREATE DATABASE ``$stagingDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

try {
    Write-Host "==> 3/5 import dumpa do $stagingDb" -ForegroundColor Cyan
    if ($localPass -ne "") { $env:MYSQL_PWD = $localPass }
    try {
        $argLine = "--host=$localHost --port=$localPort --user=$localUser --default-character-set=utf8mb4 $stagingDb"
        $proc = Start-Process -FilePath $mysql -ArgumentList $argLine -RedirectStandardInput $OutFile -Wait -PassThru -NoNewWindow
        if ($proc.ExitCode -ne 0) {
            throw "import do bazy przejsciowej zakonczyl sie kodem $($proc.ExitCode)"
        }
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }

    $staged = Get-LocalScalar "SELECT COUNT(*) FROM ``$stagingDb``.catalog_pages;"
    $before = Get-LocalScalar "SELECT COUNT(*) FROM ``$localDb``.catalog_pages;"
    Write-Host "    w dumpie: $staged stron | lokalnie przed scaleniem: $before"

    Write-Host "==> 4/5 scalanie po url_hash (INSERT IGNORE - nic lokalnego nie ginie)" -ForegroundColor Cyan
    # Bez kolumny id: MySQL nadaje wlasne, wiec ID z serwera nie nadpisuja lokalnych.
    $mergeSql = @"
INSERT IGNORE INTO ``$localDb``.catalog_pages
    (host, manufacturer, url_hash, url, title, haystack, last_seen_at, created_at, updated_at)
SELECT host, manufacturer, url_hash, url, title, haystack, last_seen_at, created_at, updated_at
FROM ``$stagingDb``.catalog_pages;
"@
    Invoke-LocalSql $mergeSql

    $after = Get-LocalScalar "SELECT COUNT(*) FROM ``$localDb``.catalog_pages;"
    $added = [int]$after - [int]$before
    Write-Host "    doszlo $added stron (lacznie $after)"
} finally {
    Invoke-LocalSql "DROP DATABASE IF EXISTS ``$stagingDb``;"
}

if ($SkipTokens) {
    Write-Host "==> 5/5 tokeny pominiete (-SkipTokens)" -ForegroundColor Yellow
    Write-Host "    Bez tokenow nowe strony nie wyjda w wyszukiwaniu. Uruchom pozniej:"
    Write-Host "    cd backend; php artisan catalog:tokens"
} else {
    Write-Host "==> 5/5 tokeny dla nowych stron (catalog:tokens)" -ForegroundColor Cyan
    $php = "c:\xampp\php\php.exe"
    if (-not (Test-Path $php)) {
        Write-Host "    Nie znalazlem $php - uruchom recznie: cd backend; php artisan catalog:tokens" -ForegroundColor Yellow
    } else {
        Push-Location (Join-Path $Root "backend")
        try {
            & $php artisan catalog:tokens
            if ($LASTEXITCODE -ne 0) {
                throw "catalog:tokens zakonczyl sie kodem $LASTEXITCODE"
            }
        } finally {
            Pop-Location
        }
    }
}

Write-Host "OK - indeks katalogowy z serwera scalony lokalnie ($localDb)." -ForegroundColor Green
Write-Host "Uwaga: catalog_hosts.pages_count nadal pokazuje licznik z serwera - to licznik, nie stan lokalny."
Write-Host $OutFile
