[CmdletBinding()]
param(
    [int] $AppPort = 8000
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker no está disponible en PATH.'
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP no está disponible en PATH.'
}

$listener = Get-NetTCPConnection -LocalPort $AppPort -State Listen -ErrorAction SilentlyContinue
if ($listener) {
    throw "El puerto $AppPort ya está ocupado. Detén el servidor activo antes de continuar."
}

Write-Host '[runtime] Modo desarrollo rápido: Laravel host + PostgreSQL/Redis/Mailpit Docker.'
docker compose up -d --wait postgres redis mailpit
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudieron iniciar los servicios Docker.'
}

$postgresPort = (docker compose port postgres 5432).Split(':')[-1]
$redisPort = (docker compose port redis 6379).Split(':')[-1]
if (-not $postgresPort -or -not $redisPort) {
    throw 'No se pudieron resolver los puertos publicados de PostgreSQL o Redis.'
}

$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = $postgresPort
$env:REDIS_HOST = '127.0.0.1'
$env:REDIS_PORT = $redisPort

php artisan optimize:clear
if ($LASTEXITCODE -ne 0) {
    throw 'Laravel no pudo limpiar la configuración cacheada.'
}

Write-Host "[runtime] DB=127.0.0.1:$postgresPort Redis=127.0.0.1:$redisPort App=127.0.0.1:$AppPort"
php artisan serve --host=127.0.0.1 --port=$AppPort
exit $LASTEXITCODE
