<#
.SYNOPSIS
    Downloads and configures portable PHP, MariaDB and Adminer in .tools/ and prepares the database.

.DESCRIPTION
    Safe to run repeatedly: tools already at the right version are skipped, downloaded
    ZIPs are reused from the cache and an existing database is left untouched.
    Use init-db.ps1 to wipe and recreate the data.

.PARAMETER Force
    Re-extracts all tools (database data is kept).
#>
param([switch]$Force)

. "$PSScriptRoot\_common.ps1"

function Install-Php {
    $spec = $Versions.Php
    if (-not $Force -and (Test-ToolVersion $PhpDir $spec.Version)) {
        Write-Ok "PHP $($spec.Version) is already installed"
    } else {
        Write-Step "Installing PHP $($spec.Version)"
        $running = Get-PidProcess $WebPidFile 'php'
        if ($running) { Stop-Process -Id $running.Id -Force }
        Expand-ZipTo (Get-VerifiedDownload $spec) $PhpDir
        Set-ToolVersion $PhpDir $spec.Version
    }

    # php.ini is always regenerated so it matches the current project location.
    New-Item -ItemType Directory -Force $SessionDir | Out-Null
    $extDir = ConvertTo-SlashPath (Join-Path $PhpDir 'ext')
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-development')
    $enable = 'curl|fileinfo|gd|intl|mbstring|mysqli|openssl|pdo_mysql|pdo_sqlite|sodium|sqlite3|zip'
    $ini = $ini | ForEach-Object {
        if ($_ -match '^;\s*extension_dir\s*=\s*"ext"') { "extension_dir = `"$extDir`"" }
        elseif ($_ -match "^;extension=($enable)$") { $_.Substring(1) }
        elseif ($_ -match '^;date\.timezone\s*=') { 'date.timezone = Europe/Prague' }
        elseif ($_ -match '^;session\.save_path\s*=') { "session.save_path = `"$(ConvertTo-SlashPath $SessionDir)`"" }
        elseif ($_ -match '^;\s*sys_temp_dir\s*=') { "sys_temp_dir = `"$(ConvertTo-SlashPath $TmpDir)`"" }
        elseif ($_ -match '^;\s*upload_tmp_dir\s*=') { "upload_tmp_dir = `"$(ConvertTo-SlashPath $TmpDir)`"" }
        # Even plain mail() ends up in Mailpit, never on the internet.
        elseif ($_ -match '^SMTP\s*=') { 'SMTP = 127.0.0.1' }
        elseif ($_ -match '^smtp_port\s*=') { "smtp_port = $SmtpPort" }
        else { $_ }
    }
    Write-Utf8NoBom $PhpIni (($ini -join "`r`n") + "`r`n")

    $check = 'echo implode(chr(44), array_filter([''pdo_mysql'', ''mysqli'', ''mbstring'', ''intl'', ''openssl'', ''gd'', ''zip''], fn($e) => !extension_loaded($e)));'
    $r = Invoke-Native $PhpExe @('-c', $PhpIni, '-r', $check)
    if ($r.Code -ne 0) { throw "PHP does not run:`n$($r.Output)" }
    if ($r.Output.Trim()) { throw "PHP is missing extensions: $($r.Output)" }
    Write-Ok 'php.ini generated, extensions loaded'
}

function Install-MariaDb {
    $spec = $Versions.MariaDb
    if (-not $Force -and (Test-ToolVersion $MariaDbDir $spec.Version)) {
        Write-Ok "MariaDB $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing MariaDB $($spec.Version)"
    if (Test-Path $MariaAdmin) {
        Write-MyIni
        Stop-Db
    }
    Expand-ZipTo (Get-VerifiedDownload $spec) $MariaDbDir
    Set-ToolVersion $MariaDbDir $spec.Version
    # The test suite and debug symbols are not needed to run the server.
    foreach ($unused in @('mysql-test', 'sql-bench')) {
        $p = Join-Path $MariaDbDir $unused
        if (Test-Path $p) { Remove-Item $p -Recurse -Force }
    }
    Get-ChildItem $MariaDbDir -Recurse -Filter '*.pdb' | Remove-Item -Force
}

function Install-Mailpit {
    $spec = $Versions.Mailpit
    if (-not $Force -and (Test-ToolVersion $MailpitDir $spec.Version)) {
        Write-Ok "Mailpit $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing Mailpit $($spec.Version)"
    Stop-Mailpit
    Expand-ZipTo (Get-VerifiedDownload $spec) $MailpitDir
    Set-ToolVersion $MailpitDir $spec.Version
}

function Install-Composer {
    $spec = $Versions.Composer
    if (-not $Force -and (Test-ToolVersion $ComposerDir $spec.Version)) {
        Write-Ok "Composer $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing Composer $($spec.Version)"
    $file = Get-VerifiedDownload $spec
    New-Item -ItemType Directory -Force $ComposerDir | Out-Null
    Copy-Item $file $ComposerPhar -Force
    Set-ToolVersion $ComposerDir $spec.Version
}

function Install-Bun {
    $spec = $Versions.Bun
    $installed = if (Test-Path (Join-Path $BunDir '.version')) { (Get-Content (Join-Path $BunDir '.version') -Raw).Trim() } else { '' }
    if (-not $Force -and $installed -in @($spec.Version, $Versions.BunBaseline.Version)) {
        Write-Ok "Bun $installed is already installed"
        return
    }
    Write-Step "Installing Bun $($spec.Version)"
    Expand-ZipTo (Get-VerifiedDownload $spec) $BunDir
    if ((Invoke-Native $BunExe @('--version')).Code -ne 0) {
        # Typically "Illegal instruction" on CPUs without AVX2.
        Write-Info 'Regular build does not run on this CPU, using the baseline build'
        $spec = $Versions.BunBaseline
        Expand-ZipTo (Get-VerifiedDownload $spec) $BunDir
    }
    $r = Invoke-Native $BunExe @('--version')
    if ($r.Code -ne 0) { throw "Bun does not run:`n$($r.Output)" }
    Set-ToolVersion $BunDir $spec.Version
    Write-Ok "bun $($r.Output.Trim())"
}

function Install-TypeScript {
    $spec = $Versions.TypeScript
    if (-not $Force -and (Test-ToolVersion $TsDir $spec.Version)) {
        Write-Ok "TypeScript $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing TypeScript $($spec.Version) (native)"
    Expand-ZipTo (Get-VerifiedDownload $spec) $TsDir
    $r = Invoke-Native $TscExe @('--version')
    if ($r.Code -ne 0) { throw "tsc does not run:`n$($r.Output)" }
    Set-ToolVersion $TsDir $spec.Version
    Write-Ok $r.Output.Trim()
}

function Install-Sass {
    $spec = $Versions.Sass
    if (-not $Force -and (Test-ToolVersion $SassDir $spec.Version)) {
        Write-Ok "Dart Sass $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing Dart Sass $($spec.Version)"
    Expand-ZipTo (Get-VerifiedDownload $spec) $SassDir
    $r = Invoke-Native $DartExe @($SassSnapshot, '--version')
    if ($r.Code -ne 0) { throw "sass does not run:`n$($r.Output)" }
    Set-ToolVersion $SassDir $spec.Version
    Write-Ok "sass $($r.Output.Trim())"
}

# Installs project dependencies once their manifests exist (added in phase 2).
function Install-Dependencies {
    if (Test-Path (Join-Path $Root 'composer.json')) {
        Write-Step 'Installing PHP dependencies (composer install)'
        Push-Location $Root
        try {
            $r = Invoke-Native $PhpExe @('-c', $PhpIni, $ComposerPhar, 'install', '--no-interaction', '--no-progress')
            if ($r.Code -ne 0) { throw "composer install failed:`n$($r.Output)" }
        } finally { Pop-Location }
        Write-Ok 'PHP dependencies installed'
    }
    if (Test-Path (Join-Path $Root 'package.json')) {
        Write-Step 'Installing frontend dependencies (bun install)'
        Push-Location $Root
        try {
            $r = Invoke-Native $BunExe @('install', '--frozen-lockfile')
            if ($r.Code -ne 0) { throw "bun install failed:`n$($r.Output)" }
        } finally { Pop-Location }
        Write-Ok 'Frontend dependencies installed'
    }
}

function Install-Adminer {
    $spec = $Versions.Adminer
    if (-not $Force -and (Test-ToolVersion $AdminerDir $spec.Version)) {
        Write-Ok "Adminer $($spec.Version) is already installed"
        return
    }
    Write-Step "Installing Adminer $($spec.Version)"
    $file = Get-VerifiedDownload $spec
    New-Item -ItemType Directory -Force $AdminerDir | Out-Null
    Copy-Item $file (Join-Path $AdminerDir 'adminer.php') -Force
    Set-ToolVersion $AdminerDir $spec.Version
}

Install-Php
Install-MariaDb
Install-Mailpit
Install-Adminer
Install-Composer
Install-Bun
Install-TypeScript
Install-Sass
Install-Dependencies

$localConfig = Join-Path $Root 'config\local.neon'
if (-not (Test-Path $localConfig)) {
    Copy-Item (Join-Path $Root 'config\local.neon.example') $localConfig
    Write-Ok 'Created config/local.neon'
}
# Nette cache and logs.
New-Item -ItemType Directory -Force (Join-Path $Root 'var\temp'), (Join-Path $Root 'var\log') | Out-Null

Write-MyIni
$started = Start-Db
try {
    if (Test-DatabaseExists $DbName) {
        Write-Ok "Database '$DbName' already exists, keeping it (reset with init-db.cmd)"
    } else {
        Reset-AppDatabase
    }
    if (-not (Test-DatabaseExists $TestDbName)) {
        Reset-TestDatabase
    }
} finally {
    if ($started) { Stop-Db }
}

Write-Host ''
Write-Host 'Done. Run start.cmd.' -ForegroundColor Green
