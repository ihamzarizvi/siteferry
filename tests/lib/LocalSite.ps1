# Helpers for running WP-CLI against a LocalWP site by name.
# Dot-source this file: . "$PSScriptRoot\lib\LocalSite.ps1"

$script:WpCliPhar = 'C:\Program Files (x86)\Local\resources\extraResources\bin\wp-cli\wp-cli.phar'

function Get-LocalSite {
    param([Parameter(Mandatory)] [string] $Name)

    $sites = Get-Content (Join-Path $env:APPDATA 'Local\sites.json') -Raw | ConvertFrom-Json
    foreach ($property in $sites.PSObject.Properties) {
        $site = $property.Value
        if ($site.name -ne $Name) { continue }

        $root = $site.path -replace '^~', $env:USERPROFILE
        $phpDir = Get-ChildItem (Join-Path $env:APPDATA 'Local\lightning-services') -Directory -Filter ('php-' + $site.services.php.version + '+*') |
            Sort-Object Name | Select-Object -Last 1
        if ($null -eq $phpDir) { throw "PHP $($site.services.php.version) for site '$Name' is not installed in Local." }

        $port = $null
        foreach ($server in 'nginx', 'apache') {
            if ($site.services.$server) { $port = @($site.services.$server.ports.HTTP)[0] }
        }

        return [pscustomobject]@{
            Name   = $Name
            Id     = $property.Name
            Root   = $root
            Public = Join-Path $root 'app\public'
            Php    = Join-Path $phpDir.FullName 'bin\win64\php.exe'
            Ini    = Join-Path $env:APPDATA ('Local\run\' + $property.Name + '\conf\php\php.ini')
            Url    = if ($port) { "http://localhost:$port" } else { 'http://' + $site.domain }
        }
    }
    throw "No Local site named '$Name'. Create it in Local first."
}

function Invoke-SiteWp {
    # Runs WP-CLI for a site; returns output lines and sets $LASTEXITCODE.
    param(
        [Parameter(Mandatory)] [pscustomobject] $Site,
        [Parameter(ValueFromRemainingArguments)] [string[]] $WpArgs
    )
    if (-not (Test-Path $Site.Ini)) { throw "No php.ini for '$($Site.Name)'. Start the site in Local once." }
    # Local's php.ini loads extensions that may be missing (imagick); keep their startup noise out of the output.
    & $Site.Php -c $Site.Ini -d display_startup_errors=0 $script:WpCliPhar "--path=$($Site.Public)" @WpArgs
}
