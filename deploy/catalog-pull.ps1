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
# -Mirror: zamiast scalac, robi wierna kopie obu tabel z serwera razem z ID,
# wiec tokeny przychodza gotowe i nie trzeba ich liczyc godzinami. Strumieniuje
# prosto mysqldump -> mysql, bez pliku posredniego, bo kilku GB dumpa moze nie
# byc gdzie zapisac. Lokalne tabele katalogowe sa najpierw czyszczone: przy
# innodb_file_per_table=ON zwalnia to miejsce jeszcze przed importem.
# Uwaga: -Mirror kasuje hosty zaindeksowane tylko lokalnie.
#
#   powershell -File deploy\catalog-pull.ps1
#   powershell -File deploy\catalog-pull.ps1 -Hosts "ansell.com,icd.pl"
#   powershell -File deploy\catalog-pull.ps1 -SkipTokens
#   powershell -File deploy\catalog-pull.ps1 -Force        # po przerwanym przebiegu
#   powershell -File deploy\catalog-pull.ps1 -Mirror       # wierna kopia z serwera
#
# Polaczenie zdalne: deploy\.env.db-remote (nie commituj).
# Polaczenie lokalne: backend\.env (musi byc 127.0.0.1 / localhost).
param(
    [string]$RemoteEnv = "",
    [string]$OutFile = "",
    [string]$Hosts = "",
    [switch]$SkipTokens,
    [switch]$Force,
    [switch]$Mirror
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

# Zwraca pojedyncza wartosc z bazy wskazanej plikiem opcji (uzywane dla serwera,
# zeby nie przepuszczac hasla przez wiersz polecenia).
function Get-CnfScalar([string]$cnf, [string]$db, [string]$sql) {
    $out = & $script:mysql @(
        "--defaults-file=$cnf",
        "--batch",
        "--skip-column-names",
        $db,
        "-e", $sql
    )
    if ($LASTEXITCODE -ne 0) { throw "zapytanie zdalne zakonczylo sie kodem $LASTEXITCODE" }
    return ($out | Select-Object -First 1)
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

if ($Mirror) {
    if ($Hosts -ne "") {
        throw "-Mirror kopiuje cala tabele; -Hosts da sie uzyc tylko w trybie scalania."
    }

    # Haslo w wierszu polecenia widac w liscie procesow, a MYSQL_PWD obsluzy tylko
    # jedna strone potoku - stad dwa tymczasowe pliki opcji, kasowane w finally.
    $remoteCnf = [System.IO.Path]::GetTempFileName()
    $localCnf = [System.IO.Path]::GetTempFileName()
    try {
        Set-Content -Path $remoteCnf -Encoding ascii -Value @(
            "[client]", "host=$remoteHost", "port=$remotePort",
            "user=$remoteUser", "password=$remotePass", "default-character-set=utf8mb4"
        )
        Set-Content -Path $localCnf -Encoding ascii -Value @(
            "[client]", "host=$localHost", "port=$localPort",
            "user=$localUser", "password=$localPass", "default-character-set=utf8mb4"
        )

        Write-Host "==> 1/3 czyszcze lokalne tabele katalogowe (zwalnia miejsce przed importem)" -ForegroundColor Cyan
        $before = Get-LocalScalar "SELECT COUNT(*) FROM ``$localDb``.catalog_pages;"
        Write-Host "    lokalnie przed: $before stron (zostana zastapione stanem serwera)"
        Invoke-LocalSql @"
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE ``$localDb``.catalog_page_tokens;
TRUNCATE TABLE ``$localDb``.catalog_pages;
SET FOREIGN_KEY_CHECKS=1;
"@

        Write-Host "==> 2/3 strumien mysqldump -> mysql (bez pliku posredniego)" -ForegroundColor Cyan
        Write-Host "    catalog_pages + catalog_page_tokens z ID serwera - tokenow nie liczymy"
        # cmd daje prawdziwy potok bajtowy; potok PowerShella przepuszcza tekst
        # liniami i przy kilku GB potrafi zdlawic transfer albo przekrecic kodowanie.
        # --compress: dump SQL to krotkie ciagi i liczby, wiec kompresja protokolu
        # scina transfer kilkukrotnie. Bez niej 3,4 GB szlo po ~1 MB/s godzinami.
        $pipe = '"{0}" --defaults-file="{1}" --compress --single-transaction --no-create-info --skip-add-locks --disable-keys --default-character-set=utf8mb4 {2} catalog_pages catalog_page_tokens | "{3}" --defaults-file="{4}" {5}' -f `
            $mysqldump, $remoteCnf, $remoteDb, $mysql, $localCnf, $localDb
        & cmd.exe /c $pipe
        if ($LASTEXITCODE -ne 0) {
            throw "strumien dump->import zakonczyl sie kodem $LASTEXITCODE. Lokalne tabele katalogowe sa puste - powtorz -Mirror."
        }

        Write-Host "==> 3/3 kontrola (porownanie z serwerem, nie z kopia)" -ForegroundColor Cyan
        # Porownujemy ZAWSZE z serwerem. Sama lokalna liczba nic nie mowi o tym,
        # czy import sie urwal, a osierocone tokeny potrafia byc dziedziczone po
        # serwerze - wtedy nie sa objawem urwania.
        $countsSql = "SELECT (SELECT COUNT(*) FROM catalog_pages), (SELECT COUNT(*) FROM catalog_page_tokens), (SELECT COUNT(*) FROM catalog_page_tokens t LEFT JOIN catalog_pages p ON p.id = t.catalog_page_id WHERE p.id IS NULL);"
        $localRow = (Get-CnfScalar $localCnf $localDb $countsSql) -split "`t"
        $remoteRow = (Get-CnfScalar $remoteCnf $remoteDb $countsSql) -split "`t"
        Write-Host "    strony:  lokalnie $($localRow[0]) | serwer $($remoteRow[0])"
        Write-Host "    tokeny:  lokalnie $($localRow[1]) | serwer $($remoteRow[1])"
        Write-Host "    tokeny bez strony: lokalnie $($localRow[2]) | serwer $($remoteRow[2])"
        if ($localRow[0] -ne $remoteRow[0] -or $localRow[1] -ne $remoteRow[1]) {
            throw "import niepelny - lokalne liczby nie zgadzaja sie z serwerem. Powtorz -Mirror."
        }
        if ($localRow[2] -ne $remoteRow[2]) {
            Write-Host "    UWAGA: inna liczba osieroconych tokenow niz na serwerze - sprawdz import." -ForegroundColor Yellow
        } elseif ([int]$localRow[2] -gt 0) {
            Write-Host "    (osierocone tokeny sa takze na serwerze - to stan zrodla, nie urwany import)"
        }
    } finally {
        Remove-Item $remoteCnf, $localCnf -Force -ErrorAction SilentlyContinue
    }

    Write-Host "OK - lokalny indeks jest wierna kopia serwera ($localDb), potwierdzona licznikami z obu baz." -ForegroundColor Green
    return
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
        "--compress",
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
