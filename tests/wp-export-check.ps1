<#
.SYNOPSIS
    End-to-end export test on a LocalWP test site (default: siteferry-src).

.DESCRIPTION
    1. Links this plugin into the site with a junction (if not linked yet) and activates it.
    2. Adds test content: files with long and Unicode names under uploads/sftest, a spam comment
       and a post revision with marker text.
    3. Runs the database dump round trip (tests/wp/test-dump.php).
    4. Exports twice with "wp siteferry export": default options, then compressed with
       media, spam comments, revisions and inactive plugins/themes left out.
    5. Checks each backup independently: GNU tar extracts it, "sha256sum -c" verifies every
       checksum in siteferry.sha256, and the manifest, dump end marker and exclusions are checked.
    6. Exports with --dir (nothing written to the site's storage) and lists backups.

    Only run this against a disposable test site.
#>
param(
    [string] $Site = 'siteferry-src',
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'lib\LocalSite.ps1')

if ($Site -notmatch '^siteferry-') { throw 'This test changes the site; it only runs on siteferry-* test sites.' }
$s = Get-LocalSite $Site
$gitBash = Join-Path (Split-Path (Split-Path (Get-Command git.exe).Source)) 'bin\bash.exe'
$work = Join-Path $root ('tests\tmp\export-check-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force $work | Out-Null
$results = New-Object System.Collections.Generic.List[object]

# Runs WP-CLI and returns its standard output; standard error is only shown when the command fails.
function Wp {
    # Windows PowerShell turns redirected native stderr into errors; with 'Stop' they would abort.
    $ErrorActionPreference = 'Continue'
    $errFile = Join-Path $work ('wp-stderr-' + [guid]::NewGuid().ToString('N') + '.txt')
    $out = Invoke-SiteWp $s @args 2> $errFile | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    $err = if (Test-Path $errFile) { (Get-Content $errFile -Raw); Remove-Item $errFile } else { '' }
    if ($code -ne 0) { throw ("wp " + ($args -join ' ') + " failed:`n" + ($out -join "`n") + "`n" + $err) }
    return $out
}
function Check([string] $name, [scriptblock] $test) {
    try { & $test; $results.Add([pscustomobject]@{ Check = $name; Result = 'PASS'; Detail = '' }) }
    catch { $results.Add([pscustomobject]@{ Check = $name; Result = 'FAIL'; Detail = $_.Exception.Message.Trim() }) }
}
function Assert([bool] $condition, [string] $message) { if (-not $condition) { throw $message } }
function ConvertTo-MsysPath([string] $path) {
    $full = [System.IO.Path]::GetFullPath($path)
    return '/' + $full.Substring(0, 1).ToLower() + ($full.Substring(2) -replace '\\', '/')
}
function Bash([string] $command) {
    $out = & $gitBash -lc "LC_ALL=C.UTF-8 $command" 2>&1 | ForEach-Object { "$_" }
    if ($LASTEXITCODE -ne 0) { throw ("$command failed:`n" + ($out -join "`n")) }
    return $out
}

# Extracts a backup with GNU tar and runs sha256sum -c; returns the extraction folder.
function Test-Backup([string] $archive, [string] $label) {
    $out = Join-Path $work $label
    New-Item -ItemType Directory -Force $out | Out-Null
    $flag = if ($archive.EndsWith('.gz')) { '-xzf' } else { '-xf' }
    Bash "tar $flag '$(ConvertTo-MsysPath $archive)' -C '$(ConvertTo-MsysPath $out)'" | Out-Null
    $check = Bash "cd '$(ConvertTo-MsysPath $out)' && sha256sum -c --quiet siteferry.sha256 && wc -l < siteferry.sha256"
    return [pscustomobject]@{ Dir = $out; Checked = [int]($check | Select-Object -Last 1) }
}

try {
    Write-Host "Site: $($s.Name) ($($s.Url)), PHP $(& $s.Php -r 'echo PHP_VERSION;')"
    Wp option get siteurl | Out-Null

    # 1. Link and activate.
    $link = Join-Path $s.Public 'wp-content\plugins\siteferry'
    if (-not (Test-Path $link)) { New-Item -ItemType Junction -Path $link -Target $root | Out-Null }
    Wp plugin activate siteferry | Out-Null

    # 2. Test content.
    $uploads = Join-Path $s.Public 'wp-content\uploads\sftest'
    if (-not (Test-Path $uploads)) {
        New-Item -ItemType Directory -Force $uploads | Out-Null
        & $s.Php -n (Join-Path $root 'tests\tools\siteferry-tar.php') fixture $uploads | Out-Null
    }
    $spam = Wp comment create --comment_post_ID=1 --comment_content=SFTEST-SPAM-MARKER --comment_approved=spam --porcelain
    $post = Wp post create --post_title=SFTEST-REVISION-POST --post_content=SFTEST-REVISION-MARKER-OLD --post_status=publish --porcelain
    Wp post update $post --post_content=SFTEST-REVISION-MARKER-NEW | Out-Null
    # Revisions hold the saved content, so they are recognised by their slugs ("<id>-revision-v1").
    $revisions = @(Wp post list --post_type=revision "--post_parent=$post" --post_status=inherit --field=post_name)
    if ($revisions.Count -eq 0) { throw 'WordPress created no revision for the test post.' }

    # 3. Dump round trip.
    Check 'database dump round trip (tests/wp/test-dump.php)' {
        $out = Wp eval-file (Join-Path $root 'tests\wp\test-dump.php')
        Write-Host ($out -join "`n")
        Assert ($out -match 'passed').Count -gt 0 'no summary'
    }

    # 4. Exports.
    $default = (Wp siteferry export --porcelain | Select-Object -Last 1).Trim()
    Check 'default export: GNU tar extracts it and every checksum matches' {
        $r = Test-Backup $default 'default'
        Assert ($r.Checked -gt 50) "only $($r.Checked) checksums"
        $manifest = Get-Content (Join-Path $r.Dir 'siteferry.json') -Raw | ConvertFrom-Json
        Assert ($manifest.format -eq 'siteferry-backup') 'manifest format'
        Assert ($manifest.site.home -eq (Wp option get home)) 'manifest home URL'
        $sql = Get-Content (Join-Path $r.Dir 'database.sql') -Raw -Encoding UTF8
        Assert ($sql.TrimEnd().EndsWith('-- SiteFerry dump end')) 'dump end marker'
        Assert ($sql.Contains('SFTEST-SPAM-MARKER')) 'spam comment should be in a default export'
        foreach ($revision in $revisions) { Assert ($sql.Contains("'$revision'")) "revision $revision should be in a default export" }
        Assert (-not $sql.Contains("'siteferry_storage'")) 'SiteFerry options must not be exported'
        # Built from code points: Windows PowerShell reads scripts without a BOM as ANSI.
        $emoji = 'wp-content\uploads\sftest\wp-content\uploads\emoji-' + [char]::ConvertFromUtf32(0x1F600) + '\party-' + [char]::ConvertFromUtf32(0x1F389) + '.txt'
        Assert (Test-Path -LiteralPath (Join-Path $r.Dir $emoji)) 'emoji file'
        Assert (-not (Test-Path (Join-Path $r.Dir 'wp-content\plugins\siteferry'))) 'SiteFerry itself must not be exported'
        Assert (-not (Get-ChildItem (Join-Path $r.Dir 'wp-content') -Directory -Filter 'siteferry-*')) 'storage folder must not be exported'
    }

    $slim = (Wp siteferry export --porcelain --compress '--exclude=media,spam-comments,revisions,inactive-plugins,inactive-themes' | Select-Object -Last 1).Trim()
    Check 'compressed export with exclusions: valid .tar.gz, checksums match, exclusions applied' {
        Assert $slim.EndsWith('.tar.gz') 'name should end in .tar.gz'
        $r = Test-Backup $slim 'slim'
        $sql = Get-Content (Join-Path $r.Dir 'database.sql') -Raw -Encoding UTF8
        Assert (-not $sql.Contains('SFTEST-SPAM-MARKER')) 'spam comment should be left out'
        foreach ($revision in $revisions) { Assert (-not $sql.Contains("'$revision'")) "revision $revision should be left out" }
        Assert ($sql.Contains('SFTEST-REVISION-MARKER-NEW')) 'the post itself must stay'
        Assert (-not (Test-Path (Join-Path $r.Dir 'wp-content\uploads'))) 'uploads should be left out'
        $themes = @(Get-ChildItem (Join-Path $r.Dir 'wp-content\themes') -Directory).Count
        Assert ($themes -le 2) "only the active theme (and parent) should remain, found $themes"
    }

    $dirOut = Join-Path $work 'dir-export'
    Check 'export with --dir writes there' {
        $file = (Wp siteferry export --porcelain "--dir=$dirOut" | Select-Object -Last 1).Trim()
        Assert ([IO.Path]::GetFullPath($file).StartsWith((Resolve-Path $dirOut).Path)) "unexpected path $file"
        $list = Wp siteferry backups list "--dir=$dirOut" --format=json | ConvertFrom-Json
        Assert ($list.Count -eq 1 -and $list[0].status -eq 'ok') 'backups list --dir'
    }

    Check 'backups list shows the stored backups; storage folder is protected' {
        $list = Wp siteferry backups list --format=json | ConvertFrom-Json
        Assert ($list.Count -ge 2) "expected at least 2 backups, got $($list.Count)"
        $storage = Split-Path (Split-Path $default)
        foreach ($f in 'index.php', '.htaccess', 'web.config') { Assert (Test-Path (Join-Path $storage $f)) "$f missing in storage" }
        Assert ((Split-Path $storage -Leaf) -match '^siteferry-[a-f0-9]{12}$') 'random storage folder name'
    }

    # Informational: nginx ignores .htaccess, so there only the random names protect a backup's URL.
    $url = $s.Url + '/wp-content/' + (Split-Path (Split-Path (Split-Path $default)) -Leaf) + '/backups/' + (Split-Path $default -Leaf)
    $status = try { (Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing -TimeoutSec 10).StatusCode } catch { [int]$_.Exception.Response.StatusCode }
    $results.Add([pscustomobject]@{
        Check  = 'direct request for a backup by its exact URL'
        Result = $(if ($status -in 401, 403, 404) { 'PASS' } else { 'WARN' })
        Detail = "HTTP $status" + $(if ($status -eq 200) { ' (served: this web server ignores the deny files; only the random names protect it)' } else { '' })
    })

    # Remove the marker content again.
    Wp comment delete $spam --force | Out-Null
    Wp post delete $post --force | Out-Null
}
finally {
    $results | Format-Table -AutoSize -Wrap | Out-String -Width 220 | Write-Host
    if (-not $Keep) { Remove-Item -LiteralPath ('\\?\' + $work) -Recurse -Force -ErrorAction SilentlyContinue }
}

$failed = @($results | Where-Object { $_.Result -eq 'FAIL' }).Count
$warned = @($results | Where-Object { $_.Result -eq 'WARN' }).Count
Write-Host ("export check: {0}/{1} passed, {2} warning(s)" -f ($results.Count - $failed - $warned), $results.Count, $warned)
exit ([int]($failed -gt 0))
