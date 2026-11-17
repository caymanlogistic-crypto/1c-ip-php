$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$root=$PSScriptRoot;$path=Join-Path $root '.local/runtime.json';if(!(Test-Path -LiteralPath $path)){throw 'CURRENT_OWNED_RECEIPT_REQUIRED'};$r=Get-Content -LiteralPath $path -Raw|ConvertFrom-Json
$p=Get-CimInstance Win32_Process -Filter ('ProcessId='+$r.pid);if(!$p){'CURRENT_ALREADY_STOPPED';exit 0}
try{[long]$x=$p.CreationDate.ToUniversalTime().Ticks;[long]$y=([DateTimeOffset]$r.startUTC).UtcDateTime.Ticks}catch{throw 'CURRENT_START_IDENTITY_UNKNOWN'}
if(($x-$x%10L)-ne($y-$y%10L)-or$p.ExecutablePath.Replace('\','/').ToLowerInvariant()-cne'C:/php/php.exe'.ToLowerInvariant()-or!$p.CommandLine.Replace('\','/').ToLowerInvariant().Contains(($root+'/public/router.php').Replace('\','/').ToLowerInvariant())){throw 'CURRENT_PROCESS_IDENTITY_DIFFERS_NO_STOP'}
$listeners=@(Get-NetTCPConnection -State Listen -LocalPort $r.port -ErrorAction SilentlyContinue);if(!$listeners.Count-or@($listeners|Where-Object OwningProcess -ne $r.pid).Count){throw 'CURRENT_LISTENER_IDENTITY_DIFFERS'}
[void](Invoke-WebRequest -Uri ('http://127.0.0.1:'+$r.port+'/health') -NoProxy -TimeoutSec 30 -MaximumRedirection 0 -SkipHttpErrorCheck)
Stop-Process -Id $r.pid -ErrorAction Stop
[ordered]@{status='CURRENT_PHP_STOPPED';pid=$r.pid;databaseStopped=$false;MySQLRestart=0;filesDeleted=0}|ConvertTo-Json
