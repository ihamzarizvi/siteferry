<#
.SYNOPSIS
    Runs every "wp siteferry" command that has machine output on a LocalWP test site and checks it:
    valid JSON with the expected keys, --porcelain, --format=count, exit codes, and the hooks during
    one small export (tests\tools\cli-hooks-probe.php). Nothing is imported; the small backups it
    makes are deleted again.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-cli-json-check.ps1 -Site siteferry-dst
#>
param(
    [Parameter(Mandatory)] [string] $Site
)
$ErrorActionPreference = 'Stop'
$wp     = Join-Path $PSScriptRoot 'wp-cli.ps1'
$probe  = (Join-Path $PSScriptRoot 'tools\cli-hooks-probe.php') -replace '\\', '/'
$failed = 0
$passed = 0

function Run-Wp([string[]] $WpArgs) {
    $ErrorActionPreference = 'Continue'
    $out = & powershell -NoProfile -ExecutionPolicy Bypass -File $wp $Site @WpArgs 2>$null
    return @{ Code = $LASTEXITCODE; Text = (@($out) -join "`n") }
}
function Check([bool] $Ok, [string] $What) {
    if ($Ok) { $script:passed++; Write-Host "  ok    $What" } else { $script:failed++; Write-Host "  FAIL  $What" -ForegroundColor Red }
}
function Json([string] $Text) {
    try { return $Text | ConvertFrom-Json } catch { return $null }
}
function Has($Object, [string[]] $Keys) {
    if ($null -eq $Object) { return $false }
    foreach ($k in $Keys) { if (-not ($Object.PSObject.Properties.Name -contains $k)) { return $false } }
    return $true
}

Write-Host "WP-CLI JSON checks on $Site"

# Lists and reports.
$r = Run-Wp @('siteferry', 'backups', 'list', '--format=json'); $list = Json $r.Text
Check ($r.Code -eq 0 -and $null -ne $list) 'backups list --format=json is JSON'
$first = @($list)[0]
Check (Has $first @('name', 'size', 'bytes', 'created', 'kind', 'place', 'status', 'path')) 'backups list: shared fields (name, size, created, kind, place, status, path)'
Check ($first.size -is [long] -or $first.size -is [int]) 'backups list: size is bytes in JSON'
$r = Run-Wp @('siteferry', 'backups', 'list', '--format=count')
Check ($r.Code -eq 0 -and [int]$r.Text.Trim() -eq @($list).Count) "backups list --format=count = $(@($list).Count)"
$r = Run-Wp @('siteferry', 'activity', '--format=json'); Check ($r.Code -eq 0 -and $null -ne (Json $r.Text)) 'activity --format=json'
$r = Run-Wp @('siteferry', 'activity', '--format=count'); Check ($r.Code -eq 0 -and $r.Text.Trim() -match '^\d+$') 'activity --format=count'
$r = Run-Wp @('siteferry', 'space', '--format=json'); Check ($r.Code -eq 0 -and (Has (Json $r.Text) @('files', 'database'))) 'space --format=json'
$r = Run-Wp @('siteferry', 'schedule', 'show', '--format=json'); $s = Json $r.Text
Check ($r.Code -eq 0 -and (Has $s @('settings', 'next', 'last'))) 'schedule show --format=json'
Check (-not ($r.Text -match '"password"\s*:\s*"')) 'schedule show: no password value in the output'
$r = Run-Wp @('siteferry', 'staging', 'status', '--format=json'); Check ($r.Code -eq 0 -and (Has (Json $r.Text) @('record', 'blockers'))) 'staging status --format=json'
$r = Run-Wp @('siteferry', 'receive', '--status', '--json'); Check ($r.Code -eq 0 -and (Has (Json $r.Text) @('ok', 'state', 'warnings'))) 'receive --status --json'
if (@($list).Count -ge 2) {
    $r = Run-Wp @('siteferry', 'backups', 'compare', @($list)[0].name, @($list)[1].name, '--format=json')
    Check ($r.Code -eq 0 -and (Has (Json $r.Text) @('older', 'newer'))) 'backups compare --format=json'
}

