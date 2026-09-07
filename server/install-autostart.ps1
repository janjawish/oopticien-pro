[CmdletBinding()]
param(
    [string]$XamppPath = 'C:\xampp',
    [ValidateRange(1, 65535)]
    [int]$HttpPort = 80,
    [switch]$KeepAwake
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Find-XamppService {
    param([string]$RelativeExecutable)

    $expectedPath = (Join-Path $script:XamppRoot $RelativeExecutable).ToLowerInvariant()
    Get-CimInstance -ClassName Win32_Service | Where-Object {
        $servicePath = ([string]$_.PathName).Replace('/', '\').ToLowerInvariant()
        $servicePath.Contains($expectedPath)
    } | Select-Object -First 1
}

function Invoke-ServiceInstaller {
    param(
        [string]$Executable,
        [string[]]$Arguments,
        [string]$WorkingDirectory
    )

    $process = Start-Process -FilePath $Executable -ArgumentList $Arguments -WorkingDirectory $WorkingDirectory -WindowStyle Hidden -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        throw ('Échec de l’installation du service avec {0} (code {1}).' -f $Executable, $process.ExitCode)
    }
}

if (-not (Test-Administrator)) {
    throw 'Ce script doit être lancé dans PowerShell avec « Exécuter en tant qu’administrateur ».'
}

$script:XamppRoot = [IO.Path]::GetFullPath($XamppPath).TrimEnd('\')
$apacheExecutable = Join-Path $script:XamppRoot 'apache\bin\httpd.exe'
$mysqlExecutable = Join-Path $script:XamppRoot 'mysql\bin\mysqld.exe'
$mysqlConfiguration = Join-Path $script:XamppRoot 'mysql\bin\my.ini'
$requiredFiles = @($apacheExecutable, $mysqlExecutable, $mysqlConfiguration)
foreach ($requiredFile in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw ('Fichier XAMPP introuvable : {0}' -f $requiredFile)
    }
}

$apacheService = Find-XamppService 'apache\bin\httpd.exe'
if ($null -eq $apacheService) {
    $existingApache = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
    if ($null -ne $existingApache) {
        throw 'Un service Apache2.4 existe déjà mais ne correspond pas à ce dossier XAMPP.'
    }
    Invoke-ServiceInstaller -Executable $apacheExecutable -Arguments @('-k', 'install', '-n', 'Apache2.4') -WorkingDirectory (Split-Path $apacheExecutable)
    $apacheService = Find-XamppService 'apache\bin\httpd.exe'
}

$mysqlService = Find-XamppService 'mysql\bin\mysqld.exe'
if ($null -eq $mysqlService) {
    $existingMysql = Get-Service -Name 'mysql' -ErrorAction SilentlyContinue
    if ($null -ne $existingMysql) {
        throw 'Un service mysql existe déjà mais ne correspond pas à ce dossier XAMPP.'
    }
    Invoke-ServiceInstaller -Executable $mysqlExecutable -Arguments @('--install', 'mysql', ('--defaults-file={0}' -f $mysqlConfiguration)) -WorkingDirectory (Split-Path $mysqlExecutable)
    $mysqlService = Find-XamppService 'mysql\bin\mysqld.exe'
}

if ($null -eq $apacheService -or $null -eq $mysqlService) {
    throw 'Impossible de retrouver les services Apache et MariaDB après leur installation.'
}

foreach ($serviceInfo in @($mysqlService, $apacheService)) {
    Set-Service -Name $serviceInfo.Name -StartupType Automatic
    & sc.exe failure $serviceInfo.Name 'reset=' '86400' 'actions=' 'restart/60000/restart/60000/restart/60000' | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw ('Impossible de configurer la relance automatique du service {0}.' -f $serviceInfo.Name)
    }
    $service = Get-Service -Name $serviceInfo.Name
    if ($service.Status -ne 'Running') {
        Start-Service -Name $serviceInfo.Name
        $service.WaitForStatus('Running', (New-TimeSpan -Seconds 30))
    }
}

$firewallRuleName = 'Oopticien Pro - HTTP LAN - port {0}' -f $HttpPort
$existingRule = Get-NetFirewallRule -DisplayName $firewallRuleName -ErrorAction SilentlyContinue
if ($null -eq $existingRule) {
    New-NetFirewallRule `
        -DisplayName $firewallRuleName `
        -Description 'Autorise uniquement les postes du réseau local à ouvrir Oopticien Pro.' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort $HttpPort `
        -RemoteAddress LocalSubnet `
        -Profile Private | Out-Null
} else {
    Set-NetFirewallRule -DisplayName $firewallRuleName -Enabled True -Profile Private -Action Allow | Out-Null
}

