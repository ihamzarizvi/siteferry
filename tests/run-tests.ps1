<#
.SYNOPSIS
    Lints every PHP file and runs the CLI test suites on each PHP build.

.DESCRIPTION
    Uses LocalWP's PHP builds (newest 7.4, 8.2 and 8.5 under %APPDATA%\Local\lightning-services)
    unless -Php is given. PHP runs with -n (no php.ini), so only built-in extensions are used, plus
    sodium, OpenSSL and ftp for the suites when the build has them (the secrets and FTP tests).
    Any deprecation or warning during linting counts as a failure.

    Test data goes to tests\tmp\ and is removed afterwards unless -Keep is given.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\run-tests.ps1
#>
param(
    [string[]] $Php = @(),
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

if ($Php.Count -eq 0) {
    $base = Join-Path $env:APPDATA 'Local\lightning-services'
    $Php = foreach ($minor in '7.4', '8.2', '8.5') {
        Get-ChildItem $base -Directory -Filter "php-$minor.*" |
            Sort-Object { [version]($_.Name -replace '^php-([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') } |
            Select-Object -Last 1 |
            ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' }
    }
}

$files = Get-ChildItem $root -Recurse -Filter *.php |
    Where-Object { $_.FullName -notmatch '\\(\.git|tmp|vendor|node_modules)\\' }
$suites = Get-ChildItem (Join-Path $root 'tests') -Filter 'test-*.php' | Sort-Object Name
$failures = New-Object System.Collections.Generic.List[string]

# A junction inside a staging folder, for the safety test (PHP cannot create junctions itself).
$tmp = Join-Path $root 'tests\tmp'
New-Item -ItemType Directory -Force $tmp | Out-Null
$junctionCase = Join-Path $tmp ('junction-case-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force (Join-Path $junctionCase 'outside'), (Join-Path $junctionCase 'staging') | Out-Null
$junction = Join-Path $junctionCase 'staging\wp-content'
New-Item -ItemType Junction -Path $junction -Target (Join-Path $junctionCase 'outside') | Out-Null
$env:SITEFERRY_TEST_JUNCTION_CASE = $junctionCase

try {
    foreach ($exe in $Php) {
        $version = (& $exe -n -r 'echo PHP_VERSION;')
        Write-Host "=== PHP $version ($exe)" -ForegroundColor Cyan

        $lintProblems = 0
        foreach ($file in $files) {
            $out = (& $exe -n -d error_reporting=-1 -d display_errors=1 -l $file.FullName 2>&1 | Out-String)
            if ($LASTEXITCODE -ne 0 -or $out -match '(Deprecated|Warning|Notice):') {
                $lintProblems++
                Write-Host $out.Trim() -ForegroundColor Red
            }
        }
        Write-Host ("lint: {0} files, {1} problem(s)" -f $files.Count, $lintProblems)
        if ($lintProblems -gt 0) { $failures.Add("lint on PHP $version") }

        & $exe -n (Join-Path $root 'tests\compat-check.php')
        if ($LASTEXITCODE -ne 0) { $failures.Add("compat-check on PHP $version") }

        # The secrets and FTP tests need sodium, OpenSSL and ftp, which are not built in on Windows.
        $extArgs = @()
        $extDir = Join-Path (Split-Path $exe) 'ext'
        foreach ($ext in 'sodium', 'openssl', 'ftp') {
            if (Test-Path (Join-Path $extDir "php_$ext.dll")) {
                $extArgs += @('-d', "extension=$ext")
            }
        }
        if ($extArgs.Count -gt 0) { $extArgs = @('-d', "extension_dir=$extDir") + $extArgs }

        foreach ($suite in $suites) {
            $suiteArgs = @('-n', '-d', 'memory_limit=512M') + $extArgs + @($suite.FullName)
            if ($Keep) { $suiteArgs += '--keep' }
            & $exe @suiteArgs
            if ($LASTEXITCODE -ne 0) { $failures.Add("$($suite.Name) on PHP $version") }
        }
    }

    # The admin screen's SHA-256 fallback, if Node is installed.
    if (Get-Command node -ErrorAction SilentlyContinue) {
        Write-Host '=== JavaScript' -ForegroundColor Cyan
        & node (Join-Path $root 'tests\js\test-sha256.js')
        if ($LASTEXITCODE -ne 0) { $failures.Add('js/test-sha256.js') }
    }
    else {
        Write-Host 'Node not found: skipped tests\js\test-sha256.js' -ForegroundColor Yellow
    }
}
finally {
    # Remove the junction itself first (never its target), then the case folder.
    if (Test-Path $junction) { [System.IO.Directory]::Delete($junction) }
    if (-not $Keep) { Remove-Item -Recurse -Force $junctionCase }
    Remove-Item Env:\SITEFERRY_TEST_JUNCTION_CASE
}

Write-Host ''
if ($failures.Count -gt 0) {
    Write-Host ('FAILED: ' + ($failures -join '; ')) -ForegroundColor Red
    exit 1
}
Write-Host ('All checks passed on {0} PHP build(s).' -f $Php.Count) -ForegroundColor Green
exit 0
