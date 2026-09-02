# Tunel SSH: lokalny 3307 -> MySQL na serwerze (127.0.0.1:3306).
# Lokalny .env: DB_HOST=127.0.0.1  DB_PORT=3307  DB_DATABASE=supon_przetargi
param(
    [string]$SshHost = "supon.rzeszow.pl",
    [string]$SshUser = "root",
    [int]$LocalPort = 3307
)

$ErrorActionPreference = "Stop"
Write-Host "Tunel 127.0.0.1:${LocalPort} -> ${SshHost}:3306  (Ctrl+C zamyka)" -ForegroundColor Cyan
ssh -N -o ExitOnForwardFailure=yes -L "${LocalPort}:127.0.0.1:3306" "${SshUser}@${SshHost}"
