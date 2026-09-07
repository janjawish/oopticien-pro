[CmdletBinding()]
param(
    [string]$XamppPath = 'C:\xampp',
    [string]$AppUrl = 'http://127.0.0.1/oopticien-pro/'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$logDirectory = Join-Path $env:ProgramData 'OopticienPro'
$logFile = Join-Path $logDirectory 'server-watchdog.log'
New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null

function Write-WatchdogLog {
    param([string]$Message)

    $line = '{0} | {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Add-Content -LiteralPath $logFile -Value $line -Encoding UTF8
    Write-Output $line
}

function Find-XamppService {
    param([string]$RelativeExecutable)

    $expectedPath = (Join-Path $XamppPath $RelativeExecutable).ToLowerInvariant()
    Get-CimInstance -ClassName Win32_Service | Where-Object {
        $servicePath = ([string]$_.PathName).Replace('/', '\').ToLowerInvariant()
        $servicePath.Contains($expectedPath)
    } | Select-Object -First 1
}

try {
    $services = @(
        Find-XamppService 'apache\bin\httpd.exe'
        Find-XamppService 'mysql\bin\mysqld.exe'
    ) | Where-Object { $_ -ne $null }

    if ($services.Count -ne 2) {
        throw 'Les services XAMPP Apache et MariaDB ne sont pas tous installés.'
    }

    foreach ($serviceInfo in $services) {
        $service = Get-Service -Name $serviceInfo.Name
        if ($service.Status -ne 'Running') {
            Start-Service -Name $service.Name
            $service.WaitForStatus('Running', (New-TimeSpan -Seconds 30))
            Write-WatchdogLog ('Service {0} redémarré.' -f $service.DisplayName)
        }
    }

    $response = Invoke-WebRequest -Uri $AppUrl -UseBasicParsing -TimeoutSec 15
    if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 400) {
        throw ('Le site répond avec le code HTTP {0}.' -f $response.StatusCode)
    }

    Write-WatchdogLog ('Serveur opérationnel : {0}' -f $AppUrl)
    exit 0
} catch {
    Write-WatchdogLog ('ERREUR : {0}' -f $_.Exception.Message)
    exit 1
}
