[CmdletBinding()]
param(
    [string[]] $Path = @()
)

$ErrorActionPreference = 'Stop'
$testExitCode = 1
$dockerTestArguments = @(
    'exec',
    '-e', 'APP_ENV=testing',
    '-e', 'APP_DEBUG=false',
    '-e', 'DB_DATABASE=backend_rifas_app_test',
    '-e', 'CACHE_STORE=array',
    '-e', 'SESSION_DRIVER=array',
    '-e', 'QUEUE_CONNECTION=sync',
    'rifas_app'
)

try {
    Write-Host '[tests] Backend aislado en backend_rifas_app_test.'
    & docker @dockerTestArguments php artisan config:clear
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo limpiar el cache de configuración de testing.'
    }

    $testArguments = $dockerTestArguments + @('php', 'artisan', 'test') + $Path
    & docker @testArguments
    $testExitCode = $LASTEXITCODE
}
finally {
    Write-Host '[runtime] Restaurando el contenedor de desarrollo y su configuración optimizada.'
    docker compose up -d --force-recreate app
    if ($LASTEXITCODE -ne 0 -and $testExitCode -eq 0) {
        $testExitCode = $LASTEXITCODE
    }
}

exit $testExitCode
