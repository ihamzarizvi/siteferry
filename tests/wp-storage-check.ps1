<#
.SYNOPSIS
    Runs tests\wp\test-storage.php on a Local test site against fake S3 and FTPS servers.

.DESCRIPTION
    For each driver, starts its fake server on 127.0.0.1 with test logins generated for this run
    (data in tests\tmp\), runs the WordPress storage test through WP-CLI and stops the server:
      s3:  tests\tools\fake-s3-server.php under "php -S" (port 18765)
      ftp: tests\tools\fake-ftp-server.php, explicit FTPS with a self-signed certificate (port 18767)
      gdrive: tests\tools\fake-google-server.php under "php -S" (port 18769), OAuth and Drive
      gdrive-relay: the same, plus the real relay (relay\public\index.php, port 18771) in between
      dropbox: tests\tools\fake-dropbox-server.php under "php -S" (port 18773), OAuth and API v2
      dropbox-relay: the same, with the browser passing through the real relay (port 18771)
    The backup sent first is the database only; -Full sends the whole site without media instead,
    started through the REST API (slow on a big site: use it when the transfer code changes).
    With -Serve the server of -Driver keeps running for browser tests until Enter is pressed; the
    logins are in the printed config.json (test values only).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Site siteferry-src
    powershell -ExecutionPolicy Bypass -File tests\wp-storage-check.ps1 -Driver ftp
#>
param(
    [string] $Site = 'siteferry-src',
    [ValidateSet('s3', 'ftp', 'gdrive', 'gdrive-relay', 'dropbox', 'dropbox-relay', 'all')] [string] $Driver = 'all',
    [switch] $Serve,
    [switch] $Keep,
    [switch] $Full
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

# Starts one php process; returns it.
function Start-Php([string[]] $Arguments, [string] $Dir, [string] $Name, [int] $Port) {
    # Start-Process joins arguments with spaces; quote the ones that contain spaces.
    $quoted = $Arguments | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }
    $process = Start-Process -FilePath $php -ArgumentList $quoted -PassThru -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $Dir "$Name.out") -RedirectStandardError (Join-Path $Dir "$Name.err")
    Wait-Port $Port
    return $process
}

