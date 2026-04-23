param(
    [string]$RepoUrl = 'https://github.com/JeanMarc31110/pmu.git',
    [string]$Branch = 'main',
    [string]$InstallRoot = 'C:\xampp\htdocs',
    [switch]$IncludeLocalData,
    [switch]$UpdateExisting,
    [switch]$CreateDesktopShortcut
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Info {
    param([string]$Message)
    Write-Host $Message
}

function Test-CommandExists {
    param([string]$Name)
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Invoke-Git {
    param(
        [string[]]$Arguments,
        [string]$WorkingDirectory = $PWD.Path
    )

    & git -C $WorkingDirectory @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Git command failed: git -C $WorkingDirectory $($Arguments -join ' ')"
    }
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path | Out-Null
    }
}

function Copy-LocalData {
    param(
        [string]$SourceRoot,
        [string]$TargetRoot
    )

    $sourceData = Join-Path $SourceRoot 'data'
    if (-not (Test-Path -LiteralPath $sourceData)) {
        return
    }

    Write-Info "Copie des données locales vers $TargetRoot\data"
    Ensure-Directory (Join-Path $TargetRoot 'data')
    Copy-Item -Path (Join-Path $sourceData '*') -Destination (Join-Path $TargetRoot 'data') -Recurse -Force
}

function New-DesktopShortcut {
    param(
        [string]$TargetUrl,
        [string]$ShortcutName = 'PMU Dashboard.url'
    )

    $desktop = [Environment]::GetFolderPath('Desktop')
    if ([string]::IsNullOrWhiteSpace($desktop) -or -not (Test-Path -LiteralPath $desktop)) {
        return
    }

    $shortcutPath = Join-Path $desktop $ShortcutName
    @"
[InternetShortcut]
URL=$TargetUrl
"@ | Set-Content -LiteralPath $shortcutPath -Encoding ASCII
}

if (-not (Test-CommandExists git)) {
    throw 'Git n''est pas installé ou n''est pas dans le PATH.'
}

$targetRoot = Join-Path $InstallRoot 'pmu'
$sourceRoot = Split-Path -Parent $PSScriptRoot

Write-Info "Installation PMU"
Write-Info "Source : $RepoUrl"
Write-Info "Cible  : $targetRoot"

if (Test-Path -LiteralPath (Join-Path $targetRoot '.git')) {
    if (-not $UpdateExisting) {
        Write-Info 'Dépôt déjà présent. Mise à jour de la branche distante.'
    }
    Invoke-Git -WorkingDirectory $targetRoot -Arguments @('remote', 'set-url', 'origin', $RepoUrl)
    Invoke-Git -WorkingDirectory $targetRoot -Arguments @('fetch', 'origin', '--prune')
    Invoke-Git -WorkingDirectory $targetRoot -Arguments @('checkout', $Branch)
    Invoke-Git -WorkingDirectory $targetRoot -Arguments @('pull', '--ff-only', 'origin', $Branch)
} elseif (Test-Path -LiteralPath $targetRoot) {
    if (-not $UpdateExisting) {
        throw "Le dossier cible existe déjà et n'est pas un dépôt Git : $targetRoot"
    }
    Invoke-Git -WorkingDirectory $InstallRoot -Arguments @('clone', '--branch', $Branch, '--single-branch', $RepoUrl, 'pmu')
} else {
    Ensure-Directory $InstallRoot
    Invoke-Git -WorkingDirectory $InstallRoot -Arguments @('clone', '--branch', $Branch, '--single-branch', $RepoUrl, 'pmu')
}

Ensure-Directory (Join-Path $targetRoot 'data')
Ensure-Directory (Join-Path $targetRoot 'logs')
Ensure-Directory (Join-Path $targetRoot 'exports')

if ($IncludeLocalData) {
    Copy-LocalData -SourceRoot $sourceRoot -TargetRoot $targetRoot
}

if ($CreateDesktopShortcut) {
    New-DesktopShortcut -TargetUrl 'http://localhost/pmu/dashboard.html'
}

$commit = & git -C $targetRoot rev-parse --short HEAD
if ($LASTEXITCODE -eq 0) {
    Write-Info "Version installée : $commit"
}

Write-Info 'Installation terminée.'
Write-Info 'Vérification utile : ouvrir http://localhost/pmu/dashboard.html'
