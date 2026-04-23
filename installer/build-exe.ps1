param(
    [string]$OutDir = (Join-Path $PSScriptRoot 'out')
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework64\v4.0.30319\csc.exe'
if (-not (Test-Path -LiteralPath $csc)) {
    $csc = Join-Path $env:WINDIR 'Microsoft.NET\Framework\v4.0.30319\csc.exe'
}

if (-not (Test-Path -LiteralPath $csc)) {
    throw 'Le compilateur C# Windows (csc.exe) est introuvable.'
}

if (-not (Test-Path -LiteralPath $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir | Out-Null
}

$program = Join-Path $PSScriptRoot 'exe\Program.cs'
$script = Join-Path $PSScriptRoot 'install-pmu.ps1'
$exe = Join-Path $OutDir 'PMU-Installer.exe'

$args = @(
    '/nologo'
    '/target:exe'
    '/optimize+'
    '/platform:anycpu'
    '/reference:System.Core.dll'
    ('/out:' + $exe)
    ('/resource:' + $script + ',install-pmu.ps1')
    $program
)

& $csc @args
if ($LASTEXITCODE -ne 0) {
    throw 'La génération de l''exe a échoué.'
}

Write-Host "Exe généré dans $exe"