# Starts the fake server(s) for a kind; returns the processes. Sets the environment variables the test reads.
function Start-FakeServer([string] $Kind, [string] $Dir) {
    $env:SITEFERRY_TEST_STORAGE = $Kind
    if ($Kind -eq 'dropbox' -or $Kind -eq 'dropbox-relay') {
        $port = 18773
        $relayPort = 18771
        $config = [ordered] @{
            base      = "http://127.0.0.1:$port"
            app_key   = (New-TestKey 15).ToLower().Replace('+', 'x').Replace('/', 'y')
            account   = 'tester@example.com'
            page_size = 2
        }
        if ($Kind -eq 'dropbox-relay') {
            # Like Dropbox: only the relay's registered redirect address is accepted.
            $config.redirect_uris = @("http://127.0.0.1:$relayPort/siteferry/dropbox/callback")
        }
        Write-Json (Join-Path $Dir 'config.json') $config
        New-Item -ItemType Directory -Force (Join-Path $Dir 'data') | Out-Null
        $env:SITEFERRY_FAKE_DROPBOX_DIR = $Dir
        $dropbox = Start-Php @('-n', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", (Join-Path $root 'tests\tools\fake-dropbox-server.php')) $Dir 'dropbox' $port
        if ($Kind -eq 'dropbox') { return @($dropbox) }

        $key = -join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })
        $relayConfig = Join-Path $Dir 'relay-config.php'
        $lines = @(
            '<?php',
            'return array(',
            "    'base_url' => 'http://127.0.0.1:$relayPort/siteferry',",
            "    'signing_key' => '$key',",
            "    'dropbox_app_key' => '$($config.app_key)',",
            "    'dropbox_auth_url' => 'http://127.0.0.1:$port/oauth2/authorize',",
            ');'
        )
        [IO.File]::WriteAllText($relayConfig, ($lines -join "`n"), (New-Object Text.UTF8Encoding $false))
        $env:SITEFERRY_RELAY_CONFIG = $relayConfig
        $env:SITEFERRY_FAKE_RELAY_URL = "http://127.0.0.1:$relayPort/siteferry"
        $relay = Start-Php @('-n', '-S', "127.0.0.1:$relayPort", (Join-Path $root 'relay\public\index.php')) $Dir 'relay' $relayPort
        return @($dropbox, $relay)
    }
    if ($Kind -eq 'gdrive' -or $Kind -eq 'gdrive-relay') {
        $port = 18769
        $config = [ordered] @{
            base          = "http://127.0.0.1:$port"
            client_id     = ('123456-' + (New-TestKey 10).ToLower().Replace('+', 'x').Replace('/', 'y') + '.apps.googleusercontent.com')
            client_secret = 'GOCSPX-' + (New-TestKey 24).Replace('+', 'x').Replace('/', 'y')
            account       = 'tester@example.com'
            page_size     = 2
        }
        Write-Json (Join-Path $Dir 'config.json') $config
        $env:SITEFERRY_FAKE_GOOGLE_DIR = $Dir
        $google = Start-Php @('-n', '-d', 'memory_limit=512M', '-S', "127.0.0.1:$port", (Join-Path $root 'tests\tools\fake-google-server.php')) $Dir 'google' $port
        if ($Kind -eq 'gdrive') { return @($google) }

        # The real relay between the site and the fake Google, with a test config of its own.
        $relayPort = 18771
        $key = -join ((1..32) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })
        $relayConfig = Join-Path $Dir 'relay-config.php'
        $lines = @(
            '<?php',
            'return array(',
            "    'base_url' => 'http://127.0.0.1:$relayPort/siteferry',",
            "    'signing_key' => '$key',",
            "    'google_client_id' => '$($config.client_id)',",
            "    'google_client_secret' => '$($config.client_secret)',",
            "    'google_auth_url' => 'http://127.0.0.1:$port/o/oauth2/v2/auth',",
            "    'google_token_url' => 'http://127.0.0.1:$port/token',",
            ');'
        )
        [IO.File]::WriteAllText($relayConfig, ($lines -join "`n"), (New-Object Text.UTF8Encoding $false))
        $env:SITEFERRY_RELAY_CONFIG = $relayConfig
        $env:SITEFERRY_FAKE_RELAY_URL = "http://127.0.0.1:$relayPort/siteferry"
        $relay = Start-Php @('-n', '-d', "extension_dir=$extDir", '-d', 'extension=curl', '-S', "127.0.0.1:$relayPort", (Join-Path $root 'relay\public\index.php')) $Dir 'relay' $relayPort
        return @($google, $relay)
    }
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
    return @(Start-Php $arguments $Dir 'server' $port)
}

$kinds = if ($Driver -eq 'all') { @('s3', 'ftp', 'gdrive', 'gdrive-relay', 'dropbox', 'dropbox-relay') } else { @($Driver) }
if ($Serve -and $kinds.Count -ne 1) { throw 'Use -Serve with one -Driver.' }

$failed = 0
foreach ($kind in $kinds) {
    $dir = Join-Path $root ("tests\tmp\fake-$kind-" + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Force $dir | Out-Null
    $servers = @()
    try {
        $servers = Start-FakeServer $kind $dir
        if ($Serve) {
            Write-Host "Fake $kind server running. Test logins: $(Join-Path $dir 'config.json')"
            Read-Host 'Press Enter to stop it'
        } else {
            $ErrorActionPreference = 'Continue'
            $env:SITEFERRY_STORAGE_FULL = if ($Full) { '1' } else { '' }
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wp-cli.ps1') $Site --skip-themes "--exec=define('DISABLE_WP_CRON', true);" eval-file tests/wp/test-storage.php
            if ($LASTEXITCODE -ne 0) { $failed++ }
            $ErrorActionPreference = 'Stop'
        }
    }
    finally {
        foreach ($server in $servers) { Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue }
        foreach ($name in 'SITEFERRY_TEST_STORAGE', 'SITEFERRY_FAKE_S3_DIR', 'SITEFERRY_FAKE_S3_URL', 'SITEFERRY_FAKE_FTP_DIR', 'SITEFERRY_FAKE_GOOGLE_DIR', 'SITEFERRY_FAKE_DROPBOX_DIR', 'SITEFERRY_RELAY_CONFIG', 'SITEFERRY_FAKE_RELAY_URL') {
            Remove-Item "Env:\$name" -ErrorAction SilentlyContinue
        }
        if (-not $Keep) {
            Start-Sleep -Milliseconds 300
            Remove-Item -Recurse -Force $dir -ErrorAction SilentlyContinue
        }
    }
}
exit $(if ($failed -gt 0) { 1 } else { 0 })
