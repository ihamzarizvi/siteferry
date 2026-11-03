<#
.SYNOPSIS
    Cross-checks SiteFerry's TAR format against bsdtar (Windows tar.exe) and GNU tar (Git for Windows).

.DESCRIPTION
    1. Archives written by SiteFerry (plain and block-gzip) are extracted by both tools.
    2. Archives written by both tools (pax, ustar, GNU formats, gzip) are extracted by SiteFerry.
    Each extracted tree is compared file by file (type, size, SHA-256) with the source tree.

    The tools are only used by this test script; the plugin never runs external programs.
    Test data goes to tests\tmp\ and is removed afterwards unless -Keep is given.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\interop-tar.ps1
#>
param(
    [string] $Php = '',
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot

if ($Php -eq '') {
    $base = Join-Path $env:APPDATA 'Local\lightning-services'
    $Php = Get-ChildItem $base -Directory -Filter 'php-8.*' |
        Sort-Object { [version]($_.Name -replace '^php-([0-9]+\.[0-9]+\.[0-9]+).*$', '$1') } |
        Select-Object -Last 1 |
        ForEach-Object { Join-Path $_.FullName 'bin\win64\php.exe' }
}
$tool = Join-Path $root 'tests\tools\siteferry-tar.php'
$bsdtar = Join-Path $env:SystemRoot 'System32\tar.exe'
$gitBash = Join-Path (Split-Path (Split-Path (Get-Command git.exe).Source)) 'bin\bash.exe'

$work = Join-Path $root ('tests\tmp\interop-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force $work | Out-Null
$results = New-Object System.Collections.Generic.List[object]

function Invoke-Tool([string[]] $toolArgs) {
    $out = & $Php -n -d memory_limit=512M $tool @toolArgs 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "siteferry-tar $($toolArgs[0]) failed: $out" }
    return $out
}

function ConvertTo-MsysPath([string] $path) {
    $full = [System.IO.Path]::GetFullPath($path)
    return '/' + $full.Substring(0, 1).ToLower() + ($full.Substring(2) -replace '\\', '/')
}

function Invoke-GnuTar([string] $arguments) {
    $out = & $gitBash -lc "LC_ALL=C.UTF-8 tar $arguments" 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "GNU tar failed: $out" }
}

function Invoke-BsdTar([string[]] $tarArgs) {
    $out = & $bsdtar @tarArgs 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "bsdtar failed: $out" }
}

function Test-Case([string] $name, [string] $sourceTree, [scriptblock] $produce) {
    $out = Join-Path $work ('out-' + ($name -replace '[^a-z0-9]+', '-'))
    New-Item -ItemType Directory -Force $out | Out-Null
    try {
        & $produce $out
        $got = Invoke-Tool @('tree', $out)
        if ($got -eq $sourceTree) {
            $results.Add([pscustomobject]@{ Case = $name; Result = 'PASS'; Detail = '' })
        } else {
            $diff = Compare-Object ($sourceTree -split "`n") ($got -split "`n") | Select-Object -First 4 |
                ForEach-Object { $_.SideIndicator + ' ' + $_.InputObject.Trim() }
            $results.Add([pscustomobject]@{ Case = $name; Result = 'FAIL'; Detail = ($diff -join ' | ') })
        }
    } catch {
        $results.Add([pscustomobject]@{ Case = $name; Result = 'FAIL'; Detail = $_.Exception.Message.Trim() })
    }
}

