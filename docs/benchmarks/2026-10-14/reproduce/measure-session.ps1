$ErrorActionPreference='Stop'
$taskRoot=(Get-Location).Path
$taskOutput=Join-Path $taskRoot '.cache/resource-probe-live'
New-Item -ItemType Directory -Path $taskOutput -Force | Out-Null
if(Test-Path -LiteralPath (Join-Path $taskOutput 'php.jsonl')){throw 'Measurement output already exists; use a new run directory'}
$taskInfo=[System.Diagnostics.ProcessStartInfo]::new()
$taskInfo.FileName='C:\xampp\php\php.exe'
$taskInfo.WorkingDirectory=$taskRoot
$taskInfo.UseShellExecute=$false
$taskInfo.CreateNoWindow=$true
$taskInfo.RedirectStandardOutput=$true
$taskInfo.RedirectStandardError=$true
foreach($arg in @('-c',(Join-Path $taskRoot '.dev/php.ini'),'-d','xdebug.mode=off',(Join-Path $taskRoot '.cache/session-resource-probe.php'))){$taskInfo.ArgumentList.Add($arg)}
$taskInfo.Environment['XDEBUG_MODE']='off'
$taskProcess=[System.Diagnostics.Process]::new()
$taskProcess.StartInfo=$taskInfo
[void]$taskProcess.Start()
$taskStdout=$taskProcess.StandardOutput.ReadToEndAsync()
$taskStderr=$taskProcess.StandardError.ReadToEndAsync()
$taskTimer=[System.Diagnostics.Stopwatch]::StartNew()
$taskSamples=[System.Collections.Generic.List[object]]::new()
$taskCores=[Environment]::ProcessorCount
$taskLastPhase=''
try{
    while(-not $taskProcess.HasExited){
        $taskProcess.Refresh()
        $taskPhase='starting'
        $taskMetrics=Join-Path $taskOutput 'php.jsonl'
        if(Test-Path -LiteralPath $taskMetrics){
            $taskLast=Get-Content -LiteralPath $taskMetrics -Tail 1
            if($taskLast){$taskPhase=($taskLast|ConvertFrom-Json).phase}
        }
        $taskSamples.Add([pscustomobject]@{
            elapsed_seconds=$taskTimer.Elapsed.TotalSeconds
            cpu_seconds=$taskProcess.TotalProcessorTime.TotalSeconds
            working_set_bytes=$taskProcess.WorkingSet64
            private_bytes=$taskProcess.PrivateMemorySize64
            phase=$taskPhase
        })
        if($taskPhase-ne$taskLastPhase){Write-Output ('Probe phase: '+$taskPhase);$taskLastPhase=$taskPhase}
        if($taskTimer.Elapsed.TotalSeconds-gt185){
            [System.IO.File]::WriteAllText((Join-Path $taskOutput 'stop'),'stop')
            if($taskTimer.Elapsed.TotalSeconds-gt210){$taskProcess.Kill();throw 'Owned measurement worker did not stop within its bound'}
        }
        Start-Sleep -Milliseconds 1000
    }
    $taskProcess.WaitForExit()
    $taskSamples|ConvertTo-Json -Depth 4|Set-Content -LiteralPath (Join-Path $taskOutput 'os.json') -Encoding utf8
    [System.IO.File]::WriteAllText((Join-Path $taskOutput 'stdout.txt'),$taskStdout.GetAwaiter().GetResult())
    [System.IO.File]::WriteAllText((Join-Path $taskOutput 'stderr.txt'),$taskStderr.GetAwaiter().GetResult())
    [pscustomobject]@{exit_code=$taskProcess.ExitCode;logical_processors=$taskCores;samples=$taskSamples.Count;elapsed_seconds=[math]::Round($taskTimer.Elapsed.TotalSeconds,3)}|ConvertTo-Json -Compress
}finally{
    if(-not $taskProcess.HasExited){[System.IO.File]::WriteAllText((Join-Path $taskOutput 'stop'),'stop')}
    $taskProcess.Dispose()
}