# Errors: exit code 1, and JSON on STDOUT in --json mode.
$r = Run-Wp @('siteferry', 'backups', 'delete', 'no-such-backup-2026-01-01-000000000000.tar', '--yes', '--json'); $e = Json $r.Text
Check ($r.Code -eq 1 -and $null -ne $e -and $e.ok -eq $false -and $e.error) 'an error with --json: exit 1 and {"ok":false,"error":...}'
$r = Run-Wp @('siteferry', 'backups', 'delete', 'no-such-backup-2026-01-01-000000000000.tar', '--json')
Check ($r.Code -eq 1 -and (Json $r.Text).error -match '--yes') 'delete --json without --yes is refused'
$r = Run-Wp @('siteferry', 'storage', 'test', 'nosuch', '--json'); Check ($r.Code -eq 1 -and (Json $r.Text).ok -eq $false) 'storage test of an unknown storage: exit 1, ok false'

# One small export with the hooks probe, as JSON; another with --porcelain.
$probeLog = Join-Path $env:TEMP ('sf-probe-' + [guid]::NewGuid().ToString('N') + '.log')
$env:SITEFERRY_HOOKS_PROBE = $probeLog
$r = Run-Wp @("--require=$probe", 'siteferry', 'export', '--exclude=media,plugins,themes', '--json')
Remove-Item Env:SITEFERRY_HOOKS_PROBE
$x = Json $r.Text
Check ($r.Code -eq 0 -and (Has $x @('ok', 'name', 'path', 'size', 'created', 'files', 'bytes', 'format', 'encrypted', 'base', 'sent', 'warnings'))) 'export --json: one JSON object with the result fields'
$own = @()
if ($x) { $own += $x.name }
Check ($x.name -like 'probe-label-*') "siteferry_backup_name_label renamed it: $($x.name)"
$seen = if (Test-Path $probeLog) { Get-Content $probeLog } else { @() }
Check (($seen -contains 'started export') -and ($seen -contains "done $($x.name)")) 'siteferry_export_started and _done fired (a throwing callback did not stop the backup)'
Remove-Item $probeLog -ErrorAction SilentlyContinue
$m = Run-Wp @('eval', "echo wp_json_encode( SiteFerry\Backups::manifest( '$($x.path)' ) );")
$manifest = Json $m.Text
Check ($manifest.extra.'probe-test'.answer -eq 42 -and -not ($manifest.extra.PSObject.Properties.Name -contains 'Bad Key')) 'siteferry_manifest_extra stored under extra, bad keys dropped'
Check (@($manifest.files.exclusions.paths) -contains 'languages') 'siteferry_export_exclusions added "languages"'

$r = Run-Wp @('siteferry', 'export', '--exclude=media,plugins,themes', '--porcelain')
$path = $r.Text.Trim()
Check ($r.Code -eq 0 -and (Test-Path $path)) 'export --porcelain prints only the path'
if (Test-Path $path) { $own += Split-Path $path -Leaf }

$r = Run-Wp @('siteferry', 'backups', 'check', $own[0], '--json'); $c = Json $r.Text
Check ($r.Code -eq 0 -and (Has $c @('ok', 'name', 'place', 'where', 'status', 'summary')) -and $c.status -eq 'ok') 'backups check --json'
$r = Run-Wp @('siteferry', 'backups', 'check', $own[0], '--porcelain'); Check ($r.Code -eq 0 -and $r.Text.Trim() -eq 'ok') 'backups check --porcelain'

# Delete what this script made.
$r = Run-Wp (@('siteferry', 'backups', 'delete') + $own + @('--yes', '--json')); $d = Json $r.Text
Check ($r.Code -eq 0 -and @($d.deleted).Count -eq $own.Count) "backups delete --yes --json removed $($own.Count) test backups"

Write-Host "wp-cli-json-check: $passed passed, $failed failed"
exit [int]($failed -gt 0)
