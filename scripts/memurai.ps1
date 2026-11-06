# StoneERP-AI-Assistant — project-local Memurai (Redis-compatible) helper
# Does not install services or modify global PATH.
#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = (Resolve-Path (Join-Path $ScriptDir '..')).Path
$MemuraiHome = Join-Path $ProjectRoot 'tools\memurai'
$MemuraiExe = Join-Path $MemuraiHome 'memurai.exe'
$MemuraiConf = Join-Path $MemuraiHome 'memurai.conf'
$MemuraiCli = Join-Path $MemuraiHome 'memurai-cli.exe'
$RunDir = Join-Path $MemuraiHome 'run'
$PidFile = Join-Path $RunDir 'memurai.pid'

function Get-ExpectedMemuraiExecutablePath {
    if (-not (Test-Path -LiteralPath $MemuraiExe)) {
        throw "Project Memurai executable not found: $MemuraiExe"
    }

    return (Get-Item -LiteralPath $MemuraiExe).FullName
}

function Get-ProjectMemuraiProcesses {
    $expected = Get-ExpectedMemuraiExecutablePath

    Get-CimInstance Win32_Process -Filter "Name='memurai.exe'" | Where-Object {
        if (-not $_.ExecutablePath) {
            return $false
        }
        try {
            $procPath = (Get-Item -LiteralPath $_.ExecutablePath -ErrorAction Stop).FullName
        } catch {
            return $false
        }

        return ($procPath -eq $expected)
    }
}

function Test-MemuraiPortListening {
    $connections = @(Get-NetTCPConnection -LocalPort 6379 -State Listen -ErrorAction SilentlyContinue |
        Where-Object { $_.LocalAddress -eq '127.0.0.1' })

    return ($connections.Count -gt 0)
}

function Test-MemuraiPing {
    if (-not (Test-Path -LiteralPath $MemuraiCli)) {
        return $false
    }

    $output = & $MemuraiCli -h 127.0.0.1 -p 6379 PING 2>&1 | Out-String

    return ($output -match 'PONG')
}

function Write-ProjectMemuraiPidFile {
    param([Parameter(Mandatory = $true)][int]$ProcessId)

    $expected = Get-ExpectedMemuraiExecutablePath
    $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$ProcessId" -ErrorAction SilentlyContinue
    if (-not $proc -or -not $proc.ExecutablePath) {
        return
    }

    try {
        $procPath = (Get-Item -LiteralPath $proc.ExecutablePath -ErrorAction Stop).FullName
    } catch {
        return
    }

    if ($procPath -ne $expected) {
        return
    }

    $null = New-Item -ItemType Directory -Force -Path $RunDir
    Set-Content -Path $PidFile -Value $ProcessId -Encoding ascii -NoNewline
}

function Remove-ProjectMemuraiPidFile {
    if (Test-Path -LiteralPath $PidFile) {
        Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
    }
}

function Start-ProjectMemurai {
    if (-not (Test-Path -LiteralPath $MemuraiConf)) {
        throw "Memurai configuration not found: $MemuraiConf"
    }

    $existing = @(Get-ProjectMemuraiProcesses)
    if ($existing.Count -gt 0) {
        $pidList = ($existing | ForEach-Object { $_.ProcessId }) -join ', '
        Write-Host "Project Memurai is already running (PID: $pidList) on 127.0.0.1:6379."
        if (Test-MemuraiPing) {
            Write-Host 'PING: PONG'
        } else {
            Write-Warning 'Process exists but PING did not return PONG yet.'
        }
        return
    }

    if (Test-MemuraiPortListening) {
        throw @(
            '127.0.0.1:6379 is already in use, but not by this project''s memurai.exe.',
            'Refusing to start a second server.',
            'Identify the process with Task Manager or: Get-NetTCPConnection -LocalPort 6379 | Select OwningProcess',
            'Stop it manually if it is safe to do so.'
        ) -join "`n"
    }

    $null = New-Item -ItemType Directory -Force -Path $RunDir

    $process = Start-Process -FilePath $MemuraiExe `
        -ArgumentList @($MemuraiConf) `
        -WorkingDirectory $MemuraiHome `
        -WindowStyle Hidden `
        -PassThru

    $deadline = (Get-Date).AddSeconds(15)
    $ready = $false
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 500
        if (Test-MemuraiPing) {
            $ready = $true
            break
        }
    }

    if (-not $ready) {
        throw "Memurai failed to become reachable on 127.0.0.1:6379 within 15 seconds. Check: $MemuraiHome\memurai-log.txt"
    }

    $running = @(Get-ProjectMemuraiProcesses)
    if ($running.Count -gt 0) {
        Write-ProjectMemuraiPidFile -ProcessId $running[0].ProcessId
    }

    Write-Host "Project Memurai started on 127.0.0.1:6379 (PID: $($process.Id))."
    Write-Host 'PING: PONG'
}

