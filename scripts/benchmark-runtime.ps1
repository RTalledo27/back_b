[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $BaseUrl,
    [Parameter(Mandatory)]
    [string] $GameId,
    [ValidateSet('all', 'public-games', 'auth-me', 'me-entries', 'admin-games', 'motor-parallel')]
    [string] $CaseName = 'all',
    [ValidateRange(2, 20)]
    [int] $Iterations = 4
)

$ErrorActionPreference = 'Stop'
$requiredVariables = @(
    'RUNTIME_BENCHMARK_ADMIN_EMAIL',
    'RUNTIME_BENCHMARK_ADMIN_PASSWORD',
    'RUNTIME_BENCHMARK_PLAYER_EMAIL',
    'RUNTIME_BENCHMARK_PLAYER_PASSWORD'
)

foreach ($name in $requiredVariables) {
    if (-not [Environment]::GetEnvironmentVariable($name, 'Process')) {
        throw "Falta la variable de proceso $name."
    }
}

Add-Type -AssemblyName System.Net.Http
$BaseUrl = $BaseUrl.TrimEnd('/')

function Get-AccessToken([string] $Email, [string] $Password) {
    $body = @{ email = $Email; password = $Password } | ConvertTo-Json
    $response = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/v1/auth/login" `
        -ContentType 'application/json' -Headers @{ Accept = 'application/json' } -Body $body
    return [string] $response.data.access_token
}

function New-ApiClient([string] $Token) {
    $client = [System.Net.Http.HttpClient]::new()
    $client.Timeout = [TimeSpan]::FromSeconds(60)
    if ($Token) {
        $client.DefaultRequestHeaders.Authorization =
            [System.Net.Http.Headers.AuthenticationHeaderValue]::new('Bearer', $Token)
    }
    return $client
}

function Measure-Requests([System.Net.Http.HttpClient] $Client, [string[]] $Urls) {
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $tasks = @($Urls | ForEach-Object { $Client.GetAsync($_) })
    [System.Threading.Tasks.Task]::WaitAll([System.Threading.Tasks.Task[]] $tasks)
    $stopwatch.Stop()

    return [pscustomobject]@{
        seconds = [Math]::Round($stopwatch.Elapsed.TotalSeconds, 3)
        statuses = @($tasks | ForEach-Object { [int] $_.Result.StatusCode })
    }
}

$adminToken = Get-AccessToken $env:RUNTIME_BENCHMARK_ADMIN_EMAIL $env:RUNTIME_BENCHMARK_ADMIN_PASSWORD
$playerToken = Get-AccessToken $env:RUNTIME_BENCHMARK_PLAYER_EMAIL $env:RUNTIME_BENCHMARK_PLAYER_PASSWORD
$clients = @{
    public = New-ApiClient ''
    admin = New-ApiClient $adminToken
    player = New-ApiClient $playerToken
}

$cases = @(
    @{ name = 'public-games'; client = 'public'; paths = @('/api/v1/public/games') },
    @{ name = 'auth-me'; client = 'player'; paths = @('/api/v1/auth/me') },
    @{ name = 'me-entries'; client = 'player'; paths = @('/api/v1/me/entries') },
    @{ name = 'admin-games'; client = 'admin'; paths = @('/api/v1/admin/games') },
    @{ name = 'motor-parallel'; client = 'admin'; paths = @(
        "/api/v1/admin/games/$GameId",
        "/api/v1/admin/games/$GameId/draws",
        "/api/v1/admin/games/$GameId/counters",
        "/api/v1/admin/games/$GameId/winner"
    ) }
)

if ($CaseName -ne 'all') {
    $cases = @($cases | Where-Object { $_.name -eq $CaseName })
}

$results = foreach ($case in $cases) {
    $urls = @($case.paths | ForEach-Object { "$BaseUrl$_" })
    $samples = @(1..$Iterations | ForEach-Object {
        Measure-Requests $clients[$case.client] $urls
    })
    $warm = @($samples | Select-Object -Skip 1)

    [pscustomobject]@{
        endpoint = $case.name
        cold_seconds = $samples[0].seconds
        warm_average_seconds = [Math]::Round(($warm.seconds | Measure-Object -Average).Average, 3)
        worst_seconds = [Math]::Round(($samples.seconds | Measure-Object -Maximum).Maximum, 3)
        statuses = @($samples | ForEach-Object { $_.statuses -join ',' })
    }
}

$clients.Values | ForEach-Object { $_.Dispose() }
[pscustomobject]@{ base_url = $BaseUrl; iterations = $Iterations; results = $results } |
    ConvertTo-Json -Depth 6
