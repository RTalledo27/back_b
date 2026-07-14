[CmdletBinding()]
param(
    [string[]] $Path = @(),
    [string] $Token,
    [string] $DatabaseName,
    [switch] $KeepDatabase,
    [switch] $ValidateOnly,
    [switch] $Compact,
    [ValidateSet('test', 'migration')]
    [string] $Mode = 'test',
    [string] $MigrationPath
)

$ErrorActionPreference = 'Stop'
$databasePrefix = 'backend_rifas_app_test_'
$databaseCreated = $false
$testExitCode = 1
$containerNames = [System.Collections.Generic.List[string]]::new()
$commandSequence = 0
$databaseNameWasProvided = $PSBoundParameters.ContainsKey('DatabaseName')

function Get-DotEnvValue {
    param([Parameter(Mandatory)] [string] $Name)

    if (-not (Test-Path -LiteralPath '.env')) {
        return $null
    }

    $line = Get-Content -LiteralPath '.env' | Where-Object {
        $_ -match ('^\s*' + [regex]::Escape($Name) + '\s*=')
    } | Select-Object -First 1

    if ($null -eq $line) {
        return $null
    }

    return (($line -split '=', 2)[1]).Trim().Trim('"').Trim("'")
}

function Resolve-TestDatabaseName {
    if ($script:databaseNameWasProvided) {
        if ([string]::IsNullOrWhiteSpace($DatabaseName)) {
            throw 'DatabaseName cannot be empty.'
        }

        return $DatabaseName.Trim().ToLowerInvariant()
    }

    $effectiveToken = $Token
    if ([string]::IsNullOrWhiteSpace($effectiveToken)) {
        $effectiveToken = $env:TEST_TOKEN
    }
    if ([string]::IsNullOrWhiteSpace($effectiveToken)) {
        $effectiveToken = [Guid]::NewGuid().ToString('N').Substring(0, 16)
    }

    if ($effectiveToken -notmatch '^[A-Za-z0-9_]{1,32}$') {
        throw 'Token must contain only letters, numbers, or underscores and be at most 32 characters.'
    }

    return $databasePrefix + $effectiveToken.ToLowerInvariant()
}

function Assert-SafeTestDatabaseName {
    param([Parameter(Mandatory)] [string] $Candidate)

    $principalDatabase = Get-DotEnvValue -Name 'DB_DATABASE'
    $protectedNames = @(
        'backend_rifas_app',
        'backend_rifas_app_test',
        'defaultdb',
        'postgres'
    )

    if (-not [string]::IsNullOrWhiteSpace($principalDatabase)) {
        $protectedNames += $principalDatabase.Trim().ToLowerInvariant()
    }

    if ([string]::IsNullOrWhiteSpace($Candidate)) {
        throw 'The test database name cannot be empty.'
    }
    if ($protectedNames -contains $Candidate.ToLowerInvariant()) {
        throw "Refusing protected or shared database '$Candidate'."
    }
    if ($Candidate -notmatch '^backend_rifas_app_test_[a-z0-9_]{1,32}$') {
        throw "Unsafe test database name '$Candidate'."
    }
    if ($Candidate.Length -gt 63) {
        throw 'The test database name exceeds PostgreSQL identifier limits.'
    }
}

function Invoke-Docker {
    param([Parameter(Mandatory)] [string[]] $Arguments)

    & docker @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker command failed with exit code $LASTEXITCODE."
    }
}

function Invoke-IsolatedAppCommand {
    param([Parameter(Mandatory)] [string[]] $Command)

    $script:commandSequence++
    $containerName = "rifas_test_${safeToken}_$PID`_$script:commandSequence"
    $script:containerNames.Add($containerName)

    $arguments = @(
        'compose', 'run', '--rm', '--no-deps',
        '--name', $containerName,
        '-e', 'APP_ENV=testing',
        '-e', 'APP_DEBUG=false',
        '-e', 'APP_CONFIG_CACHE=/tmp/b21-config.php',
        '-e', 'DB_CONNECTION=pgsql',
        '-e', 'DB_HOST=postgres',
        '-e', 'DB_PORT=5432',
        '-e', "DB_DATABASE=$safeDatabaseName",
        '-e', 'DB_URL=',
        '-e', 'CACHE_STORE=array',
        '-e', 'SESSION_DRIVER=array',
        '-e', 'QUEUE_CONNECTION=sync',
        '-e', 'MAIL_MAILER=array',
        'app'
    ) + $Command

    & docker @arguments
    $script:lastAppExitCode = $LASTEXITCODE
}

