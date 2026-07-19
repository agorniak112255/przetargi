# Start Qdrant lokalnie (binarka Windows — bez Dockera).
# Pierwsze pobranie: uruchom raz z -Download
param(
    [switch]$Download
)

$ErrorActionPreference = "Stop"
$root = Join-Path $PSScriptRoot "qdrant"
$exe = Join-Path $root "qdrant.exe"

if ($Download -or -not (Test-Path $exe)) {
    New-Item -ItemType Directory -Force -Path $root | Out-Null
    $zip = Join-Path $root "qdrant-windows.zip"
    $url = "https://github.com/qdrant/qdrant/releases/download/v1.18.3/qdrant-x86_64-pc-windows-msvc.zip"
    Write-Host "Pobieram Qdrant..."
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
    Expand-Archive -Path $zip -DestinationPath $root -Force
    Remove-Item $zip -Force
}

if (Get-Process -Name qdrant -ErrorAction SilentlyContinue) {
    Write-Host "Qdrant już działa."
} else {
    New-Item -ItemType Directory -Force -Path (Join-Path $root "storage") | Out-Null
    Start-Process -FilePath $exe -WorkingDirectory $root -WindowStyle Minimized
    Start-Sleep -Seconds 2
}

try {
    $r = Invoke-RestMethod -Uri "http://127.0.0.1:6333/collections" -TimeoutSec 5
    Write-Host "OK: http://127.0.0.1:6333  status=$($r.status)"
} catch {
    Write-Host "Nie udało się połączyć: $($_.Exception.Message)"
    exit 1
}
