<#
.SYNOPSIS
    Builds the portable version for internal testing: dist/ples-portable-<version>/ and a ZIP.

.DESCRIPTION
    The package runs on any 64-bit Windows 10/11 without installation, admin rights or internet:
    unzip anywhere and run Start.cmd (this computer) or Start-LAN.cmd (the local network too).
    It contains the web with the dev tools (debug mode with Tracy on localhost, /dev/bank, /dev/status),
    PHP, MariaDB, Mailpit and Adminer from .tools/ (only the parts the site needs) and the sample data
    (schema + defaults + hall plan + seed.sql: public sale, account admin / admin), loaded on the
    first start. The runtime scripts are in dev/portable/ (run.ps1 generates php.ini, my.ini and
    local.neon on every start, so the folder can be moved).

    Before it is finished, the ZIP is unpacked into a folder with a space and diacritics in its path,
    started, checked over HTTP and stopped again.

.PARAMETER SkipVerify
    Skips the trial run of the unpacked ZIP (not recommended).
#>
param([switch]$SkipVerify)

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Assert-BuildTools

$commit = (Invoke-Native 'git' @('-C', $Root, 'rev-parse', '--short', 'HEAD')).Output.Trim()
$dirty = (Invoke-Native 'git' @('-C', $Root, 'status', '--porcelain')).Output.Trim() -ne ''
$version = (Get-Date -Format 'yyyyMMdd-HHmm') + "-$commit" + $(if ($dirty) { '-dirty' } else { '' })
$distDir = Join-Path $Root 'dist'
$outDir = Join-Path $distDir "ples-portable-$version"
$templates = Join-Path $PSScriptRoot 'portable'

if ($dirty) { Write-Host 'Warning: uncommitted changes are included in the package.' -ForegroundColor Yellow }

Write-Step 'Building assets'
& (Join-Path $PSScriptRoot 'build.ps1')

Write-Step "Assembling $outDir"
if (Test-Path $outDir) { Remove-Item $outDir -Recurse -Force }
$web = Join-Path $outDir 'web'
Copy-AppPackage $web -WithDevTools
# Used by run.ps1 to load the hall plan; create-admin.php for a new account from the command line.
New-Item -ItemType Directory -Force (Join-Path $web 'bin') | Out-Null
foreach ($file in @('import-hall.php', 'create-admin.php')) {
    Copy-Item (Join-Path $SrcDir "bin\$file") (Join-Path $web "bin\$file")
}

$sample = Join-Path $outDir 'sample'
New-Item -ItemType Directory -Force $sample | Out-Null
foreach ($file in @('schema.sql', 'defaults.sql', 'hall-plan.svg', 'seed.sql')) {
    Copy-Item (Join-Path $DbFilesDir $file) (Join-Path $sample $file)
}

$scripts = Join-Path $outDir 'scripts'
New-Item -ItemType Directory -Force $scripts | Out-Null
foreach ($file in @('run.ps1', 'router.php', 'local.neon')) {
    Copy-Item (Join-Path $templates $file) (Join-Path $scripts $file)
}
foreach ($file in @('Start.cmd', 'Start-LAN.cmd', 'Allow-LAN.cmd', 'Stop.cmd', 'Reset-data.cmd', 'CTI-ME.txt')) {
    Copy-Item (Join-Path $templates $file) (Join-Path $outDir $file)
}
Write-Utf8NoBom (Join-Path $outDir 'version.txt') "$version`r`n"

Write-Step 'Copying PHP, MariaDB, Mailpit and Adminer (only what the site needs)'
$runtime = Join-Path $outDir 'runtime'

