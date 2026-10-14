<#
.SYNOPSIS
    Starts MariaDB and the PHP server in the background. Safe to run repeatedly.
.PARAMETER Lan
    The website is reachable from the local network too (e.g. to try it on a phone): the PHP server
    listens on all interfaces. Adminer and the old site stay local; requests from other devices run
    in production mode (no Tracy, no /dev pages). MariaDB and Mailpit stay on 127.0.0.1.
#>
param([switch]$NoBrowser, [switch]$Lan)

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Write-MyIni
Start-Db | Out-Null
Start-Mailpit

Assert-BuildTools
if (-not (Test-Path (Join-Path $SrcDir 'www\build\front.js'))) {
    & (Join-Path $PSScriptRoot 'build.ps1') -SkipTypeCheck
}
Write-Step 'Starting asset watchers'
Start-Watchers

$listen = if ($Lan) { '0.0.0.0' } else { '127.0.0.1' }
$url = "http://127.0.0.1:$WebPort"
if (Get-PidProcess $WebPidFile 'php') {
    $running = if (Test-Path $WebListenFile) { (Get-Content $WebListenFile -Raw).Trim() } else { '127.0.0.1' }
    if ($running -ne $listen) {
        throw "PHP server is already running on $running. Run stop.cmd first, then start.cmd$(if ($Lan) { ' -Lan' })."
    }
    Write-Ok "PHP server is already running ($running)"
} else {
    if (Test-PortInUse $WebPort) { throw "Port $WebPort is already used by another program." }
    Write-Step "Starting PHP server on $listen`:$WebPort"
    $public = Join-Path $SrcDir 'www'
    $router = Join-Path $PSScriptRoot 'router.php'
    $proc = Start-Process -FilePath $PhpExe -WindowStyle Hidden -PassThru `
        -ArgumentList "-c `"$PhpIni`" -S $listen`:$WebPort -t `"$public`" `"$router`"" `
        -RedirectStandardOutput (Join-Path $LogDir 'php.out.log') `
        -RedirectStandardError (Join-Path $LogDir 'php.log')
    Write-Utf8NoBom $WebPidFile "$($proc.Id)"
    Write-Utf8NoBom $WebListenFile $listen

    $ready = $false
    for ($i = 0; $i -lt 20 -and -not $ready; $i++) {
        Start-Sleep -Milliseconds 250
        $ready = Test-PortInUse $WebPort
        if ($proc.HasExited) { break }
    }
    if (-not $ready) { throw "PHP server did not start, see $LogDir\php.log" }
    Write-Ok 'PHP server is running'
}

Write-Host ''
Write-Host "Web:      $url/"
if ($Lan) {
    # Addresses of this computer in the local network (no loopback, no link-local).
    $addresses = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
        Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } |
        Sort-Object InterfaceAlias
    foreach ($address in $addresses) {
        Write-Host ("LAN:      http://{0}:{1}/  ({2})" -f $address.IPAddress, $WebPort, $address.InterfaceAlias)
    }
    Write-Host '          Other devices get production mode; Adminer and /original stay local.'
    Write-Host '          If they cannot connect, allow php.exe for private networks in Windows Firewall.'
}
Write-Host "Status:   $url/dev/status"
Write-Host "Old site: $url/original/"
Write-Host "Adminer:  $url/adminer?server=127.0.0.1:$DbPort&username=$DbUser&db=$DbName  (password: $DbPass)"
Write-Host "E-mails:  http://127.0.0.1:$MailPort/  (Mailpit, SMTP 127.0.0.1:$SmtpPort)"
Write-Host "Logs:     $LogDir"
Write-Host 'Stop:     stop.cmd'

if (-not $NoBrowser) { Start-Process "$url/" }
