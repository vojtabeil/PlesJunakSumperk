<#
.SYNOPSIS
    Runs the portable version of the ball site from this folder: MariaDB, Mailpit and the PHP server.
    Part of the package built by dev\portable.cmd (copied to scripts\run.ps1). Nothing is installed:
    all data, logs and temp files stay in data\, the configuration is generated on every start,
    so the folder may be moved or copied anywhere.

.PARAMETER Action
    start (default), stop, reset (deletes all data and starts again with the sample data),
    firewall (Windows Defender Firewall rule letting other devices in the local network reach the web port;
    asks for administrator rights) or firewall-remove (deletes that rule).

.PARAMETER Lan
    The site is reachable from the local network too (e.g. on a phone): the PHP server listens on all
    interfaces. Requests from other devices run in production mode (no Tracy, no /dev pages, no Adminer).
    MariaDB and Mailpit always stay on this computer.

.PARAMETER NoBrowser
    Does not open the browser.
#>
param(
    [ValidateSet('start', 'stop', 'reset', 'firewall', 'firewall-remove')][string]$Action = 'start',
    [switch]$Lan,
    [switch]$NoBrowser
)

# Keep this file ASCII-only: Windows PowerShell 5.1 reads BOM-less files as ANSI.
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$Root       = Split-Path -Parent $PSScriptRoot
$RuntimeDir = Join-Path $Root 'runtime'
$WebDir     = Join-Path $Root 'web'
$SampleDir  = Join-Path $Root 'sample'
$DataDir    = Join-Path $Root 'data'
$DbDataDir  = Join-Path $DataDir 'mariadb'
$MailDataDir = Join-Path $DataDir 'mailpit'
$SessionDir = Join-Path $DataDir 'sessions'
$TmpDir     = Join-Path $DataDir 'tmp'
$LogDir     = Join-Path $DataDir 'logs'
$RunDir     = Join-Path $DataDir 'run'

$PhpDir     = Join-Path $RuntimeDir 'php'
$PhpExe     = Join-Path $PhpDir 'php.exe'
$PhpIni     = Join-Path $DataDir 'php.ini'
# php.exe reads its command line in the ANSI code page, so a path with diacritics (C:\Users\Jiri...)
# would break: PHP always runs in $Root with relative paths in its arguments and in extension_dir
# (the only php.ini path PHP cannot open as UTF-8). Other paths in php.ini may be absolute.
$PhpIniArg  = 'data\php.ini'
$MariaDbDir = Join-Path $RuntimeDir 'mariadb'
$MariaDbd   = Join-Path $MariaDbDir 'bin\mariadbd.exe'
$MariaDbCli = Join-Path $MariaDbDir 'bin\mariadb.exe'
$MariaAdmin = Join-Path $MariaDbDir 'bin\mariadb-admin.exe'
$MariaInst  = Join-Path $MariaDbDir 'bin\mariadb-install-db.exe'
$MyIni      = Join-Path $DataDir 'my.ini'
$MailpitExe = Join-Path $RuntimeDir 'mailpit\mailpit.exe'

$PortsFile   = Join-Path $RunDir 'ports.json'
$DbPidFile   = Join-Path $RunDir 'mariadb.pid'
$WebPidFile  = Join-Path $RunDir 'php.pid'
$WebListenFile = Join-Path $RunDir 'php.listen'
$MailPidFile = Join-Path $RunDir 'mailpit.pid'

# Not the ports of the development environment (start.cmd), so both can run at once.
# A port taken by another program is replaced by the next free one.
$DefaultPorts = [ordered]@{ Web = 8800; Db = 3317; Smtp = 1125; Mail = 8825 }
$DbName = 'ples'
$DbUser = 'ples'
$DbPass = 'ples'

# --- Helpers -------------------------------------------------------------------
function Write-Step([string]$Text) { Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text)   { Write-Host "    $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "    $Text" }

function ConvertTo-SlashPath([string]$Path) { $Path -replace '\\', '/' }

function Write-Utf8NoBom([string]$Path, [string]$Content) {
    [IO.File]::WriteAllText($Path, $Content, (New-Object Text.UTF8Encoding $false))
}

# Runs a native program without letting stderr output abort the script in PS 5.1.
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
    if ($proc -and $proc.ProcessName -eq $Name -and $proc.Path -and $proc.Path.StartsWith($RuntimeDir, [StringComparison]::OrdinalIgnoreCase)) { return $proc }
    Remove-Item $PidFile -Force
    return $null
}

