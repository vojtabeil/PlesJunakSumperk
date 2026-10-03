# Shared configuration and helpers for the local development environment.
# Dot-sourced by the scripts in dev/ (. "$PSScriptRoot\_common.ps1").
# Keep this file ASCII-only: Windows PowerShell 5.1 reads BOM-less files as ANSI.

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# --- Versions and checksums (update the SHA256 together with the version) ----
$Versions = @{
    Php = @{
        Version = '8.4.26'
        File    = 'php-8.4.26-nts-Win32-vs17-x64.zip'
        Sha256  = 'da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7'
        Urls    = @(
            'https://windows.php.net/downloads/releases/php-8.4.26-nts-Win32-vs17-x64.zip',
            'https://windows.php.net/downloads/releases/archives/php-8.4.26-nts-Win32-vs17-x64.zip'
        )
    }
    MariaDb = @{
        Version = '11.8.9'
        File    = 'mariadb-11.8.9-winx64.zip'
        Sha256  = '830c46727d9278eae212ae3eca44eeb9e71b2a68704e95f344a64fba7b1963f5'
        Urls    = @(
            'https://archive.mariadb.org/mariadb-11.8.9/winx64-packages/mariadb-11.8.9-winx64.zip',
            'https://downloads.mariadb.org/rest-api/mariadb/11.8.9/mariadb-11.8.9-winx64.zip'
        )
    }
    Mailpit = @{
        Version = '1.31.4'
        File    = 'mailpit-1.31.4-windows-amd64.zip'
        Sha256  = '0dd6ec909a6f5b2b66682b22d82abce97ab793e30766bf2f642b691e70a4a16b'
        Urls    = @('https://github.com/axllent/mailpit/releases/download/v1.31.4/mailpit-windows-amd64.zip')
    }
    Composer = @{
        Version = '2.10.3'
        File    = 'composer-2.10.3.phar'
        Sha256  = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
        Urls    = @('https://getcomposer.org/download/2.10.3/composer.phar')
    }
    # The regular build needs a CPU with AVX2; setup falls back to the baseline build otherwise.
    Bun = @{
        Version = '1.4.2'
        File    = 'bun-1.4.2-windows-x64.zip'
        Sha256  = 'ce4c17497b2f29712a99d3d53f028de28cd42e3bacb8589599e7f000e49b6405'
        Urls    = @('https://github.com/oven-sh/bun/releases/download/bun-v1.4.2/bun-windows-x64.zip')
    }
    BunBaseline = @{
        Version = '1.4.2-baseline'
        File    = 'bun-1.4.2-windows-x64-baseline.zip'
        Sha256  = '78c221c2376f79731ccf4e4af0b3bb46d81fefa3296c5abee09ad8a1b21e68c6'
        Urls    = @('https://github.com/oven-sh/bun/releases/download/bun-v1.4.2/bun-windows-x64-baseline.zip')
    }
    TypeScript = @{
        Version = '7.0.2'
        File    = 'typescript-7.0.2-win32-x64.tgz'
        Sha256  = '61fc4e141d2bc687db580e71bbfa63b9c209f0310645d82ca1b457eb3a24fd19'
        Urls    = @('https://github.com/microsoft/typescript-go/releases/download/typescript/v7.0.2/typescript-win32-x64.tgz')
    }
    Sass = @{
        Version = '1.105.1'
        File    = 'dart-sass-1.105.1-windows-x64.zip'
        Sha256  = '3f76ae65dd7b494cc25cd2257f3be608e074b2d33b7328d9ba565bd60ba54f7e'
        Urls    = @('https://github.com/sass/dart-sass/releases/download/1.105.1/dart-sass-1.105.1-windows-x64.zip')
    }
    Adminer = @{
        Version = '6.1.1'
        File    = 'adminer-6.1.1-mysql.php'
        Sha256  = '2d092e713717c8106ae276ca5780b77970d3378817a792c6bc584651d4039cc6'
        Urls    = @('https://github.com/vrana/adminer/releases/download/v6.1.1/adminer-6.1.1-mysql.php')
    }
}

# --- Paths and ports -----------------------------------------------------------
$Root       = Split-Path -Parent $PSScriptRoot
$ToolsDir   = Join-Path $Root '.tools'
$CacheDir   = Join-Path $ToolsDir '_downloads'
$PhpDir     = Join-Path $ToolsDir 'php'
$MariaDbDir = Join-Path $ToolsDir 'mariadb'
$AdminerDir = Join-Path $ToolsDir 'adminer'
$MailpitDir = Join-Path $ToolsDir 'mailpit'
$ComposerDir = Join-Path $ToolsDir 'composer'
$BunDir     = Join-Path $ToolsDir 'bun'
$TsDir      = Join-Path $ToolsDir 'typescript'
$SassDir    = Join-Path $ToolsDir 'sass'

