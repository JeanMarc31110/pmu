$ErrorActionPreference = "Stop"

$date = Get-Date -Format "ddMMyyyy"
$url = "http://localhost/pmu/capture_d10_due_test.php?date=$date"
$logDir = "C:\xampp\htdocs\pmu\data\logs"
$logPath = Join-Path $logDir "capture_d10_due_test.log"

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
    $line = "{0} OK date={1} due={2} saved={3} already={4} no_selection={5} errors={6}" -f `
        (Get-Date -Format "yyyy-MM-dd HH:mm:ss"),
        $date,
        (Get-JsonValueOrZero $json "due_count"),
        (Get-JsonValueOrZero $json "saved"),
        (Get-JsonValueOrZero $json "already_present"),
        (Get-JsonValueOrZero $json "without_selection"),
        (Get-JsonValueOrZero $json "errors_count")
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
} catch {
    $line = "{0} ERROR date={1} {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $date, $_.Exception.Message
    Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
    exit 1
}
