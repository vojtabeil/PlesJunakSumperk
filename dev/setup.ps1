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
    $enable = 'curl|fileinfo|intl|mbstring|mysqli|openssl|pdo_mysql|pdo_sqlite|sqlite3'
    $ini = $ini | ForEach-Object {
        if ($_ -match '^;\s*extension_dir\s*=\s*"ext"') { "extension_dir = `"$extDir`"" }
        elseif ($_ -match "^;extension=($enable)$") { $_.Substring(1) }
        elseif ($_ -match '^;date\.timezone\s*=') { 'date.timezone = Europe/Prague' }
        elseif ($_ -match '^;session\.save_path\s*=') { "session.save_path = `"$(ConvertTo-SlashPath $SessionDir)`"" }
        else { $_ }
    }
    Write-Utf8NoBom $PhpIni (($ini -join "`r`n") + "`r`n")

    $check = 'echo implode(chr(44), array_filter([''pdo_mysql'', ''mysqli'', ''mbstring'', ''intl'', ''openssl''], fn($e) => !extension_loaded($e)));'
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
Install-Adminer

$localConfig = Join-Path $Root 'config.local.php'
if (-not (Test-Path $localConfig)) {
    Copy-Item (Join-Path $Root 'config.example.php') $localConfig
    Write-Ok 'Created config.local.php'
}

Write-MyIni
$started = Start-Db
try {
    if (Test-DatabaseExists) {
        Write-Ok "Database '$DbName' already exists, keeping it (reset with init-db.cmd)"
    } else {
        Reset-AppDatabase
    }
} finally {
    if ($started) { Stop-Db }
}

Write-Host ''
Write-Host 'Done. Run start.cmd.' -ForegroundColor Green