function Test-AnyRunning {
    [bool]((Get-PidProcess $DbPidFile 'mariadbd') -or (Get-PidProcess $WebPidFile 'php') -or (Get-PidProcess $MailPidFile 'mailpit'))
}

# Ports of the running instance, or free ones for a new start (saved for the next calls).
function Get-Ports {
    if ((Test-Path $PortsFile) -and (Test-AnyRunning)) {
        $saved = Get-Content $PortsFile -Raw | ConvertFrom-Json
        return @{ Web = [int]$saved.Web; Db = [int]$saved.Db; Smtp = [int]$saved.Smtp; Mail = [int]$saved.Mail }
    }
    $ports = @{}
    $taken = @()
    foreach ($name in $DefaultPorts.Keys) {
        $port = $DefaultPorts[$name]
        while ((Test-PortInUse $port) -or ($taken -contains $port)) { $port++ }
        $ports[$name] = $port
        $taken += $port
    }
    Write-Utf8NoBom $PortsFile ($ports | ConvertTo-Json)
    return $ports
}

# --- Configuration (generated on every start for the current location of the folder) ---
function Write-Config([hashtable]$Ports) {
    New-Item -ItemType Directory -Force $DataDir, $SessionDir, $TmpDir, $LogDir, $RunDir, $MailDataDir | Out-Null

    Write-Utf8NoBom $MyIni @"
# Generated by scripts/run.ps1 on every start - manual changes will be overwritten.
[mysqld]
basedir=$(ConvertTo-SlashPath $MariaDbDir)
datadir=$(ConvertTo-SlashPath $DbDataDir)
port=$($Ports.Db)
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_czech_ci
log-error=$(ConvertTo-SlashPath (Join-Path $LogDir 'mariadb.err'))
tmpdir=$(ConvertTo-SlashPath $TmpDir)
innodb_buffer_pool_size=128M
innodb_log_file_size=16M

[client]
host=127.0.0.1
port=$($Ports.Db)
user=root
default-character-set=utf8mb4
"@

    $extDir = 'runtime/php/ext'
    $enable = 'curl|fileinfo|gd|intl|mbstring|mysqli|openssl|pdo_mysql|sodium|zip'
    $ini = Get-Content (Join-Path $PhpDir 'php.ini-development') | ForEach-Object {
        if ($_ -match '^;\s*extension_dir\s*=\s*"ext"') { "extension_dir = `"$extDir`"" }
        elseif ($_ -match "^;extension=($enable)$") { $_.Substring(1) }
        elseif ($_ -match '^;date\.timezone\s*=') { 'date.timezone = Europe/Prague' }
        elseif ($_ -match '^;session\.save_path\s*=') { "session.save_path = `"$(ConvertTo-SlashPath $SessionDir)`"" }
        elseif ($_ -match '^;\s*sys_temp_dir\s*=') { "sys_temp_dir = `"$(ConvertTo-SlashPath $TmpDir)`"" }
        elseif ($_ -match '^;\s*upload_tmp_dir\s*=') { "upload_tmp_dir = `"$(ConvertTo-SlashPath $TmpDir)`"" }
        elseif ($_ -match '^;\s*error_log\s*=\s*php_errors\.log') { "error_log = `"$(ConvertTo-SlashPath (Join-Path $LogDir 'php-errors.log'))`"" }
        # Even plain mail() ends up in Mailpit, never on the internet.
        elseif ($_ -match '^SMTP\s*=') { 'SMTP = 127.0.0.1' }
        elseif ($_ -match '^smtp_port\s*=') { "smtp_port = $($Ports.Smtp)" }
        else { $_ }
    }
    Write-Utf8NoBom $PhpIni (($ini -join "`r`n") + "`r`n")

    # The template is UTF-8 (Czech sender name); only the ports are filled in.
    $neon = [IO.File]::ReadAllText((Join-Path $PSScriptRoot 'local.neon'), [Text.Encoding]::UTF8)
    $neon = $neon.Replace('{DB_PORT}', "$($Ports.Db)").Replace('{SMTP_PORT}', "$($Ports.Smtp)").Replace('{MAIL_PORT}', "$($Ports.Mail)")
    Write-Utf8NoBom (Join-Path $WebDir 'config\local.neon') $neon
}

# Nette cache holds absolute paths and the configuration: rebuilt on every fresh start.
function Clear-AppCache {
    Get-ChildItem (Join-Path $WebDir 'var\temp') -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
}

# --- MariaDB -------------------------------------------------------------------
function Test-DbRunning {
    (Invoke-Native $MariaAdmin @("--defaults-file=$MyIni", '--connect-timeout=2', 'ping')).Code -eq 0
}

function Invoke-DbSql([string]$Sql, [string]$Database) {
    $argList = @("--defaults-file=$MyIni", '--batch', '--skip-column-names')
    if ($Database) { $argList += "--database=$Database" }
    $argList += @('-e', $Sql)
    $r = Invoke-Native $MariaDbCli $argList
    if ($r.Code -ne 0) { throw "SQL failed:`n$($r.Output)" }
    return $r.Output
}

# The file goes to the client's standard input unchanged (any path, UTF-8 kept).
function Import-DbFile([string]$Path, [string]$Database) {
    Write-Info "Importing $(Split-Path -Leaf $Path)"
    $err = Join-Path $LogDir 'import.err.log'
    $proc = Start-Process -FilePath $MariaDbCli -NoNewWindow -Wait -PassThru `
        -ArgumentList "`"--defaults-file=$MyIni`" --database=$Database" `
        -RedirectStandardInput $Path -RedirectStandardError $err -RedirectStandardOutput (Join-Path $LogDir 'import.log')
    if ($proc.ExitCode -ne 0) { throw "Import of $Path failed:`n$(Get-Content $err -Raw)" }
}

# Returns $true when the data directory was created now (the sample data must be loaded).
function Start-Db([hashtable]$Ports) {
    if (Test-DbRunning) { return $false }
    $fresh = -not (Test-Path (Join-Path $DbDataDir 'mysql'))
    if ($fresh) {
        Write-Step 'Creating the database (first start)'
        if (Test-Path $DbDataDir) { Remove-Item $DbDataDir -Recurse -Force }
        $r = Invoke-Native $MariaInst @("--datadir=$DbDataDir", "--port=$($Ports.Db)")
        if ($r.Code -ne 0) { throw "mariadb-install-db failed:`n$($r.Output)" }
    }
    Write-Step 'Starting MariaDB'
    $proc = Start-Process -FilePath $MariaDbd -ArgumentList "`"--defaults-file=$MyIni`"" -WindowStyle Hidden -PassThru
    Write-Utf8NoBom $DbPidFile "$($proc.Id)"
    for ($i = 0; $i -lt 60; $i++) {
        if (Test-DbRunning) { Write-Ok 'MariaDB is running'; return $fresh }
        if ($proc.HasExited) { break }
        Start-Sleep -Milliseconds 500
    }
    $log = Join-Path $LogDir 'mariadb.err'
    $tail = if (Test-Path $log) { (Get-Content $log -Tail 20) -join "`n" } else { '' }
    throw "MariaDB did not start. Log tail:`n$tail"
}

function Stop-Db {
    $proc = Get-PidProcess $DbPidFile 'mariadbd'
    if (-not $proc) { return }
    Write-Step 'Stopping MariaDB'
    Invoke-Native $MariaAdmin @("--defaults-file=$MyIni", 'shutdown') | Out-Null
    if (-not $proc.WaitForExit(30000)) { Stop-Process -Id $proc.Id -Force }
    Remove-Item $DbPidFile -Force -ErrorAction SilentlyContinue
    Write-Ok 'MariaDB stopped'
}

# Schema + defaults + hall plan + test data, the same as dev\init-db.cmd.
function Import-SampleData {
    Write-Step 'Loading the sample data'
    $sql = @"
CREATE DATABASE IF NOT EXISTS ``$DbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci;
CREATE USER IF NOT EXISTS '$DbUser'@'localhost' IDENTIFIED BY '$DbPass';
CREATE USER IF NOT EXISTS '$DbUser'@'127.0.0.1' IDENTIFIED BY '$DbPass';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'localhost';
GRANT ALL PRIVILEGES ON ``$DbName``.* TO '$DbUser'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
    Invoke-DbSql $sql | Out-Null
    Import-DbFile (Join-Path $SampleDir 'schema.sql') $DbName
    Import-DbFile (Join-Path $SampleDir 'defaults.sql') $DbName
    Write-Info 'Importing the hall plan'
    Push-Location $Root
    try {
        $r = Invoke-Native $PhpExe @('-c', $PhpIniArg, 'web\bin\import-hall.php', 'sample\hall-plan.svg', '--cancel')
        if ($r.Code -ne 0) { throw "Hall plan import failed:`n$($r.Output)" }
    } finally { Pop-Location }
    Import-DbFile (Join-Path $SampleDir 'seed.sql') $DbName
    Write-Ok 'Sample data loaded'
}

# --- Mailpit -------------------------------------------------------------------
function Start-Mailpit([hashtable]$Ports) {
    if (Get-PidProcess $MailPidFile 'mailpit') { return }
    Write-Step 'Starting Mailpit'
    $argList = @(
        '--listen', "127.0.0.1:$($Ports.Mail)",
        '--smtp', "127.0.0.1:$($Ports.Smtp)",
        '--database', "`"$(Join-Path $MailDataDir 'mailpit.db')`"",
        '--disable-version-check'
    ) -join ' '
    $proc = Start-Process -FilePath $MailpitExe -ArgumentList $argList -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $LogDir 'mailpit.log') `
        -RedirectStandardError (Join-Path $LogDir 'mailpit.err.log')
    Write-Utf8NoBom $MailPidFile "$($proc.Id)"
    for ($i = 0; $i -lt 40; $i++) {
        if ((Test-PortInUse $Ports.Smtp) -and (Test-PortInUse $Ports.Mail)) { Write-Ok 'Mailpit is running'; return }
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

# --- PHP server ----------------------------------------------------------------
function Start-Web([hashtable]$Ports, [string]$Listen) {
    if (Get-PidProcess $WebPidFile 'php') {
        $running = if (Test-Path $WebListenFile) { (Get-Content $WebListenFile -Raw).Trim() } else { '127.0.0.1' }
        if ($running -eq $Listen) { Write-Ok "PHP server is already running ($running)"; return }
        Write-Info "PHP server runs on $running, restarting it on $Listen"
        Stop-Web
    }
    Write-Step "Starting PHP server on $Listen`:$($Ports.Web)"
    $proc = Start-Process -FilePath $PhpExe -WindowStyle Hidden -PassThru -WorkingDirectory $Root `
        -ArgumentList "-c $PhpIniArg -S $Listen`:$($Ports.Web) -t web\www scripts\router.php" `
        -RedirectStandardOutput (Join-Path $LogDir 'php.out.log') `
        -RedirectStandardError (Join-Path $LogDir 'php.log')
    Write-Utf8NoBom $WebPidFile "$($proc.Id)"
    Write-Utf8NoBom $WebListenFile $Listen
    for ($i = 0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 250
        if (Test-PortInUse $Ports.Web) { Write-Ok 'PHP server is running'; return }
        if ($proc.HasExited) { break }
    }
    throw "PHP server did not start, see $LogDir\php.log"
}

function Stop-Web {
    $proc = Get-PidProcess $WebPidFile 'php'
    if (-not $proc) { return }
    Write-Step 'Stopping PHP server'
    Stop-Process -Id $proc.Id -Force
    $proc.WaitForExit(10000) | Out-Null
    Remove-Item $WebPidFile, $WebListenFile -Force -ErrorAction SilentlyContinue
    Write-Ok 'PHP server stopped'
}

# --- Actions -------------------------------------------------------------------
function Stop-All {
    Stop-Web
    Stop-Mailpit
    Stop-Db
}

function Start-All {
    # A ZIP from the internet marks its files as downloaded; Windows would then ask before each program.
    Get-ChildItem $RuntimeDir -Recurse -Include '*.exe', '*.dll' | Unblock-File

    New-Item -ItemType Directory -Force $RunDir | Out-Null
    $fresh = -not (Test-AnyRunning)
    $ports = Get-Ports
    Write-Config $ports
    if ($fresh) { Clear-AppCache }

    if (Start-Db $ports) { Import-SampleData }
    Start-Mailpit $ports
    Start-Web $ports $(if ($Lan) { '0.0.0.0' } else { '127.0.0.1' })

    $url = "http://127.0.0.1:$($ports.Web)"
    $version = Get-Content (Join-Path $Root 'version.txt') -Raw -ErrorAction SilentlyContinue
    Write-Host ''
    Write-Host "Ples portable $("$version".Trim())" -ForegroundColor Green
    Write-Host "Web:      $url/"
    Write-Host "Admin:    $url/admin  (login admin, password admin)"
    Write-Host "Check:    $url/admin/kontrola  (entrance check app, QR codes of the tickets)"
    if ($Lan) {
        # Addresses of this computer in the local network (no loopback, no link-local).
        $addresses = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
            Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } |
            Sort-Object InterfaceAlias
        foreach ($address in $addresses) {
            Write-Host ("LAN:      http://{0}:{1}/  ({2})" -f $address.IPAddress, $ports.Web, $address.InterfaceAlias)
        }
        Write-Host '          Other devices get production mode (no Tracy, /dev pages or Adminer).'
        Write-Host '          If they cannot connect, run Allow-LAN.cmd (Windows Defender Firewall, asks for admin rights).'
        Write-Host '          Phones over plain http get no camera in the check app (browsers require https):'
        Write-Host '          type the ticket code instead.'
    }
    Write-Host "Bank:     $url/dev/bank  (fake payments, then admin -> Platby -> Nacist platby z banky)"
    Write-Host "E-mails:  http://127.0.0.1:$($ports.Mail)/"
    Write-Host "Adminer:  $url/adminer?server=127.0.0.1:$($ports.Db)&username=$DbUser&db=$DbName  (password: $DbPass)"
    Write-Host "Status:   $url/dev/status"
    Write-Host "Data:     $DataDir"
    Write-Host 'Stop:     Stop.cmd'

    if (-not $NoBrowser) { Start-Process "$url/" }
}

# --- Windows Defender Firewall (Allow-LAN.cmd) ------------------------------------
# Inbound TCP to the web port (the running instance's, else the default one) from private and domain
# networks only: the package has known passwords and must not be reachable from public networks.
$FirewallGroup = 'Ples portable'

function Test-Admin {
    ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
        [Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Get-FirewallRules {
    @(Get-NetFirewallRule -Group $FirewallGroup -ErrorAction SilentlyContinue)
}

function Set-FirewallRule([switch]$Remove) {
    if (-not (Test-Admin)) {
        # Firewall rules need administrator rights: run this action again elevated (Windows asks).
        Write-Step 'Asking for administrator rights'
        $arguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"", '-Action', $Action)
        try {
            Start-Process powershell.exe -Verb RunAs -Wait -WindowStyle Hidden -ArgumentList $arguments
        } catch {
            throw 'Administrator rights were not granted, the firewall was not changed.'
        }
    } else {
        Get-FirewallRules | Remove-NetFirewallRule
        if (-not $Remove) {
            $port = $DefaultPorts.Web
            if ((Test-Path $PortsFile) -and (Test-AnyRunning)) { $port = [int](Get-Content $PortsFile -Raw | ConvertFrom-Json).Web }
            New-NetFirewallRule -DisplayName "Ples portable - web (TCP $port)" -Group $FirewallGroup -Direction Inbound `
                -Protocol TCP -LocalPort $port -Action Allow -Profile Domain, Private | Out-Null
        }
        return
    }

    # Back in the normal window: report what the elevated run did.
    $rules = Get-FirewallRules
    if ($Remove) {
        if ($rules) { throw 'The firewall rule is still there.' }
        Write-Ok 'Firewall rule removed'
        return
    }
    if (-not $rules) { throw 'The firewall rule was not created.' }
    $ports = ($rules | Get-NetFirewallPortFilter).LocalPort -join ', '
    Write-Ok "Firewall allows TCP $ports from private networks ($($rules[0].DisplayName))"
    if ($ports -ne "$($DefaultPorts.Web)" -and -not (Test-AnyRunning)) {
        Write-Host "    The site starts on port $($DefaultPorts.Web) unless it is taken; run Allow-LAN.cmd again after Start-LAN.cmd if it differs." -ForegroundColor Yellow
    }
    $public = @(Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object NetworkCategory -eq 'Public')
    foreach ($network in $public) {
        Write-Host "    The network '$($network.Name)' is set as public: other devices there cannot connect." -ForegroundColor Yellow
        Write-Host '    Make it private in Windows Settings -> Network -> (the network) -> Private network.' -ForegroundColor Yellow
    }
}

# Child processes keep their temp files inside the folder too.
New-Item -ItemType Directory -Force $TmpDir | Out-Null
$env:TEMP = $TmpDir
$env:TMP = $TmpDir

switch ($Action) {
    'start' { Start-All }
    'stop' {
        if (-not (Test-Path $MyIni)) { Write-Ok 'Nothing is running'; return }
        Stop-All
        Write-Ok 'Everything stopped'
    }
    'reset' {
        if (Test-Path $MyIni) { Stop-All }
        Write-Step 'Deleting all data'
        foreach ($dir in @($DbDataDir, $MailDataDir, $SessionDir, $LogDir, $RunDir)) {
            if (Test-Path $dir) { Remove-Item $dir -Recurse -Force }
        }
        Get-ChildItem (Join-Path $WebDir 'var\log') -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
        Clear-AppCache
        Start-All
    }
    'firewall' { Set-FirewallRule }
    'firewall-remove' { Set-FirewallRule -Remove }
}
