<#
.SYNOPSIS
    Runs tests\wp\test-schedule-copies.php (a scheduled backup sent to several storages) on a Local
    test site, against a fake S3 server and a fake FTPS server started together.

.DESCRIPTION
    The fake servers run on 127.0.0.1 with test logins generated for this run (data in tests\tmp\):
      s3:  tests\tools\fake-s3-server.php under "php -S" (port 18765)
      ftp: tests\tools\fake-ftp-server.php, explicit FTPS with a self-signed certificate (port 18767)
    Both are stopped and their data removed at the end (unless -Keep).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-copies-check.ps1 -Site siteferry-dst
#>
param(
    [string] $Site = 'siteferry-dst',
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$php = Get-ChildItem (Join-Path $env:APPDATA 'Local\lightning-services') -Directory -Filter 'php-8.*' |
    Sort-Object { [version]($_.Name -replace '^php-([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') } |
    Select-Object -Last 1 |
    ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' }
$extDir = Join-Path (Split-Path $php) 'ext'

$alphabet = [char[]] 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'
$rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
function New-TestKey([int] $Length) {
    $bytes = New-Object byte[] $Length
    $rng.GetBytes($bytes)
    -join ($bytes | ForEach-Object { $alphabet[$_ % $alphabet.Length] })
}

function Wait-Port([int] $Port) {
    for ($i = 0; $i -lt 100; $i++) {
        try {
            $client = New-Object System.Net.Sockets.TcpClient('127.0.0.1', $Port)
            $client.Close()
            return
        } catch {
            Start-Sleep -Milliseconds 100
        }
    }
    throw "The fake server did not start on port $Port."
}

function Write-Json([string] $Path, $Value) {
    [IO.File]::WriteAllText($Path, ($Value | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))
}

function Start-Php([string[]] $Arguments, [string] $Dir, [string] $Name, [int] $Port) {
    $quoted = $Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
    $process = Start-Process -FilePath $php -ArgumentList $quoted -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $Dir "$Name.out") -RedirectStandardError (Join-Path $Dir "$Name.err")
    Wait-Port $Port
    return $process
}

$id = [guid]::NewGuid().ToString('N').Substring(0, 8)
$s3Dir = Join-Path $root "tests\tmp\fake-copies-s3-$id"
$ftpDir = Join-Path $root "tests\tmp\fake-copies-ftp-$id"
New-Item -ItemType Directory -Force $s3Dir, (Join-Path $ftpDir 'root') | Out-Null
$servers = @()
$code = 1
try {
    Write-Json (Join-Path $s3Dir 'config.json') ([ordered] @{
        access_key = 'SFTEST' + (New-TestKey 14).ToUpper()
        secret_key = New-TestKey 40
        region     = 'us-east-1'
        page_size  = 2
        buckets    = @('sftest')
    })
    # The fake S3 server reads its folder from the environment, so it is set before it starts.
    $env:SITEFERRY_FAKE_S3_DIR = $s3Dir
    $env:SITEFERRY_FAKE_S3_URL = 'http://127.0.0.1:18765'
    $servers += Start-Php @('-n', '-d', 'memory_limit=512M', '-S', '127.0.0.1:18765', (Join-Path $root 'tests\tools\fake-s3-server.php')) $s3Dir 's3' 18765

    $user = 'sftest-' + (New-TestKey 6).ToLower()
    Write-Json (Join-Path $ftpDir 'config.json') ([ordered] @{ port = 18767; user = $user; pass = (New-TestKey 24) })
    $ftpConfig = Get-Content (Join-Path $ftpDir 'config.json') -Raw | ConvertFrom-Json
    $servers += Start-Php @('-n', '-d', "extension_dir=$extDir", '-d', 'extension=openssl', (Join-Path $root 'tests\tools\fake-ftp-server.php'),
        '--port=18767', ('--root=' + (Join-Path $ftpDir 'root')), "--user=$user", "--pass=$($ftpConfig.pass)",
        ('--cert=' + (Join-Path $ftpDir 'cert.pem')), ('--faults=' + (Join-Path $ftpDir 'faults.json')),
        ('--log=' + (Join-Path $ftpDir 'commands.log')), '--idle=900') $ftpDir 'ftp' 18767

    $env:SITEFERRY_FAKE_S3_DIR = $s3Dir
    $env:SITEFERRY_FAKE_S3_URL = 'http://127.0.0.1:18765'
    $env:SITEFERRY_FAKE_FTP_DIR = $ftpDir
    $ErrorActionPreference = 'Continue'
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wp-cli.ps1') $Site --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-schedule-copies.php
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
}
finally {
    foreach ($server in $servers) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
    foreach ($name in 'SITEFERRY_FAKE_S3_DIR', 'SITEFERRY_FAKE_S3_URL', 'SITEFERRY_FAKE_FTP_DIR') {
        Remove-Item "Env:\$name" -ErrorAction SilentlyContinue
    }
    if (-not $Keep) {
        Start-Sleep -Milliseconds 300
        Remove-Item -Recurse -Force $s3Dir, $ftpDir -ErrorAction SilentlyContinue
    }
}
exit $code