$DataDir    = Join-Path $Root '.devdata'
$DbDataDir  = Join-Path $DataDir 'mariadb'
$MailDataDir = Join-Path $DataDir 'mailpit'
$LogDir     = Join-Path $DataDir 'logs'
$RunDir     = Join-Path $DataDir 'run'
$SessionDir = Join-Path $DataDir 'sessions'
$TmpDir     = Join-Path $DataDir 'tmp'
$MyIni      = Join-Path $DataDir 'my.ini'

$PhpExe     = Join-Path $PhpDir 'php.exe'
$PhpIni     = Join-Path $PhpDir 'php.ini'
$MariaDbd   = Join-Path $MariaDbDir 'bin\mariadbd.exe'
$MariaDbCli = Join-Path $MariaDbDir 'bin\mariadb.exe'
$MariaAdmin = Join-Path $MariaDbDir 'bin\mariadb-admin.exe'
$MariaInst  = Join-Path $MariaDbDir 'bin\mariadb-install-db.exe'
$MailpitExe = Join-Path $MailpitDir 'mailpit.exe'
$ComposerPhar = Join-Path $ComposerDir 'composer.phar'
$BunExe     = Join-Path $BunDir 'bun.exe'
$TscExe     = Join-Path $TsDir 'lib\tsc.exe'
$DartExe    = Join-Path $SassDir 'src\dart.exe'
$SassSnapshot = Join-Path $SassDir 'src\sass.snapshot'

$DbPort   = 3307
$WebPort  = 8000
$SmtpPort = 1025
$MailPort = 8025
$DbName  = 'ples'
$DbUser  = 'ples'
$DbPass  = 'ples'

$DbPidFile  = Join-Path $RunDir 'mariadb.pid'
$WebPidFile = Join-Path $RunDir 'php.pid'
$MailPidFile = Join-Path $RunDir 'mailpit.pid'

# Environment shared with the root *.cmd wrappers (caches and temp files inside .devdata).
# Every process started from these scripts inherits it.
foreach ($line in Get-Content (Join-Path $PSScriptRoot 'env.cmd')) {
    if ($line -match '^@set "(\w+)=(.*)"$' -and $Matches[1] -ne 'ROOT') {
        Set-Item "env:$($Matches[1])" ($Matches[2] -replace '%ROOT%', $Root)
    }
}
New-Item -ItemType Directory -Force $TmpDir | Out-Null

# --- Output --------------------------------------------------------------------
function Write-Step([string]$Text) { Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text)   { Write-Host "    $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "    $Text" }

# Forward-slash path (MariaDB prefers it in my.ini and in the SOURCE command).
function ConvertTo-SlashPath([string]$Path) { $Path -replace '\\', '/' }

function Write-Utf8NoBom([string]$Path, [string]$Content) {
    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding $false))
}

# Runs a native program without letting stderr output abort the script in PS 5.1.
# Note: PS 5.1 strips embedded double quotes from native arguments - avoid them.
function Invoke-Native([string]$Exe, [string[]]$ArgList) {
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $out = & $Exe @ArgList 2>&1 | ForEach-Object { "$_" }
        return [pscustomobject]@{ Code = $LASTEXITCODE; Output = ($out -join "`n") }
    } finally {
        $ErrorActionPreference = $old
    }
}

