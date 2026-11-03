<#
.SYNOPSIS
    Runs WP-CLI against a LocalWP site by name, with that site's PHP build and php.ini.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tests\wp-cli.ps1 siteferry-src option get siteurl
#>
param(
    [Parameter(Mandatory, Position = 0)] [string] $Site,
    [Parameter(ValueFromRemainingArguments)] [string[]] $WpArgs
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'lib\LocalSite.ps1')
Invoke-SiteWp (Get-LocalSite $Site) @WpArgs
exit $LASTEXITCODE
