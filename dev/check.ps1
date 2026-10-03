<#
.SYNOPSIS
    Runs all checks: TypeScript types, frontend tests, PHPUnit, PHPStan. Exits non-zero on failure.
#>

. "$PSScriptRoot\_common.ps1"

Assert-Installed
Assert-BuildTools
Write-MyIni
$started = Start-Db
$failed = @()
Push-Location $Root
try {
    $steps = [ordered]@{
        'TypeScript (tsc)'   = @($TscExe, @('-p', 'tsconfig.json'))
        'Frontend tests (bun test)' = @($BunExe, @('test', 'tests/ts'))
        'PHPUnit'            = @($PhpExe, @('-c', $PhpIni, 'vendor/phpunit/phpunit/phpunit'))
        'PHPStan'            = @($PhpExe, @('-c', $PhpIni, '-d', 'memory_limit=1G', 'vendor/phpstan/phpstan/phpstan', 'analyse', '--no-progress'))
    }
    foreach ($name in $steps.Keys) {
        Write-Step $name
        $r = Invoke-Native $steps[$name][0] $steps[$name][1]
        if ($r.Code -eq 0) {
            Write-Ok 'OK'
        } else {
            Write-Host $r.Output
            $failed += $name
        }
    }
} finally {
    Pop-Location
    if ($started) { Stop-Db }
}

if ($failed) {
    Write-Host "Failed: $($failed -join ', ')" -ForegroundColor Red
    exit 1
}
Write-Host 'All checks passed.' -ForegroundColor Green