# PHP: the CLI, the extensions enabled by run.ps1 and the libraries they load.
$php = Join-Path $runtime 'php'
New-Item -ItemType Directory -Force (Join-Path $php 'ext') | Out-Null
$unusedLibs = @('glib-2.dll', 'gmodule-2.dll', 'gobject-2.dll', 'libenchant2.dll', 'libpq.dll', 'libsasl.dll', 'libsqlite3.dll', 'php8phpdbg.dll')
Get-ChildItem $PhpDir -Filter '*.dll' | Where-Object { $unusedLibs -notcontains $_.Name } | Copy-Item -Destination $php
foreach ($file in @('php.exe', 'php.ini-development', 'license.txt')) {
    Copy-Item (Join-Path $PhpDir $file) (Join-Path $php $file)
}
Copy-Item (Join-Path $PhpDir 'extras') (Join-Path $php 'extras') -Recurse
Get-ChildItem (Join-Path $PhpDir 'ext') -Filter '*.dll' |
    Where-Object { $_.BaseName -match '^php_(curl|fileinfo|gd|intl|mbstring|mysqli|openssl|pdo_mysql|sodium|zip)$' } |
    Copy-Item -Destination (Join-Path $php 'ext')
# PHP needs the Visual C++ runtime, which a clean Windows may lack; MariaDB ships the same libraries.
foreach ($file in @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll')) {
    Copy-Item (Join-Path $MariaDbDir "bin\$file") (Join-Path $php $file)
}

# MariaDB: the server, the client, mariadb-admin, mariadb-install-db and their libraries
# (mariadb-install-db runs the server as mysqld.exe).
$maria = Join-Path $runtime 'mariadb'
New-Item -ItemType Directory -Force (Join-Path $maria 'bin') | Out-Null
Get-ChildItem $MariaDbDir -File | Where-Object { $_.Name -ne '.version' } | Copy-Item -Destination $maria
Copy-Item (Join-Path $MariaDbDir 'share') (Join-Path $maria 'share') -Recurse
Get-ChildItem (Join-Path $MariaDbDir 'bin') -Filter '*.dll' |
    Copy-Item -Destination (Join-Path $maria 'bin')
foreach ($file in @('mariadbd.exe', 'mysqld.exe', 'mariadb.exe', 'mariadb-admin.exe', 'mariadb-install-db.exe')) {
    Copy-Item (Join-Path $MariaDbDir "bin\$file") (Join-Path $maria "bin\$file")
}

New-Item -ItemType Directory -Force (Join-Path $runtime 'mailpit'), (Join-Path $runtime 'adminer') | Out-Null
foreach ($file in @('mailpit.exe', 'LICENSE')) {
    Copy-Item (Join-Path $MailpitDir $file) (Join-Path $runtime "mailpit\$file")
}
Copy-Item (Join-Path $AdminerDir 'adminer.php') (Join-Path $runtime 'adminer\adminer.php')

# --- Checks of the package contents -------------------------------------------
Write-Step 'Checking package contents'
foreach ($path in @('web\config\local.neon', 'web\config\test.neon', 'web\vendor\phpunit', 'web\vendor\phpstan', 'web\tests',
        'web\assets', 'web\node_modules', 'data', 'runtime\php\php.ini')) {
    if (Test-Path (Join-Path $outDir $path)) { throw "Package must not contain $path" }
}
foreach ($path in @('web\app\Presentation\Dev', 'web\vendor\autoload.php', 'web\www\build\front.js', 'web\www\build\front.css')) {
    if (-not (Test-Path (Join-Path $outDir $path))) { throw "Package is missing $path" }
}
foreach ($file in Get-ChildItem $outDir -Recurse -Include '*.ps1', '*.cmd') {
    if ([IO.File]::ReadAllBytes($file.FullName) | Where-Object { $_ -gt 127 }) { throw "$($file.Name) must be ASCII-only" }
}
Write-Ok 'Dev tools included; no tests, local config, data or dev dependencies'

$zip = New-DistZip $outDir
Write-Ok ('{0} ({1:N0} MB)' -f $zip, ((Get-Item $zip).Length / 1MB))