try {
    Write-Host "PHP: $Php"
    Write-Host ("bsdtar: " + (& $bsdtar --version))
    Write-Host ("GNU tar: " + ((& $gitBash -lc 'tar --version' | Select-Object -First 1)))

    # Fixture variants. GNU tar gets the full fixture. bsdtar on Windows converts names to the ANSI
    # code page, so it skips names outside it (Japanese, emoji) and crashes when archiving them.
    # Its gnutar and ustar formats also store names in that code page rather than UTF-8, which
    # SiteFerry refuses on purpose, so those formats get ASCII names only. ustar also cannot store
    # a 154-byte name segment or a 385-byte path.
    $variants = @(
        @{ Name = 'full';       Omit = '';                                                 Tools = @('gnu') },
        @{ Name = 'bsd';        Omit = 'japanese,emoji,over_255';                          Tools = @('bsd') },
        @{ Name = 'bsd-long';   Omit = 'japanese,emoji';                                   Tools = @('bsd') },
        @{ Name = 'bsd-ascii';  Omit = 'japanese,emoji,accents,over_255';                  Tools = @('bsd-gnutar') },
        @{ Name = 'bsd-ustar';  Omit = 'japanese,emoji,accents,over_255,segment_165';      Tools = @('bsd-ustar') }
    )

    foreach ($variant in $variants) {
        $name = $variant.Name
        $src = Join-Path $work "src-$name"
        New-Item -ItemType Directory -Force $src | Out-Null
        Invoke-Tool @('fixture', $src, ('--omit=' + $variant.Omit)) | Out-Null
        $tree = Invoke-Tool @('tree', $src)

        $ours = Join-Path $work "ours-$name.tar"
        $oursGz = Join-Path $work "ours-$name.tar.gz"
        Invoke-Tool @('pack', $src, $ours) | Out-Null
        Invoke-Tool @('pack', $src, $oursGz, '--gz') | Out-Null

        if ($variant.Tools -contains 'gnu') {
            Test-Case "[$name] SiteFerry tar -> GNU tar" $tree { param($o) Invoke-GnuTar "-xf '$(ConvertTo-MsysPath $ours)' -C '$(ConvertTo-MsysPath $o)'" }
            Test-Case "[$name] SiteFerry tar.gz -> GNU tar" $tree { param($o) Invoke-GnuTar "-xzf '$(ConvertTo-MsysPath $oursGz)' -C '$(ConvertTo-MsysPath $o)'" }
            foreach ($format in @('posix', 'gnu')) {
                $theirs = Join-Path $work "gnu-$format-$name.tar"
                Test-Case "[$name] GNU tar $format -> SiteFerry" $tree {
                    param($o)
                    Invoke-GnuTar "--format=$format -cf '$(ConvertTo-MsysPath $theirs)' -C '$(ConvertTo-MsysPath $src)' wp-content"
                    Invoke-Tool @('unpack', $theirs, $o) | Out-Null
                }
                $theirsGz = Join-Path $work "gnu-$format-$name.tar.gz"
                Test-Case "[$name] GNU tar $format.gz -> SiteFerry" $tree {
                    param($o)
                    Invoke-GnuTar "--format=$format -czf '$(ConvertTo-MsysPath $theirsGz)' -C '$(ConvertTo-MsysPath $src)' wp-content"
                    Invoke-Tool @('unpack', $theirsGz, $o) | Out-Null
                }
            }
        }

        if ($variant.Tools -contains 'bsd') {
            Test-Case "[$name] SiteFerry tar -> bsdtar" $tree { param($o) Invoke-BsdTar @('-xf', $ours, '-C', $o) }
            Test-Case "[$name] SiteFerry tar.gz -> bsdtar" $tree { param($o) Invoke-BsdTar @('-xzf', $oursGz, '-C', $o) }
            $theirs = Join-Path $work "bsd-pax-$name.tar"
            Test-Case "[$name] bsdtar pax -> SiteFerry" $tree {
                param($o)
                Invoke-BsdTar @('--format', 'pax', '-cf', $theirs, '-C', $src, 'wp-content')
                Invoke-Tool @('unpack', $theirs, $o) | Out-Null
            }
            $theirsGz = Join-Path $work "bsd-pax-$name.tar.gz"
            Test-Case "[$name] bsdtar pax.gz -> SiteFerry" $tree {
                param($o)
                Invoke-BsdTar @('--format', 'pax', '-czf', $theirsGz, '-C', $src, 'wp-content')
                Invoke-Tool @('unpack', $theirsGz, $o) | Out-Null
            }
        }

        foreach ($format in @('gnutar', 'ustar')) {
            if ($variant.Tools -contains "bsd-$format") {
                $theirs = Join-Path $work "bsd-$format-$name.tar"
                Test-Case "[$name] bsdtar $format -> SiteFerry" $tree {
                    param($o)
                    Invoke-BsdTar @('--format', $format, '-cf', $theirs, '-C', $src, 'wp-content')
                    Invoke-Tool @('unpack', $theirs, $o) | Out-Null
                }
            }
        }
    }
}
finally {
    $results | Format-Table -AutoSize -Wrap | Out-String -Width 200 | Write-Host
    # The \\?\ prefix lets Windows PowerShell delete paths longer than 260 characters.
    if (-not $Keep) { Remove-Item -LiteralPath ('\\?\' + $work) -Recurse -Force }
}

$failed = @($results | Where-Object { $_.Result -ne 'PASS' }).Count
Write-Host ("interop: {0}/{1} passed" -f ($results.Count - $failed), $results.Count)
exit ([int]($failed -gt 0))
