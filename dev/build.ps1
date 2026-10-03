<#
.SYNOPSIS
    Builds frontend assets into www/build: type check (tsc), bundle (bun build), styles (sass).

.PARAMETER SkipTypeCheck
    Skips tsc (used by start.ps1 for a quick first build).
#>
param([switch]$SkipTypeCheck)

. "$PSScriptRoot\_common.ps1"

Assert-BuildTools
Push-Location $Root
try {
    if (-not $SkipTypeCheck) {
        Write-Step 'Type checking (tsc)'
        $r = Invoke-Native $TscExe @('-p', 'tsconfig.json')
        if ($r.Code -ne 0) { throw "Type errors:`n$($r.Output)" }
        Write-Ok 'No type errors'
    }

    Write-Step 'Bundling TypeScript (bun build)'
    $r = Invoke-Native $BunExe (Get-BunBuildArgs)
    if ($r.Code -ne 0) { throw "bun build failed:`n$($r.Output)" }
    Write-Ok 'www/build/*.js'

    Write-Step 'Compiling SCSS (sass)'
    $r = Invoke-Native $DartExe (@($SassSnapshot) + (Get-SassArgs))
    if ($r.Code -ne 0) { throw "sass failed:`n$($r.Output)" }
    Write-Ok 'www/build/*.css'
} finally {
    Pop-Location
}
