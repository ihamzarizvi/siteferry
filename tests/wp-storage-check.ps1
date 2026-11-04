<#
.SYNOPSIS
    Runs tests\wp\test-storage.php on a Local test site against fake S3 and FTPS servers.

.DESCRIPTION
    For each driver, starts its fake server on 127.0.0.1 with test logins generated for this run
    (data in tests\tmp\), runs the WordPress storage test through WP-CLI and stops the server:
      s3:  tests\tools\fake-s3-server.php under "php -S" (port 18765)
      ftp: tests\tools\fake-ftp-server.php, explicit FTPS with a self-signed certificate (port 18767)
      gdrive: tests\tools\fake-google-server.php under "php -S" (port 18769), OAuth and Drive
    With -Serve the server of -Driver keeps running for browser tests until Enter is pressed; the
    logins are in the printed config.json (test values only).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Site siteferry-src
    powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Driver ftp
#>
param(
    [string] $Site = 'siteferry-src',
    [ValidateSet('s3', 'ftp', 'gdrive', 'all')] [string] $Driver = 'all',
    [switch] $Serve,
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

$php = Get-ChildItem (Join-Path $env:APPDATA 'Local\lightning-services') -Directory -Filter 'php-8.*' |
    Sort-Object { [version]($_.Name -replace '^php-([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') } |
    Select-Object -Last 1 |
    ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' }
$extDir = Join-Path (Split-Path $php) 'ext'

$alphabet = [char[]] 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'
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

# Starts one fake server; returns the process. Sets the environment variables the test reads.
function Start-FakeServer([string] $Kind, [string] $Dir) {
    $env:SITEFERRY_TEST_STORAGE = $Kind
    $out = Join-Path $Dir 'server.out'
    $err = Join-Path $Dir 'server.err'
    if ($Kind -eq 's3') {
        $port = 18765
        Write-Json (Join-Path $Dir 'config.json') ([ordered] @{
            access_key = 'SFTEST' + (New-TestKey 14).ToUpper().Replace('+', 'X').Replace('/', 'Y')
            secret_key = New-TestKey 40
            region     = 'us-east-1'
            page_size  = 2
            buckets    = @('sftest')
        })
        $env:SITEFERRY_FAKE_S3_DIR = $Dir
        $env:SITEFERRY_FAKE_S3_URL = "http://127.0.0.1:$port"
        $arguments = @('-n', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", (Join-Path $root 'tests\tools\fake-s3-server.php'))
    } elseif ($Kind -eq 'gdrive') {
        $port = 18769
        Write-Json (Join-Path $Dir 'config.json') ([ordered] @{
            base          = "http://127.0.0.1:$port"
            client_id     = ('123456-' + (New-TestKey 10).ToLower().Replace('+', 'x').Replace('/', 'y') + '.apps.googleusercontent.com')
            client_secret = 'GOCSPX-' + (New-TestKey 24).Replace('+', 'x').Replace('/', 'y')
            account       = 'tester@example.com'
            page_size     = 2
        })
        $env:SITEFERRY_FAKE_GOOGLE_DIR = $Dir
        $arguments = @('-n', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", (Join-Path $root 'tests\tools\fake-google-server.php'))
    } else {
        $port = 18767
        $user = 'sftest-' + (New-TestKey 6).ToLower().Replace('+', 'x').Replace('/', 'y')
        $pass = New-TestKey 24
        Write-Json (Join-Path $Dir 'config.json') ([ordered] @{ port = $port; user = $user; pass = $pass })
        New-Item -ItemType Directory -Force (Join-Path $Dir 'root') | Out-Null
        $env:SITEFERRY_FAKE_FTP_DIR = $Dir
        $arguments = @('-n', '-d', "extension_dir=$extDir", '-d', 'extension=openssl', (Join-Path $root 'tests\tools\fake-ftp-server.php'),
            "--port=$port", ('--root=' + (Join-Path $Dir 'root')), "--user=$user", "--pass=$pass",
            ('--cert=' + (Join-Path $Dir 'cert.pem')), ('--faults=' + (Join-Path $Dir 'faults.json')),
            ('--log=' + (Join-Path $Dir 'commands.log')), '--idle=900')
    }
    # Start-Process joins arguments with spaces; quote the ones that contain spaces.
    $quoted = $arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
    $process = Start-Process -FilePath $php -ArgumentList $quoted -PassThru -WindowStyle Hidden -RedirectStandardOutput $out -RedirectStandardError $err
    Wait-Port $port
    return $process
}

$kinds = if ($Driver -eq 'all') { @('s3', 'ftp', 'gdrive') } else { @($Driver) }
if ($Serve -and $kinds.Count -ne 1) { throw 'Use -Serve with -Driver s3 or -Driver ftp.' }

$failed = 0
foreach ($kind in $kinds) {
    $dir = Join-Path $root ("tests\tmp\fake-$kind-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force $dir | Out-Null
    $server = $null
    try {
        $server = Start-FakeServer $kind $dir
        if ($Serve) {
            Write-Host "Fake $kind server running. Test logins: $(Join-Path $dir 'config.json')"
            Read-Host 'Press Enter to stop it'
        } else {
            $ErrorActionPreference = 'Continue'
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wp-cli.ps1') $Site --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-storage.php
            if ($LASTEXITCODE -ne 0) { $failed++ }
            $ErrorActionPreference = 'Stop'
        }
    }
    finally {
        if ($null -ne $server) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
        foreach ($name in 'SITEFERRY_TEST_STORAGE', 'SITEFERRY_FAKE_S3_DIR', 'SITEFERRY_FAKE_S3_URL', 'SITEFERRY_FAKE_FTP_DIR', 'SITEFERRY_FAKE_GOOGLE_DIR') {
            Remove-Item "Env:\$name" -ErrorAction SilentlyContinue
        }
        if (-not $Keep) {
            Start-Sleep -Milliseconds 300
            Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
        }
    }
}
exit $(if ($failed -gt 0) { 1 } else { 0 })