# --- Trial run of the unpacked ZIP -------------------------------------------------
if (-not $SkipVerify) {
    # A path with a space and Czech diacritics (c-caron, s-caron, r-caron).
    $tryRoot = Join-Path $TmpDir ("portable try " + [char]0x010D + [char]0x0161 + [char]0x0159)
    if (Test-Path $tryRoot) { Remove-Item $tryRoot -Recurse -Force }
    New-Item -ItemType Directory -Force $tryRoot | Out-Null
    Write-Step "Trying the ZIP in $tryRoot"
    # tar.exe cannot take such a path (ANSI command line); .NET unpacks like Windows Explorer.
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [IO.Compression.ZipFile]::ExtractToDirectory($zip, $tryRoot)
    $try = Join-Path $tryRoot (Split-Path -Leaf $outDir)
    $run = Join-Path $try 'scripts\run.ps1'

    $failed = @()
    try {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $run start -NoBrowser
        if ($LASTEXITCODE -ne 0) { throw 'The portable version did not start.' }
        $ports = Get-Content (Join-Path $try 'data\run\ports.json') -Raw | ConvertFrom-Json
        $base = "http://127.0.0.1:$($ports.Web)"

        Write-Info 'Extensions of the bundled PHP'
        $check = 'echo implode(chr(44), array_filter([''pdo_mysql'', ''mysqli'', ''mbstring'', ''intl'', ''openssl'', ''gd'', ''zip'', ''curl'', ''sodium'', ''fileinfo''], fn($e) => !extension_loaded($e)));'
        # Like run.ps1: from the package folder with relative paths (php.exe cannot take diacritics).
        Push-Location $try
        try { $r = Invoke-Native (Join-Path $try 'runtime\php\php.exe') @('-c', 'data\php.ini', '-r', $check) } finally { Pop-Location }
        if ($r.Code -ne 0 -or $r.Output.Trim()) { $failed += "PHP extensions: $($r.Output.Trim())" } else { Write-Ok 'All loaded' }

        Write-Info 'Public sale with the sample data, debug mode with the dev tools'
        $failed += Invoke-HttpChecks $base @(
            @{ Path = '/'; Status = 200; Expect = 'seat-picker-data' },
            @{ Path = '/'; Status = 200; Expect = 'tracy-debug' },
            @{ Path = '/api/state'; Status = 200; Expect = '"ok":true' },
            @{ Path = '/plan-salu.svg'; Status = 200; Expect = 'seat-' },
            @{ Path = '/build/front.js'; Status = 200 },
            @{ Path = '/admin/'; Status = 302; Expect = '/admin/sign/in' },
            @{ Path = '/admin/sign/in'; Status = 200; Expect = 'type="password"' },
            @{ Path = '/dev/status'; Status = 200; Expect = 'seat_bookings' },
            @{ Path = '/dev/bank'; Status = 200 },
            @{ Path = '/adminer'; Status = 200; Expect = 'Adminer' },
            @{ Path = '/neexistuje'; Status = 404 }
        )
        $failed += Invoke-HttpChecks "http://127.0.0.1:$($ports.Mail)" @(@{ Path = '/'; Status = 200; Expect = 'Mailpit' })

        Write-Info 'Second start keeps the running instance'
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $run start -NoBrowser | Out-Null
        if ($LASTEXITCODE -ne 0) { $failed += 'second start' }
        $again = Get-Content (Join-Path $try 'data\run\ports.json') -Raw | ConvertFrom-Json
        if ($again.Web -ne $ports.Web -or $again.Db -ne $ports.Db) { $failed += 'second start changed the ports' } else { Write-Ok 'Same ports' }
    } finally {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $run stop
        if ($LASTEXITCODE -ne 0) { $failed += 'stop' }
    }
    $left = Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path.StartsWith($try, [StringComparison]::OrdinalIgnoreCase) }
    if ($left) {
        $failed += "still running after stop: $(($left | ForEach-Object { $_.ProcessName }) -join ', ')"
        $left | Stop-Process -Force
    }
    if ($failed) {
        Remove-Item $zip -Force
        throw "The portable version is broken (the ZIP was deleted, the trial run stays in $tryRoot):`n  $($failed -join "`n  ")"
    }
    Remove-Item $tryRoot -Recurse -Force
    Write-Ok 'Started, checked and stopped'
}

Write-Host ''
Write-Host "Folder: $outDir"
Write-Host "ZIP:    $zip"
Write-Host 'Unpack anywhere on Windows 10/11 and run Start.cmd (or Start-LAN.cmd); see CTI-ME.txt.' -ForegroundColor Green
