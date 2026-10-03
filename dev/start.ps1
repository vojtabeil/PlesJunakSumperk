<#
.SYNOPSIS
    Starts MariaDB and the PHP server in the background. Safe to run repeatedly.
#>
param([switch]$NoBrowser)

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Write-MyIni
Start-Db | Out-Null
Start-Mailpit

Assert-BuildTools
if (-not (Test-Path (Join-Path $Root 'www\build\front.js'))) {
    & (Join-Path $PSScriptRoot 'build.ps1') -SkipTypeCheck
}
Write-Step 'Starting asset watchers'
Start-Watchers

$url = "http://127.0.0.1:$WebPort"
if (Get-PidProcess $WebPidFile 'php') {
    Write-Ok 'PHP server is already running'
} else {
    if (Test-PortInUse $WebPort) { throw "Port $WebPort is already used by another program." }
    Write-Step "Starting PHP server on port $WebPort"
    $public = Join-Path $Root 'www'
    $router = Join-Path $PSScriptRoot 'router.php'
    $proc = Start-Process -FilePath $PhpExe -WindowStyle Hidden -PassThru `
        -ArgumentList "-c `"$PhpIni`" -S 127.0.0.1:$WebPort -t `"$public`" `"$router`"" `
        -RedirectStandardOutput (Join-Path $LogDir 'php.out.log') `
        -RedirectStandardError (Join-Path $LogDir 'php.log')
    Write-Utf8NoBom $WebPidFile "$($proc.Id)"

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
Write-Host "Status:   $url/dev/status"
Write-Host "Old site: $url/original/"
Write-Host "Adminer:  $url/adminer?server=127.0.0.1:$DbPort&username=$DbUser&db=$DbName  (password: $DbPass)"
Write-Host "E-mails:  http://127.0.0.1:$MailPort/  (Mailpit, SMTP 127.0.0.1:$SmtpPort)"
Write-Host "Logs:     $LogDir"
Write-Host 'Stop:     stop.cmd'

if (-not $NoBrowser) { Start-Process "$url/" }
