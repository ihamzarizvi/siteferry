<#
.SYNOPSIS
    End-to-end import tests. Imports ONLY into siteferry-dst, and replaces its site.

.DESCRIPTION
    -Scenario site      Adds marker content to siteferry-src (URLs in post content, JSON, serialized
                        post meta and options), exports it and imports it into siteferry-dst. Checks
                        addresses, serialized data, files, users, SiteFerry staying active and its
                        settings kept, no leftovers, and that the front page loads.
    -Scenario rollback  A backup of siteferry-dst itself (with marker content), imported with a
                        simulated failure right after the table swap. Checks that its tables (CHECKSUM
                        TABLE) and files are exactly as before, nothing is left over, and the safety
                        backup made first was removed again. Does not touch siteferry-src.
    -Scenario undo      Imports such a backup of siteferry-dst with a safety backup (the default), then
                        undoes it by importing that safety backup. Does not touch siteferry-src. Checks that the safety backup
                        and the undo point exist after the first import, and that after the undo every
                        table but the options table (CHECKSUM TABLE) and every file in wp-content is
                        exactly as before, the marker content is gone and the undo point is cleared.
    -Scenario archive   Imports an existing backup (-Archive). Checks addresses and leftovers, and with
                        -SourceChecksums (a "table checksum" list of the source site) that every table
                        the search-replace did not change is identical to the source. -VerifyOnly runs
                        the checks against the latest import without importing again.

    Run each scenario only with the site owner's permission: it replaces siteferry-dst's content.
#>
param(
    [ValidateSet('site', 'rollback', 'undo', 'archive')] [string] $Scenario = 'site',
    [string] $Archive = '',
    [string] $SourceChecksums = '',
    [switch] $VerifyOnly,
    [switch] $Keep
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'lib\LocalSite.ps1')

