<#
.SYNOPSIS
    Runs tests\wp\test-storage.php on a Local test site against a fake S3 server.

.DESCRIPTION
    Starts tests\tools\fake-s3-server.php with "php -S" on 127.0.0.1 (test keys generated for this
    run, data in tests\tmp\), runs the WordPress storage test through WP-CLI and stops the server.
    With -Serve the server keeps running for browser tests until Enter is pressed; the keys are in
    the printed config.json (test values only).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Site siteferry-src
#>
param(
    [string] $Site = 'siteferry-src',
    [int] $Port = 18765,
    [switch] $Serve,
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$php = Get-ChildItem (Join-Path $env:APPDATA 'Local\lightning-services') -Directory -Filter 'php-8.*' |
    Sort-Object { [version]($_.Name -replace '^php-([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') } |
    Select-Object -Last 1 |
    ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' }

$alphabet = [char[]] 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
function New-TestKey([int] $Length) {
    $bytes = New-Object byte[] $Length
    $rng.GetBytes($bytes)
    -join ($bytes | ForEach-Object { $alphabet[$_ % $alphabet.Length] })
}

$dir = Join-Path $root ('tests\tmp\fake-s3-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force $dir | Out-Null
$config = [ordered] @{
    access_key = 'SFTEST' + (New-TestKey 14).ToUpper().Replace('+', 'X').Replace('/', 'Y')
    secret_key = New-TestKey 40
    region     = 'us-east-1'
    page_size  = 2
    buckets    = @('sftest')
}
[IO.File]::WriteAllText((Join-Path $dir 'config.json'), ($config | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))

$env:SITEFERRY_FAKE_S3_DIR = $dir
$env:SITEFERRY_FAKE_S3_URL = "http://127.0.0.1:$Port"
$server = Start-Process -FilePath $php -ArgumentList @('-n', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$Port", (Join-Path $root 'tests\tools\fake-s3-server.php')) `
    -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $dir 'server.out') -RedirectStandardError (Join-Path $dir 'server.err')

$code = 1
try {
    $ready = $false
    for ($i = 0; $i -lt 50 -and -not $ready; $i++) {
        try {
            $client = New-Object System.Net.Sockets.TcpClient('127.0.0.1', $Port)
            $client.Close()
            $ready = $true
        } catch {
            Start-Sleep -Milliseconds 100
        }
    }
    if (-not $ready) { throw "The fake S3 server did not start on port $Port." }

    if ($Serve) {
        Write-Host "Fake S3 server at http://127.0.0.1:$Port (bucket sftest). Test keys: $(Join-Path $dir 'config.json')"
        Read-Host 'Press Enter to stop it'
        $code = 0
    } else {
        $ErrorActionPreference = 'Continue'
        & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wp-cli.ps1') $Site --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-storage.php
        $code = $LASTEXITCODE
    }
}
finally {
    Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
    Remove-Item Env:\SITEFERRY_FAKE_S3_DIR, Env:\SITEFERRY_FAKE_S3_URL -ErrorAction SilentlyContinue
    if (-not $Keep) {
        Start-Sleep -Milliseconds 300
        Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
    }
}
exit $code