$programDirectory = Join-Path $env:ProgramData 'OopticienPro'
New-Item -ItemType Directory -Path $programDirectory -Force | Out-Null
$watchdogSource = Join-Path $PSScriptRoot 'oopticien-server-watchdog.ps1'
$watchdogTarget = Join-Path $programDirectory 'oopticien-server-watchdog.ps1'
if (-not (Test-Path -LiteralPath $watchdogSource -PathType Leaf)) {
    throw ('Script de vérification introuvable : {0}' -f $watchdogSource)
}
Copy-Item -LiteralPath $watchdogSource -Destination $watchdogTarget -Force

$localUrl = 'http://127.0.0.1:{0}/oopticien-pro/' -f $HttpPort
$taskArguments = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -XamppPath "{1}" -AppUrl "{2}"' -f $watchdogTarget, $script:XamppRoot, $localUrl
$taskAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $taskArguments
$taskTrigger = New-ScheduledTaskTrigger -AtStartup
$taskTrigger.Delay = 'PT30S'
$taskSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Minutes 5)
Register-ScheduledTask `
    -TaskName 'Oopticien Pro - Vérification serveur' `
    -Description 'Vérifie Apache, MariaDB et Oopticien Pro après chaque démarrage Windows.' `
    -Action $taskAction `
    -Trigger $taskTrigger `
    -Settings $taskSettings `
    -User 'SYSTEM' `
    -RunLevel Highest `
    -Force | Out-Null

$phpExecutable = Join-Path $script:XamppRoot 'php\php.exe'
$applicationRoot = Split-Path $PSScriptRoot -Parent
$automationScript = Join-Path $applicationRoot 'cron\run_automation.php'
if (-not (Test-Path -LiteralPath $phpExecutable -PathType Leaf)) {
    throw ('PHP introuvable : {0}' -f $phpExecutable)
}
if (-not (Test-Path -LiteralPath $automationScript -PathType Leaf)) {
    throw ('Moteur automatique introuvable : {0}' -f $automationScript)
}
$automationAction = New-ScheduledTaskAction -Execute $phpExecutable -Argument ('"{0}"' -f $automationScript) -WorkingDirectory $applicationRoot
$automationTrigger = New-ScheduledTaskTrigger -Daily -At '00:00'
$automationTrigger.Repetition.Interval = 'PT15M'
$automationTrigger.Repetition.Duration = 'P1D'
$automationSettings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
Register-ScheduledTask `
    -TaskName 'Oopticien Pro - Automatisations' `
    -Description 'Toutes les 15 minutes : statuts PEC, relances, paiements et bons de livraison reçus par e-mail.' `
    -Action $automationAction `
    -Trigger $automationTrigger `
    -Settings $automationSettings `
    -User 'SYSTEM' `
    -RunLevel Highest `
    -Force | Out-Null

if ($KeepAwake) {
    & powercfg.exe /change standby-timeout-ac 0
    & powercfg.exe /change hibernate-timeout-ac 0
}

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $watchdogTarget -XamppPath $script:XamppRoot -AppUrl $localUrl
if ($LASTEXITCODE -ne 0) {
    throw 'Les services sont installés, mais le contrôle final du site a échoué. Consultez C:\ProgramData\OopticienPro\server-watchdog.log.'
}

$activeIpv4 = Get-NetIPAddress -AddressFamily IPv4 | Where-Object {
    $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*'
} | Select-Object -ExpandProperty IPAddress

Write-Host ''
Write-Host 'Oopticien Pro est configuré pour démarrer automatiquement.' -ForegroundColor Green
Write-Host ('Adresse locale : {0}' -f $localUrl)
foreach ($ipAddress in $activeIpv4) {
    Write-Host ('Adresse réseau possible : http://{0}:{1}/oopticien-pro/' -f $ipAddress, $HttpPort)
}
Write-Host 'Réservez ensuite cette adresse IP dans le routeur de la boutique.' -ForegroundColor Yellow
Write-Host 'Les automatisations métier sont exécutées toutes les 15 minutes.' -ForegroundColor Green
