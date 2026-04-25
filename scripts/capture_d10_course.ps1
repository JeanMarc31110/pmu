param(
    [Parameter(Mandatory = $true)][string]$Date,
    [Parameter(Mandatory = $true)][string]$Reunion,
    [Parameter(Mandatory = $true)][string]$Course
)

$ErrorActionPreference = "Stop"

$url = "http://localhost/pmu/capture_d10_due_test.php?date=$Date&reunion=$Reunion&course=$Course"
$logDir = "C:\xampp\htdocs\pmu\data\logs"
$logPath = Join-Path $logDir "capture_d10_course.log"

function Get-JsonValueOrZero($Object, [string]$Name) {
    if ($null -eq $Object) {
        return 0
    }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property -or $null -eq $property.Value) {
        return 0
    }
    return $property.Value
}

if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
}

try {
    $response = Invoke-WebRequest -UseBasicParsing -Uri $url -TimeoutSec 60
    $content = $response.Content -replace "^[\uFEFF\s]+", ""
    $json = $content | ConvertFrom-Json
    if (-not $json.success) {
        throw "capture_d10_due_test.php success=false"
    }
    $line = "{0} OK date={1} course={2}{3} due={4} saved={5} already={6} no_selection={7} errors={8}" -f `
        (Get-Date -Format "yyyy-MM-dd HH:mm:ss"),
        $Date,
        $Reunion,
        $Course,
        (Get-JsonValueOrZero $json "due_count"),
        (Get-JsonValueOrZero $json "saved"),
        (Get-JsonValueOrZero $json "already_present"),
        (Get-JsonValueOrZero $json "without_selection"),
        (Get-JsonValueOrZero $json "errors_count")
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
} catch {
    $line = "{0} ERROR date={1} course={2}{3} {4}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Date, $Reunion, $Course, $_.Exception.Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
    exit 1
}
