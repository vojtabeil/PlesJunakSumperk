<#
.SYNOPSIS
    Builds the release package for the hosting: dist/ples-<version>/ and dist/ples-<version>.zip.

.DESCRIPTION
    web/      upload this folder to the hosting (document root = web/www, or web/ itself thanks to .htaccess)
    install/  SQL files for phpMyAdmin and the deployment guide (do not upload)

    Left out on purpose: dev tools (app/Presentation/Dev, /dev/* routes), tests, sources of
    assets, source maps, Composer dev dependencies and every local config file.
    The package is verified by running it locally in production mode before zipping.

.PARAMETER SkipVerify
    Skips the local production-mode check (not recommended).
#>
param([switch]$SkipVerify)

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Assert-BuildTools

$commit = (Invoke-Native 'git' @('-C', $Root, 'rev-parse', '--short', 'HEAD')).Output.Trim()
$dirty = (Invoke-Native 'git' @('-C', $Root, 'status', '--porcelain')).Output.Trim() -ne ''
$version = (Get-Date -Format 'yyyyMMdd-HHmm') + "-$commit" + $(if ($dirty) { '-dirty' } else { '' })
$distDir = Join-Path $Root 'dist'
$outDir = Join-Path $distDir "ples-$version"
$web = Join-Path $outDir 'web'
$install = Join-Path $outDir 'install'

if ($dirty) { Write-Host 'Warning: uncommitted changes are included in the package.' -ForegroundColor Yellow }

Write-Step 'Building assets'
& (Join-Path $PSScriptRoot 'build.ps1')

Write-Step "Assembling $outDir"
if (Test-Path $outDir) { Remove-Item $outDir -Recurse -Force }
New-Item -ItemType Directory -Force $web, $install | Out-Null

Copy-Item (Join-Path $Root 'app') (Join-Path $web 'app') -Recurse
Remove-Item (Join-Path $web 'app\Presentation\Dev') -Recurse -Force

New-Item -ItemType Directory -Force (Join-Path $web 'config') | Out-Null
foreach ($file in @('common.neon', 'services.neon', 'local.neon.example')) {
    Copy-Item (Join-Path $Root "config\$file") (Join-Path $web "config\$file")
}

Copy-Item (Join-Path $Root 'www') (Join-Path $web 'www') -Recurse
Get-ChildItem (Join-Path $web 'www\build') -Filter '*.map' | Remove-Item -Force

foreach ($dir in @('var\temp', 'var\log')) {
    New-Item -ItemType Directory -Force (Join-Path $web $dir) | Out-Null
}

# Deny direct access to everything outside www/ (when the hosting serves web/ itself).
$deny = "Require all denied`n"
foreach ($dir in @('app', 'config', 'var', 'vendor')) {
    New-Item -ItemType Directory -Force (Join-Path $web $dir) | Out-Null
    Write-Utf8NoBom (Join-Path $web "$dir\.htaccess") $deny
}
Write-Utf8NoBom (Join-Path $web '.htaccess') @'
# Used when the document root is this folder (it cannot be set to www/).
# Everything is served from www/; app, config, var and vendor are never reachable.
<IfModule mod_rewrite.c>
	RewriteEngine On
	RewriteRule ^(app|config|var|vendor)(/|$) - [F,L]
	RewriteCond %{REQUEST_URI} !^/www/
	RewriteRule ^(.*)$ www/$1 [L]
</IfModule>
'@

Write-Step 'Installing PHP dependencies without dev packages'
Copy-Item (Join-Path $Root 'composer.json'), (Join-Path $Root 'composer.lock') $web
$r = Invoke-Native $PhpExe @('-c', $PhpIni, $ComposerPhar, 'install', '--no-dev', '--optimize-autoloader',
    '--no-interaction', '--no-progress', "--working-dir=$web")
if ($r.Code -ne 0) { throw "composer install failed:`n$($r.Output)" }
Remove-Item (Join-Path $web 'composer.json'), (Join-Path $web 'composer.lock')
Write-Utf8NoBom (Join-Path $web 'vendor\.htaccess') $deny
Write-Utf8NoBom (Join-Path $web 'VERSION') "$version`n"

foreach ($file in @('schema.sql', 'defaults.sql', 'hall.sql')) {
    Copy-Item (Join-Path $PSScriptRoot "db\$file") (Join-Path $install $file)
}
Copy-Item (Join-Path $Root 'docs\deploy.md') (Join-Path $install 'deploy.md')

# --- Checks of the package contents -------------------------------------------
Write-Step 'Checking package contents'
$forbidden = @('app\Presentation\Dev', 'config\local.neon', 'config\test.neon', 'vendor\phpunit', 'vendor\phpstan', 'tests', 'dev', 'assets', 'node_modules')
foreach ($path in $forbidden) {
    if (Test-Path (Join-Path $web $path)) { throw "Package must not contain $path" }
}
if (Get-ChildItem $web -Recurse -Filter '*.map') { throw 'Package must not contain source maps' }
Write-Ok 'No dev tools, tests, local config or dev dependencies'

if (-not $SkipVerify) {
    Write-Step 'Running the package locally in production mode'
    Test-ReleasePackage $web
}

Write-Step 'Creating ZIP'
$zip = Join-Path $distDir "ples-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }
$r = Invoke-Native 'tar.exe' @('-a', '-c', '-f', $zip, '-C', $distDir, "ples-$version")
if ($r.Code -ne 0) { throw "Creating ZIP failed: $($r.Output)" }
Write-Ok $zip
Write-Host ''
Write-Host "Done. Upload $web, follow $install\deploy.md." -ForegroundColor Green