$dst = Get-LocalSite 'siteferry-dst'
$src = Get-LocalSite 'siteferry-src'
$tool = Join-Path $root 'tests\tools\siteferry-tar.php'
$work = Join-Path $root ('tests\tmp\import-check-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
New-Item -ItemType Directory -Force $work | Out-Null
$results = New-Object System.Collections.Generic.List[object]
$noCron = "--exec=define('DISABLE_WP_CRON', true);"
if ($VerifyOnly -and $Scenario -ne 'archive') { throw '-VerifyOnly applies to the archive scenario only.' }

# Runs WP-CLI on a site; returns stdout lines. Throws on failure unless -AllowFail.
function Wp([pscustomobject] $Site, [string[]] $WpArgs, [switch] $AllowFail) {
    $ErrorActionPreference = 'Continue'
    # Windows PowerShell 5.1 drops double quotes inside arguments passed to programs unless escaped.
    if ($PSVersionTable.PSVersion.Major -lt 7) { $WpArgs = @($WpArgs | ForEach-Object { $_ -replace '"', '\"' }) }
    $errFile = Join-Path $work ('stderr-' + [guid]::NewGuid().ToString('N') + '.txt')
    $out = Invoke-SiteWp $Site @WpArgs 2> $errFile | ForEach-Object { "$_" }
    $script:lastCode = $LASTEXITCODE
    $script:lastErr = if (Test-Path $errFile) { (Get-Content $errFile -Raw); Remove-Item $errFile } else { '' }
    if ($script:lastCode -ne 0 -and -not $AllowFail) { throw ("wp " + ($WpArgs -join ' ') + " failed:`n" + ($out -join "`n") + "`n" + $script:lastErr) }
    return $out
}
function Check([string] $name, [scriptblock] $test) {
    try { & $test; $results.Add([pscustomobject]@{ Check = $name; Result = 'PASS'; Detail = '' }) }
    catch { $results.Add([pscustomobject]@{ Check = $name; Result = 'FAIL'; Detail = $_.Exception.Message.Trim() }) }
}
function Assert([bool] $condition, [string] $message) { if (-not $condition) { throw $message } }
function DbState([pscustomobject] $Site, [string] $Needle = '') {
    # Read-only and independent of the site's code: imported plugins may print to the output.
    $wpArgs = @($noCron, '--skip-plugins', '--skip-themes', 'eval-file', (Join-Path $root 'tests\wp\db-state.php'))
    if ($Needle) { $wpArgs += $Needle }
    return (Wp $Site $wpArgs | Select-Object -Last 1) | ConvertFrom-Json
}
function Tree([string] $dir, [string] $exclude) {
    return (& $dst.Php -n -d memory_limit=1G $tool tree $dir "--exclude=$exclude" | Out-String)
}
function Ensure-Plugin([pscustomobject] $Site) {
    $link = Join-Path $Site.Public 'wp-content\plugins\siteferry'
    if (-not (Test-Path $link)) { New-Item -ItemType Junction -Path $link -Target $root | Out-Null }
    Wp $Site @('plugin', 'is-active', 'siteferry') -AllowFail | Out-Null
    if ($script:lastCode -ne 0) { Wp $Site @('plugin', 'activate', 'siteferry') | Out-Null }
    # Create SiteFerry's storage folder and setting now, so before/after snapshots compare like with like.
    Wp $Site @('eval', 'SiteFerry\Security\Storage::root();') | Out-Null
}
function Export-Source {
    Ensure-Plugin $src
    # Marker content with the source address in several encodings.
    $url = $src.Url
    foreach ($id in (Wp $src @('post', 'list', '--post_type=post', '--title=SFTEST-IMPORT', '--field=ID', '--post_status=any'))) { Wp $src @('post', 'delete', $id, '--force') | Out-Null }
    $content = "<a href=`"$url/hello/`">link</a> <img src=`"$url/wp-content/uploads/x.jpg`"> {`"u`":`"" + ($url -replace '/', '\/') + "\/json`"}"
    $script:postId = (Wp $src @('post', 'create', '--post_type=post', '--post_status=publish', '--post_title=SFTEST-IMPORT', "--post_content=$content", '--porcelain') | Select-Object -Last 1).Trim()
    Wp $src @('post', 'meta', 'update', $script:postId, 'sftest_serialized', ('{"url":"' + $url + '/meta","list":["' + $url + '","keep"]}'), '--format=json') | Out-Null
    Wp $src @('option', 'update', 'sftest_option', ('{"nested":{"u":"' + $url + '/opt","n":5}}'), '--format=json') | Out-Null
    return (Wp $src @($noCron, 'siteferry', 'export', '--porcelain') | Select-Object -Last 1).Trim()
}

# A backup of siteferry-dst itself with marker content (written to the test folder, not to dst's
# backup list); the marker is removed again afterwards, so importing the backup brings it back.
# Leaves siteferry-src alone.
function Export-Self {
    $id = (Wp $dst @('post', 'create', '--post_type=post', '--post_status=publish', '--post_title=SFTEST-UNDO', '--post_content=undo marker', '--porcelain') | Select-Object -Last 1).Trim()
    Wp $dst @('option', 'update', 'sftest_undo', 'marker') | Out-Null
    $made = (Wp $dst @($noCron, 'siteferry', 'export', "--dir=$work", '--porcelain') | Select-Object -Last 1).Trim()
    Wp $dst @('post', 'delete', $id, '--force') | Out-Null
    Wp $dst @('option', 'delete', 'sftest_undo') | Out-Null
    return $made
}

try {
    Write-Host "Scenario: $Scenario; target: $($dst.Name) ($($dst.Url))"
    Ensure-Plugin $dst
    $before = DbState $dst

    if ($Scenario -eq 'site') {
        $file = Export-Source
        Write-Host "Exported $file"
        $srcState = DbState $src
        $srcUsers = (Wp $src @('user', 'list', '--fields=user_login,user_email,roles', '--format=csv')) -join "`n"
        $srcTree = Tree (Join-Path $src.Public 'wp-content\uploads') 'siteferry-*'

        $out = Wp $dst @($noCron, 'siteferry', 'import', $file, '--yes')
        Write-Host (($out | Select-Object -Last 3) -join "`n")
        $after = DbState $dst ($src.Url -replace '^https?://', '')

        Check 'site address is the destination address' {
            Assert ($after.home -eq $dst.Url -and $after.siteurl -eq $dst.Url) "home=$($after.home) siteurl=$($after.siteurl)"
        }
        Check 'no trace of the old address outside posts.guid' {
            Assert (@($after.hits.PSObject.Properties).Count -eq 0) ('still found in: ' + (($after.hits.PSObject.Properties | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join ', '))
        }
        Check 'post content, JSON-escaped URL, serialized post meta and option were rewritten' {
            $id = (Wp $dst @('post', 'list', '--post_type=post', '--title=SFTEST-IMPORT', '--field=ID')).Trim()
            $content = (Wp $dst @('post', 'get', $id, '--field=post_content')) -join "`n"
            Assert ($content.Contains("$($dst.Url)/hello/") -and $content.Contains(($dst.Url -replace '/', '\/') + '\/json')) "content: $content"
            $meta = (Wp $dst @('post', 'meta', 'get', $id, 'sftest_serialized', '--format=json')) -join '' | ConvertFrom-Json
            Assert ($meta.url -eq "$($dst.Url)/meta" -and $meta.list[0] -eq $dst.Url -and $meta.list[1] -eq 'keep') "meta: $($meta | ConvertTo-Json -Compress)"
            $opt = (Wp $dst @('option', 'get', 'sftest_option', '--format=json')) -join '' | ConvertFrom-Json
            Assert ($opt.nested.u -eq "$($dst.Url)/opt" -and $opt.nested.n -eq 5) "option: $($opt | ConvertTo-Json -Compress)"
        }
        Check 'serialized values stay valid' {
            Assert ($after.invalid_serialized -eq $srcState.invalid_serialized) "invalid serialized: source $($srcState.invalid_serialized), imported $($after.invalid_serialized)"
        }
        Check 'users are the source site''s users' {
            $dstUsers = (Wp $dst @('user', 'list', '--fields=user_login,user_email,roles', '--format=csv')) -join "`n"
            Assert ($dstUsers -eq $srcUsers) "users differ:`n$dstUsers"
        }
        Check 'uploads are identical to the source' {
            $dstTree = Tree (Join-Path $dst.Public 'wp-content\uploads') 'siteferry-*'
            Assert ($dstTree -eq $srcTree) 'uploads trees differ'
        }
        Check 'SiteFerry still active, its storage setting kept, no leftovers' {
            Wp $dst @('plugin', 'is-active', 'siteferry') -AllowFail | Out-Null
            Assert ($script:lastCode -eq 0) 'SiteFerry is not active after the import'
            Assert ($after.storage -eq $before.storage) "storage option changed: $($before.storage) -> $($after.storage)"
            Assert (@($after.import_tables).Count -eq 0) ('import tables left: ' + ($after.import_tables -join ', '))
            Assert (-not (Test-Path (Join-Path $dst.Public '.maintenance'))) 'maintenance file left behind'
            $jobs = Join-Path $dst.Public ('wp-content\' + $after.storage + '\jobs')
            $left = @(Get-ChildItem $jobs -Recurse -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -in 'staging', 'rollback' })
            Assert ($left.Count -eq 0) ('job folders left: ' + ($left.FullName -join ', '))
        }
        Check 'front page loads' {
            $r = Invoke-WebRequest -Uri ($dst.Url + '/') -UseBasicParsing -TimeoutSec 30
            Assert ($r.StatusCode -eq 200 -and $r.Content -notmatch 'critical error|Fatal error') "HTTP $($r.StatusCode)"
            Assert (-not $r.Content.Contains(($src.Url -replace '^https?://', ''))) 'old address appears in the page'
        }
        Wp $src @('post', 'delete', $script:postId, '--force') | Out-Null
        Wp $src @('option', 'delete', 'sftest_option') | Out-Null
    }

    if ($Scenario -eq 'rollback') {
        $file = Export-Self
        Write-Host "Exported $file"
        $before = DbState $dst
        $treeBefore = Tree (Join-Path $dst.Public 'wp-content') 'siteferry-*,plugins/siteferry'
        $out = Wp $dst @($noCron, "--exec=define('SITEFERRY_TEST_FAIL_AFTER_SWAP', true);", 'siteferry', 'import', $file, '--yes') -AllowFail
        $importCode = $script:lastCode
        $message = ($out -join "`n") + "`n" + $script:lastErr
        $after = DbState $dst
        $treeAfter = Tree (Join-Path $dst.Public 'wp-content') 'siteferry-*,plugins/siteferry'

        Check 'the import failed with the simulated error' {
            Assert ($importCode -ne 0 -and $message.Contains('Simulated failure after the swap')) "exit ${importCode}: $message"
            Assert ($message.Contains('left as it was before')) 'rollback not reported as complete'
        }
        Check 'the safety backup made first was removed (the site is as it was)' {
            $job = Get-ChildItem (Join-Path $dst.Public ('wp-content\' + $after.storage + '\jobs')) -Directory | Sort-Object LastWriteTime | Where-Object { (Get-Content (Join-Path $_.FullName 'job.json') -Raw | ConvertFrom-Json).type -eq 'import' } | Select-Object -Last 1
            $log = Get-Content (Join-Path $job.FullName 'job.log') -Raw
            Assert ($log.Contains('Safety backup made:') -and $log.Contains('Removed the safety backup')) 'safety backup not made and removed'
            $left = @(Get-ChildItem (Join-Path $dst.Public ('wp-content\' + $after.storage + '\backups')) -File | Where-Object { $log.Contains($_.Name) })
            Assert ($left.Count -eq 0) ('safety backup still there: ' + ($left.Name -join ', '))
        }
        Check 'every table is exactly as before (CHECKSUM TABLE)' {
            $diff = Compare-Object @($before.checksums.PSObject.Properties | ForEach-Object { "$($_.Name)=$($_.Value)" }) @($after.checksums.PSObject.Properties | ForEach-Object { "$($_.Name)=$($_.Value)" })
            Assert ($null -eq $diff) ('tables differ: ' + (($diff | ForEach-Object { $_.SideIndicator + $_.InputObject }) -join ', '))
        }
        Check 'every file in wp-content is exactly as before' {
            Assert ($treeAfter -eq $treeBefore) 'wp-content differs'
        }
        Check 'no import tables, staging, rollback folder or maintenance file left' {
            Assert (@($after.import_tables).Count -eq 0) ('import tables left: ' + ($after.import_tables -join ', '))
            Assert (-not (Test-Path (Join-Path $dst.Public '.maintenance'))) 'maintenance file left behind'
            $jobs = Join-Path $dst.Public ('wp-content\' + $after.storage + '\jobs')
            $left = @(Get-ChildItem $jobs -Recurse -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -in 'staging', 'rollback' })
            Assert ($left.Count -eq 0) ('job folders left: ' + ($left.FullName -join ', '))
        }
    }

    if ($Scenario -eq 'undo') {
        $file = Export-Self
        Write-Host "Exported $file"
        $before = DbState $dst
        $treeBefore = Tree (Join-Path $dst.Public 'wp-content') 'siteferry-*,plugins/siteferry'

        $out = Wp $dst @($noCron, 'siteferry', 'import', $file, '--yes')
        Write-Host (($out | Select-Object -Last 3) -join "`n")
        $safety = (($out -join "`n") | Select-String -Pattern 'Safety backup of the site as it was: (\S+)\.').Matches | ForEach-Object { $_.Groups[1].Value } | Select-Object -First 1
        $mid = DbState $dst
        $backups = Join-Path $dst.Public ('wp-content\' + $mid.storage + '\backups')

        Check 'the import made a safety backup first and recorded the undo point' {
            Assert ([bool] $safety) ('no safety backup reported: ' + ($out -join ' | '))
            Assert (Test-Path (Join-Path $backups $safety)) "missing: $safety"
            $undo = (Wp $dst @($noCron, 'option', 'get', 'siteferry_undo', '--format=json')) -join '' | ConvertFrom-Json
            Assert ($undo.safety -eq $safety -and $undo.from -eq $dst.Url -and $undo.to -eq $dst.Url) ('undo point: ' + ($undo | ConvertTo-Json -Compress))
            $label = (Wp $dst @($noCron, 'eval', "echo wp_json_encode( SiteFerry\Labels::get( '$safety' ) );")) -join '' | ConvertFrom-Json
            Assert ($label.tags -contains 'before-restore' -and $label.title.StartsWith('Before restoring')) ('label: ' + ($label | ConvertTo-Json -Compress))
        }
        Check 'the import brought the marker back' {
            $ids = (Wp $dst @('post', 'list', '--post_type=post', '--title=SFTEST-UNDO', '--field=ID', '--post_status=any')) -join ''
            Assert ($ids.Trim() -ne '') 'marker post missing after the import'
        }

        $out = Wp $dst @($noCron, 'siteferry', 'import', (Join-Path $backups $safety), '--yes', '--skip-safety-backup')
        Write-Host (($out | Select-Object -Last 2) -join "`n")
        $after = DbState $dst
        $treeAfter = Tree (Join-Path $dst.Public 'wp-content') 'siteferry-*,plugins/siteferry'
        $prefix = (Wp $dst @('config', 'get', 'table_prefix') | Select-Object -Last 1).Trim()

        Check 'after the undo every table but the options is exactly as before (CHECKSUM TABLE)' {
            $skip = $prefix + 'options'
            $diff = Compare-Object @($before.checksums.PSObject.Properties | Where-Object { $_.Name -ne $skip } | ForEach-Object { "$($_.Name)=$($_.Value)" }) @($after.checksums.PSObject.Properties | Where-Object { $_.Name -ne $skip } | ForEach-Object { "$($_.Name)=$($_.Value)" })
            Assert ($null -eq $diff) ('tables differ: ' + (($diff | ForEach-Object { $_.SideIndicator + $_.InputObject }) -join ', '))
        }
        Check 'after the undo every file in wp-content is exactly as before' {
            Assert ($treeAfter -eq $treeBefore) 'wp-content differs'
        }
        Check 'the marker content and option are gone, the address is the site''s, the undo point is cleared' {
            $ids = @(Wp $dst @('post', 'list', '--post_type=post', '--title=SFTEST-UNDO', '--field=ID', '--post_status=any'))
            Assert ($ids.Count -eq 0 -or ($ids -join '').Trim() -eq '') 'marker post still there'
            Wp $dst @($noCron, 'option', 'get', 'sftest_undo') -AllowFail | Out-Null
            Assert ($script:lastCode -ne 0) 'marker option still there'
            Assert ($after.home -eq $dst.Url) "home=$($after.home)"
            Wp $dst @($noCron, 'option', 'get', 'siteferry_undo') -AllowFail | Out-Null
            Assert ($script:lastCode -ne 0) 'undo point not cleared after an import without a safety backup'
            Assert (@($after.import_tables).Count -eq 0) ('import tables left: ' + ($after.import_tables -join ', '))
        }
        # The safety backup is test data now: remove it and its label.
        Remove-Item -LiteralPath (Join-Path $backups $safety) -Force -ErrorAction SilentlyContinue
        Wp $dst @($noCron, 'eval', "SiteFerry\Labels::set( '$safety', '', '', array() );") | Out-Null
    }

    if ($Scenario -eq 'archive') {
        if (-not (Test-Path $Archive)) { throw "Backup not found: $Archive" }
        if (-not $VerifyOnly) {
            $out = Wp $dst @($noCron, 'siteferry', 'import', $Archive, '--yes')
            Write-Host (($out | Select-Object -Last 4) -join "`n")
        }
        $manifest = (& $dst.Php -n $tool manifest $Archive | Out-String) | ConvertFrom-Json
        $after = DbState $dst ($manifest.site.home -replace '^https?://', '')
        $dstPrefix = (Wp $dst @('config', 'get', 'table_prefix') | Select-Object -Last 1).Trim()
        $job = Get-ChildItem (Join-Path $dst.Public ('wp-content\' + $after.storage + '\jobs')) -Directory | Sort-Object LastWriteTime | Select-Object -Last 1
        $jobState = Get-Content (Join-Path $job.FullName 'job.json') -Raw | ConvertFrom-Json

        Check 'site address is the destination address' {
            Assert ($after.home -eq $dst.Url -and $after.siteurl -eq $dst.Url) "home=$($after.home) siteurl=$($after.siteurl)"
        }
        Check 'no trace of the old address outside posts.guid' {
            Assert (@($after.hits.PSObject.Properties).Count -eq 0) ('still found in: ' + (($after.hits.PSObject.Properties | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join ', '))
        }
        if ($SourceChecksums) {
            Check 'tables the search-replace did not touch are identical to the source (CHECKSUM TABLE)' {
                $changed = @($jobState.state.replace.changed.PSObject.Properties.Name)
                $prefix = $jobState.state.inspect.prefix
                $source = @{}
                foreach ($line in Get-Content $SourceChecksums) { $p = $line -split ' '; if ($p.Count -eq 2) { $source[$p[0]] = $p[1] } }
                $compared = 0; $bad = @()
                foreach ($name in $source.Keys) {
                    $suffix = $name.Substring($prefix.Length)
                    if ($changed -contains $suffix) { continue }
                    $mine = $after.checksums.($dstPrefix + $suffix)
                    $compared++
                    if ($mine -ne $source[$name]) { $bad += "$dstPrefix$suffix" }
                }
                Write-Host "  compared $compared unchanged tables; $($changed.Count) tables had rows rewritten"
                Assert ($bad.Count -eq 0) ('differ: ' + ($bad -join ', '))
            }
        }
        Check 'SiteFerry still active, no leftovers' {
            Wp $dst @('plugin', 'is-active', 'siteferry') -AllowFail | Out-Null
            Assert ($script:lastCode -eq 0) 'SiteFerry is not active after the import'
            Assert (@($after.import_tables).Count -eq 0) ('import tables left: ' + ($after.import_tables -join ', '))
            Assert (-not (Test-Path (Join-Path $dst.Public '.maintenance'))) 'maintenance file left behind'
        }
        Check 'front page and login page load' {
            foreach ($path in '/', '/wp-login.php') {
                $r = Invoke-WebRequest -Uri ($dst.Url + $path) -UseBasicParsing -TimeoutSec 60
                Assert ($r.StatusCode -eq 200 -and $r.Content -notmatch 'critical error|Fatal error') "$path -> HTTP $($r.StatusCode)"
            }
        }
    }
}
finally {
    $results | Format-Table -AutoSize -Wrap | Out-String -Width 220 | Write-Host
    if (-not $Keep) { Remove-Item -LiteralPath ('\\?\' + $work) -Recurse -Force -ErrorAction SilentlyContinue }
}

$failed = @($results | Where-Object { $_.Result -eq 'FAIL' }).Count
Write-Host ("import check ({0}): {1}/{2} passed" -f $Scenario, ($results.Count - $failed), $results.Count)
exit ([int]($failed -gt 0))