function Stop-ProjectMemurai {
    $processes = @(Get-ProjectMemuraiProcesses)

    if ($processes.Count -eq 0 -and (Test-Path -LiteralPath $PidFile)) {
        $storedPid = (Get-Content -LiteralPath $PidFile -Raw).Trim()
        if ($storedPid -match '^\d+$') {
            $candidate = Get-CimInstance Win32_Process -Filter "ProcessId=$storedPid" -ErrorAction SilentlyContinue
            if ($candidate -and $candidate.ExecutablePath) {
                try {
                    $expected = Get-ExpectedMemuraiExecutablePath
                    $candidatePath = (Get-Item -LiteralPath $candidate.ExecutablePath -ErrorAction Stop).FullName
                    if ($candidatePath -eq $expected) {
                        $processes = @($candidate)
                    } else {
                        Write-Warning "PID file points to a non-project process; ignoring PID file."
                    }
                } catch {
                    Write-Warning "PID file is stale or invalid; ignoring PID file."
                }
            }
        }
    }

    if ($processes.Count -eq 0) {
        if (Test-MemuraiPortListening) {
            Write-Host @(
                'No project Memurai process was found (tools\memurai\memurai.exe).',
                'Port 6379 is listening, but this script will not stop processes by port alone.',
                'If you started Memurai manually, close that console or stop the process you started.',
                'Optional: tools\memurai\memurai-cli.exe -h 127.0.0.1 -p 6379 SHUTDOWN (only if you know this is your dev instance).'
            ) -join "`n"
            exit 1
        }

        Write-Host 'Project Memurai is not running.'
        Remove-ProjectMemuraiPidFile
        return
    }

    if (Test-MemuraiPing) {
        $null = & $MemuraiCli -h 127.0.0.1 -p 6379 SHUTDOWN 2>&1
    }

    $deadline = (Get-Date).AddSeconds(10)
    while ((Get-Date) -lt $deadline) {
        $processes = @(Get-ProjectMemuraiProcesses)
        if ($processes.Count -eq 0) {
            break
        }
        Start-Sleep -Milliseconds 400
    }

    $processes = @(Get-ProjectMemuraiProcesses)
    foreach ($proc in $processes) {
        Stop-Process -Id $proc.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Start-Sleep -Milliseconds 500
    $remaining = @(Get-ProjectMemuraiProcesses)
    if ($remaining.Count -gt 0) {
        throw "Failed to stop project Memurai (PID: $(($remaining | ForEach-Object ProcessId) -join ', '))."
    }

    Remove-ProjectMemuraiPidFile
    Write-Host 'Project Memurai stopped.'
}

function Show-ProjectMemuraiStatus {
    $expected = Get-ExpectedMemuraiExecutablePath
    $processes = @(Get-ProjectMemuraiProcesses)

    Write-Host '=== Project Memurai (tools\memurai) ==='
    Write-Host "Executable: $expected"
    Write-Host "Config: $MemuraiConf"

    if ($processes.Count -gt 0) {
        $pidList = ($processes | ForEach-Object { $_.ProcessId }) -join ', '
        Write-Host "Process: RUNNING (PID: $pidList)"
    } else {
        Write-Host 'Process: NOT RUNNING (project memurai.exe)'
    }

    Write-Host '=== Port 127.0.0.1:6379 ==='
    if (Test-MemuraiPortListening) {
        Write-Host 'State: LISTENING'
        Get-NetTCPConnection -LocalPort 6379 -State Listen -ErrorAction SilentlyContinue |
            Where-Object { $_.LocalAddress -eq '127.0.0.1' } |
            Format-Table LocalAddress, LocalPort, State, OwningProcess -AutoSize
    } else {
        Write-Host 'State: NOT LISTENING'
    }

    Write-Host '=== PING ==='
    if (Test-MemuraiPing) {
        Write-Host 'PONG'
    } else {
        Write-Host 'PING failed or server unreachable.'
        if ($processes.Count -eq 0 -and (Test-MemuraiPortListening)) {
            Write-Host 'Note: something else may be bound to 127.0.0.1:6379.'
        }
        exit 1
    }
}

$action = $args[0]
switch ($action) {
    'start' { Start-ProjectMemurai }
    'stop' { Stop-ProjectMemurai }
    'status' { Show-ProjectMemuraiStatus }
    default {
        Write-Host @"
Usage:
  .\scripts\memurai.ps1 start
  .\scripts\memurai.ps1 stop
  .\scripts\memurai.ps1 status

Or use the matching .cmd wrappers from the project root.
"@
        exit 1
    }
}