try {
    $safeDatabaseName = Resolve-TestDatabaseName
    Assert-SafeTestDatabaseName -Candidate $safeDatabaseName
    $safeToken = $safeDatabaseName.Substring($databasePrefix.Length)

    Write-Host "[guard] Safe isolated database: $safeDatabaseName"

    if ($ValidateOnly) {
        Write-Host '[guard] Validation completed before any Docker command.'
        $testExitCode = 0
        exit 0
    }

    $postgresHealth = & docker inspect --format '{{.State.Health.Status}}' rifas_postgres 2>$null
    if ($LASTEXITCODE -ne 0 -or $postgresHealth -ne 'healthy') {
        throw 'rifas_postgres must be running and healthy before isolated tests.'
    }

    $databaseUser = $env:DB_USERNAME
    if ([string]::IsNullOrWhiteSpace($databaseUser)) {
        $databaseUser = Get-DotEnvValue -Name 'DB_USERNAME'
    }
    if ([string]::IsNullOrWhiteSpace($databaseUser)) {
        $databaseUser = 'rifas'
    }

    Write-Host "[database] Creating isolated database $safeDatabaseName."
    Invoke-Docker -Arguments @(
        'exec', 'rifas_postgres', 'createdb',
        '--username', $databaseUser,
        '--owner', $databaseUser,
        $safeDatabaseName
    )
    $databaseCreated = $true

    $actualDatabase = & docker exec rifas_postgres psql --username $databaseUser --dbname $safeDatabaseName -tAc 'SELECT current_database();'
    if ($LASTEXITCODE -ne 0 -or $actualDatabase.Trim() -ne $safeDatabaseName) {
        throw 'The isolated PostgreSQL database could not be verified.'
    }

    if ($Mode -eq 'migration') {
        Invoke-IsolatedAppCommand -Command @('php', 'artisan', 'migrate:fresh', '--force')
        $testExitCode = $lastAppExitCode
        if ($testExitCode -ne 0) {
            throw 'migrate:fresh failed in the isolated database.'
        }

        $rollback = @('php', 'artisan', 'migrate:rollback', '--force')
        $migrate = @('php', 'artisan', 'migrate', '--force')
        if (-not [string]::IsNullOrWhiteSpace($MigrationPath)) {
            $rollback += "--path=$MigrationPath"
            $migrate += "--path=$MigrationPath"
        }

        Invoke-IsolatedAppCommand -Command $rollback
        $testExitCode = $lastAppExitCode
        if ($testExitCode -ne 0) {
            throw 'migrate:rollback failed in the isolated database.'
        }

        Invoke-IsolatedAppCommand -Command $migrate
        $testExitCode = $lastAppExitCode
        if ($testExitCode -ne 0) {
            throw 'migrate failed in the isolated database.'
        }

        Invoke-IsolatedAppCommand -Command @('php', 'artisan', 'migrate:status')
        $testExitCode = $lastAppExitCode
    } else {
        $testCommand = @('php', 'artisan', 'test')
        if ($Compact) {
            $testCommand += '--compact'
        }

        Invoke-IsolatedAppCommand -Command ($testCommand + $Path)
        $testExitCode = $lastAppExitCode
    }
}
catch {
    Write-Host "[error] $($_.Exception.Message)" -ForegroundColor Red
    $testExitCode = 1
}
finally {
    foreach ($containerName in $containerNames) {
        $existingContainer = & docker ps --all --quiet --filter "name=^/$containerName$" 2>$null
        if (-not [string]::IsNullOrWhiteSpace($existingContainer)) {
            & docker rm --force $containerName *> $null
        }
    }

    if ($databaseCreated -and -not $KeepDatabase) {
        Assert-SafeTestDatabaseName -Candidate $safeDatabaseName
        Write-Host "[database] Dropping isolated database $safeDatabaseName."
        & docker exec rifas_postgres dropdb --username $databaseUser --if-exists --force $safeDatabaseName
        if ($LASTEXITCODE -ne 0 -and $testExitCode -eq 0) {
            $testExitCode = $LASTEXITCODE
        }
    } elseif ($databaseCreated) {
        Write-Host "[database] Preserved for diagnostics: $safeDatabaseName"
    }
}

exit $testExitCode