function Test-PortInUse([int]$Port) {
    $client = New-Object Net.Sockets.TcpClient
    try {
        $client.Connect('127.0.0.1', $Port)
        return $true
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Get-PidProcess([string]$PidFile, [string]$Name) {
    if (-not (Test-Path $PidFile)) { return $null }
    $id = [int](Get-Content $PidFile -Raw).Trim()
    $proc = Get-Process -Id $id -ErrorAction SilentlyContinue
    if ($proc -and $proc.ProcessName -eq $Name) { return $proc }
    Remove-Item $PidFile -Force
    return $null
}

# --- Downloading and installing tools ------------------------------------------
function Get-VerifiedDownload([hashtable]$Spec) {
    New-Item -ItemType Directory -Force $CacheDir | Out-Null
    $target = Join-Path $CacheDir $Spec.File

    if (Test-Path $target) {
        if ((Get-FileHash $target -Algorithm SHA256).Hash -eq $Spec.Sha256) {
            Write-Info "Using cached $($Spec.File)"
            return $target
        }
        Remove-Item $target -Force
    }

    foreach ($url in $Spec.Urls) {
        Write-Info "Downloading $url"
        $partial = "$target.part"
        $r = Invoke-Native 'curl.exe' @('-fL', '--retry', '3', '--silent', '--show-error', '-o', $partial, $url)
        if ($r.Code -ne 0) {
            Write-Info "  failed: $($r.Output)"
            Remove-Item $partial -Force -ErrorAction SilentlyContinue
            continue
        }
        $hash = (Get-FileHash $partial -Algorithm SHA256).Hash
        if ($hash -ne $Spec.Sha256) {
            Remove-Item $partial -Force
            throw "Checksum mismatch for $($Spec.File) (expected $($Spec.Sha256), got $hash)."
        }
        Move-Item $partial $target -Force
        return $target
    }
    throw "Could not download $($Spec.File) from any source."
}

# Extracts a ZIP into the destination; a single top-level folder in the ZIP is flattened.
function Expand-ZipTo([string]$Zip, [string]$Destination) {
    $tmp = "$Destination.tmp"
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    New-Item -ItemType Directory -Force $tmp | Out-Null
    $r = Invoke-Native 'tar.exe' @('-xf', $Zip, '-C', $tmp)
    if ($r.Code -ne 0) { throw "Extracting $Zip failed: $($r.Output)" }

    $items = @(Get-ChildItem $tmp)
    $source = if ($items.Count -eq 1 -and $items[0].PSIsContainer) { $items[0].FullName } else { $tmp }

    if (Test-Path $Destination) { Remove-Item $Destination -Recurse -Force }
    Move-Item $source $Destination
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
}

function Test-ToolVersion([string]$Dir, [string]$Version) {
    $marker = Join-Path $Dir '.version'
    (Test-Path $marker) -and ((Get-Content $marker -Raw).Trim() -eq $Version)
}

function Set-ToolVersion([string]$Dir, [string]$Version) {
    Write-Utf8NoBom (Join-Path $Dir '.version') $Version
}

function Assert-Installed {
    foreach ($exe in @($PhpExe, $MariaDbd, $MailpitExe, (Join-Path $AdminerDir 'adminer.php'))) {
        if (-not (Test-Path $exe)) { throw "Missing $exe. Run setup.cmd first." }
    }
}

# --- MariaDB -------------------------------------------------------------------
function Write-MyIni {
    New-Item -ItemType Directory -Force $DataDir, $LogDir, $RunDir, $SessionDir, $TmpDir | Out-Null
    $ini = @"
# Generated by dev/_common.ps1 - manual changes will be overwritten.
[mysqld]
basedir=$(ConvertTo-SlashPath $MariaDbDir)
datadir=$(ConvertTo-SlashPath $DbDataDir)
port=$DbPort
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_czech_ci
log-error=$(ConvertTo-SlashPath (Join-Path $LogDir 'mariadb.err'))
tmpdir=$(ConvertTo-SlashPath $TmpDir)
innodb_buffer_pool_size=128M

[client]
host=127.0.0.1
port=$DbPort
user=root
default-character-set=utf8mb4
"@
    Write-Utf8NoBom $MyIni $ini
}

function Test-DbRunning {
    (Invoke-Native $MariaAdmin @("--defaults-file=$MyIni", '--connect-timeout=2', 'ping')).Code -eq 0
}

function Initialize-DbDataDir {
    if (Test-Path (Join-Path $DbDataDir 'mysql')) { return }
    Write-Step 'Initializing MariaDB data directory'
    if (Test-Path $DbDataDir) { Remove-Item $DbDataDir -Recurse -Force }
    $r = Invoke-Native $MariaInst @("--datadir=$DbDataDir", "--port=$DbPort")
    if ($r.Code -ne 0) { throw "mariadb-install-db failed:`n$($r.Output)" }
    Write-Ok "Created in $DbDataDir (root without password, 127.0.0.1 only)"
}

# Returns $true if this call started the server (the caller should then stop it again).
function Start-Db {
    if (Test-DbRunning) { return $false }
    if (Test-PortInUse $DbPort) { throw "Port $DbPort is already used by another program." }
    Initialize-DbDataDir
    Write-Step "Starting MariaDB on port $DbPort"
    $proc = Start-Process -FilePath $MariaDbd -ArgumentList "--defaults-file=`"$MyIni`"" -WindowStyle Hidden -PassThru
    Write-Utf8NoBom $DbPidFile "$($proc.Id)"
    for ($i = 0; $i -lt 60; $i++) {
        if (Test-DbRunning) { Write-Ok 'MariaDB is running'; return $true }
        if ($proc.HasExited) { break }
        Start-Sleep -Milliseconds 500
    }
    $log = Join-Path $LogDir 'mariadb.err'
    $tail = if (Test-Path $log) { (Get-Content $log -Tail 20) -join "`n" } else { '' }
    throw "MariaDB did not start. Log tail:`n$tail"
}

function Stop-Db {
    $proc = Get-PidProcess $DbPidFile 'mariadbd'
    if (-not (Test-DbRunning) -and -not $proc) { return }
    Write-Step 'Stopping MariaDB'
    Invoke-Native $MariaAdmin @("--defaults-file=$MyIni", 'shutdown') | Out-Null
    if ($proc -and -not $proc.WaitForExit(30000)) { Stop-Process -Id $proc.Id -Force }
    Remove-Item $DbPidFile -Force -ErrorAction SilentlyContinue
    Write-Ok 'MariaDB stopped'
}

function Invoke-DbSql([string]$Sql, [string]$Database) {
    $argList = @("--defaults-file=$MyIni", '--batch', '--skip-column-names')
    if ($Database) { $argList += "--database=$Database" }
    $argList += @('-e', $Sql)
    $r = Invoke-Native $MariaDbCli $argList
    if ($r.Code -ne 0) { throw "SQL failed:`n$($r.Output)" }
    return $r.Output
}

# The client reads the file itself (SOURCE) so UTF-8 is not mangled by PowerShell piping.
function Import-DbFile([string]$Path, [string]$Database) {
    $full = (Resolve-Path $Path).Path
    Write-Info "Importing $full"
    Invoke-DbSql "SOURCE $(ConvertTo-SlashPath $full)" $Database | Out-Null
}

function Test-DatabaseExists {
    (Invoke-DbSql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$DbName'") -eq '1'
}

# Drops and recreates the application database and user, then loads data.
function Reset-AppDatabase([string]$Import, [switch]$NoSeed) {
    Write-Step "Creating database '$DbName' and user '$DbUser'"
    $bootstrap = @"
DROP DATABASE IF EXISTS ``$DbName``;
CREATE DATABASE ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
CREATE USER IF NOT EXISTS '$DbUser'@'localhost' IDENTIFIED BY '$DbPass';
CREATE USER IF NOT EXISTS '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass';
ALTER USER '$DbUser'@'localhost' IDENTIFIED BY '$DbPass';
ALTER USER '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'localhost';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
    Invoke-DbSql $bootstrap | Out-Null

    $dbScripts = Join-Path $PSScriptRoot 'db'
    if ($Import) {
        Import-DbFile $Import $DbName
    } else {
        Import-DbFile (Join-Path $dbScripts 'schema.sql') $DbName
        if (-not $NoSeed) { Import-DbFile (Join-Path $dbScripts 'seed.sql') $DbName }
    }
    Write-Ok 'Database ready'
}

# --- Mailpit (catches all outgoing e-mail; nothing is delivered) -----------------
function Start-Mailpit {
    if (Get-PidProcess $MailPidFile 'mailpit') { return }
    foreach ($port in @($SmtpPort, $MailPort)) {
        if (Test-PortInUse $port) { throw "Port $port is already used by another program." }
    }
    New-Item -ItemType Directory -Force $MailDataDir, $LogDir, $RunDir | Out-Null
    Write-Step "Starting Mailpit (SMTP $SmtpPort, web $MailPort)"
    $argList = @(
        '--listen', "127.0.0.1:$MailPort",
        '--smtp', "127.0.0.1:$SmtpPort",
        '--database', "`"$(Join-Path $MailDataDir 'mailpit.db')`"",
        '--disable-version-check'
    ) -join ' '
    $proc = Start-Process -FilePath $MailpitExe -ArgumentList $argList -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $LogDir 'mailpit.log') `
        -RedirectStandardError (Join-Path $LogDir 'mailpit.err.log')
    Write-Utf8NoBom $MailPidFile "$($proc.Id)"
    for ($i = 0; $i -lt 40; $i++) {
        if ((Test-PortInUse $SmtpPort) -and (Test-PortInUse $MailPort)) { Write-Ok 'Mailpit is running'; return }
        if ($proc.HasExited) { break }
        Start-Sleep -Milliseconds 250
    }
    throw "Mailpit did not start, see $LogDir\mailpit.err.log"
}

function Stop-Mailpit {
    $proc = Get-PidProcess $MailPidFile 'mailpit'
    if (-not $proc) { return }
    Write-Step 'Stopping Mailpit'
    Stop-Process -Id $proc.Id -Force
    $proc.WaitForExit(10000) | Out-Null
    Remove-Item $MailPidFile -Force -ErrorAction SilentlyContinue
    Write-Ok 'Mailpit stopped'
}
