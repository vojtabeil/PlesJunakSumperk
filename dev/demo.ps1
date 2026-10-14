<#
.SYNOPSIS
    Static demo of the reservation page for a customer: dist/ples-ukazka-<time>/ and a ZIP to upload to any
    static web (FTP), into the root or any folder.

.DESCRIPTION
    The pages are rendered from the real templates and the local database (bin/export-demo.php): the hall
    plan, prices and event info as they are set locally. The seat picker runs on the in-browser demo API
    (assets/ts/front-demo.tsx): about a third of the seats is shown as sold, nothing is sent anywhere, and
    "Zavazne rezervovat" opens a sample "Moje rezervace" page (payment QR code to a made-up account).
    The export changes nothing in the local database (it runs in a transaction that is rolled back).
    MariaDB is started when it does not run.
#>

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Assert-BuildTools
Write-MyIni
Start-Db | Out-Null

$version = Get-Date -Format 'yyyyMMdd-HHmm'
$distDir = Join-Path $Root 'dist'
$outDir = Join-Path $distDir "ples-ukazka-$version"
$zip = "$outDir.zip"

Write-Step 'Building assets'
& (Join-Path $PSScriptRoot 'build.ps1')

if (Test-Path $outDir) { Remove-Item $outDir -Recurse -Force }
New-Item -ItemType Directory -Force (Join-Path $outDir 'build') | Out-Null

Push-Location $SrcDir
try {
    Write-Step 'Bundling the demo script'
    $r = Invoke-Native $BunExe @('build', 'assets/ts/front-demo.tsx', '--outdir', (Join-Path $outDir 'build'),
        '--target', 'browser', '--format', 'esm', '--minify')
    if ($r.Code -ne 0) { throw "bun build failed:`n$($r.Output)" }

    Write-Step 'Rendering the pages'
    $r = Invoke-Native $PhpExe @('-c', $PhpIni, 'bin/export-demo.php', $outDir)
    if ($r.Code -ne 0) { throw "Export failed:`n$($r.Output)" }
    Write-Ok $r.Output.Trim()
} finally {
    Pop-Location
}

Write-Step 'Copying styles, fonts and images'
$www = Join-Path $SrcDir 'www'
Copy-Item (Join-Path $www 'build\front.css') (Join-Path $outDir 'build\front.css')
Copy-Item (Join-Path $www 'fonts') (Join-Path $outDir 'fonts') -Recurse
Copy-Item (Join-Path $www 'img') (Join-Path $outDir 'img') -Recurse

Write-Step 'Checking the pages'
$problems = @()
foreach ($page in Get-ChildItem $outDir -Filter '*.html') {
    $html = [IO.File]::ReadAllText($page.FullName, [Text.Encoding]::UTF8)
    foreach ($m in [regex]::Matches($html, '(?:href|src)="([^"#]+)"')) {
        $url = $m.Groups[1].Value
        if ($url -match '^(https?:|mailto:|tel:|data:)') { continue }
        $path = ($url -split '\?')[0]
        if ($path.StartsWith('/')) { $problems += "$($page.Name): absolute link $url" }
        elseif (-not (Test-Path (Join-Path $outDir $path))) { $problems += "$($page.Name): missing $path" }
    }
    foreach ($bad in @('/api/', '__op__', 'tracy', 'demo.local', 'build/front.js')) {
        if ($html.Contains($bad)) { $problems += "$($page.Name): contains $bad" }
    }
}
$css = [IO.File]::ReadAllText((Join-Path $outDir 'build\front.css'))
foreach ($m in [regex]::Matches($css, 'url\([''"]?([^)''"]+)')) {
    $path = ($m.Groups[1].Value -split '[?#]')[0]
    if ($path -notmatch '^data:' -and -not (Test-Path (Join-Path (Join-Path $outDir 'build') $path))) {
        $problems += "front.css: missing $path"
    }
}
if ($problems) { throw "The demo is broken:`n  $($problems -join "`n  ")" }
Write-Ok 'All links work, no server calls'

Write-Step 'Packing'
if (Test-Path $zip) { Remove-Item $zip -Force }
Compress-Archive -Path (Join-Path $outDir '*') -DestinationPath $zip
Write-Ok $zip

Write-Host ''
Write-Host "Folder: $outDir"
Write-Host "ZIP:    $zip"
Write-Host 'Upload the content of the ZIP to any folder of a static web (FTP). Open index.html.'
