param([ValidateRange(1024,65535)][int]$Port=8766,[switch]$NoBrowser,[switch]$Plan)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$root=$PSScriptRoot;$php='C:/php/php.exe';$receipt=Join-Path $root '.local/runtime.json'
function Open-LocalBrowser {
 $chrome='C:/Program Files/Google/Chrome/Application/chrome.exe'
 if(!(Test-Path -LiteralPath $chrome)){throw 'T001_BROWSER_MISSING_USE_ISOLATED_PROFILE'}
 $profile=Join-Path $root '.local/browser-profile'
 Start-Process -FilePath $chrome -ArgumentList @('--user-data-dir="'+$profile+'"','--no-first-run','--no-default-browser-check',('http://127.0.0.1:'+$Port))
}
function Same-Time($a,$b){try{[long]$x=([DateTimeOffset]$a).UtcDateTime.Ticks;[long]$y=([DateTimeOffset]$b).UtcDateTime.Ticks;return($x-$x%10L)-eq($y-$y%10L)}catch{return $false}}
function Owned($process,$record){return $process-and$process.ProcessId-eq$record.pid-and$process.ExecutablePath.Replace('\','/').ToLowerInvariant()-eq$php.ToLowerInvariant()-and(Same-Time $process.CreationDate.ToUniversalTime().ToString('o') $record.startUTC)-and$process.CommandLine.Replace('\','/').ToLowerInvariant().Contains(($root+'/public/router.php').Replace('\','/').ToLowerInvariant())}
if($Plan){[ordered]@{root=$root;php=$php;port=$Port;databaseConfig=(Join-Path $root '.local/database.php');setup=0;restore=0;MySQLRestart=0}|ConvertTo-Json;exit 0}
if(!(Test-Path -LiteralPath (Join-Path $root '.local/database.php'))-or!(Test-Path -LiteralPath (Join-Path $root 'vendor/autoload.php'))){throw 'CURRENT_CONFIG_OR_VENDOR_MISSING'}
$listener=@(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
if($listener.Count){
 if(!(Test-Path -LiteralPath $receipt)){throw 'PORT_BUSY_FOREIGN_NO_START'};$record=Get-Content -LiteralPath $receipt -Raw|ConvertFrom-Json;$process=Get-CimInstance Win32_Process -Filter ('ProcessId='+$record.pid)
 if($record.port-ne$Port-or@($listener|Where-Object OwningProcess -ne $record.pid).Count-or!(Owned $process $record)){throw 'PORT_BUSY_FOREIGN_NO_START'}
 if(!$NoBrowser){Open-LocalBrowser};[ordered]@{status='CURRENT_ALREADY_RUNNING';pid=$record.pid;port=$Port}|ConvertTo-Json;exit 0
}
foreach($name in @([Environment]::GetEnvironmentVariables('Process').Keys)){if($name-like'IPPHP_*'-or$name-like'DB_*'-or$name-like'APP_*ADMISSION*'-or$name-like'APP_INTEGRATION_*'-or$name-eq'APP_STAND'-or$name-eq'APP_LOCAL_SESSION_NAME'){if(Test-Path -LiteralPath ('Env:'+$name)){Remove-Item -LiteralPath ('Env:'+$name)}}}
$sessionHash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes(($root.ToLowerInvariant()+':'+$Port)))).Substring(0,16).ToLowerInvariant()
$env:APP_LOCAL_SESSION_NAME='IPPHPSESSID_'+$sessionHash
$env:APP_DB_CONFIG_FILE=Join-Path $root '.local/database.php';$env:APP_ARTIFACTS_ROOT=Join-Path $root 'artifacts'
$settings=Get-Content -LiteralPath (Join-Path $root '.local/runtime-settings.json') -Raw|ConvertFrom-Json
if($settings.port-ne$Port){throw 'T001_PORT_BINDING'}
if($settings.project_id-cne'f6e21e3c-d974-4604-82fb-333da66559d2'-or$settings.app_root.Replace('\','/').ToLowerInvariant()-cne$root.Replace('\','/').ToLowerInvariant()){throw 'CURRENT_SETTINGS_BINDING'}
if($settings.billing_part_admission_sha256){$env:APP_BILLING_PART_ADMISSION_SHA256=$settings.billing_part_admission_sha256}
if($settings.max_enabled){$marker=Join-Path $root '.local/rental-max-postcommit.json';if((Get-FileHash -LiteralPath $marker -Algorithm SHA256).Hash.ToLowerInvariant()-cne$settings.max_marker_sha256){throw 'CURRENT_MAX_MARKER_PIN'};$env:IPPHP_RENTAL_MAX_POSTCOMMIT='owner-authorized-new-test-workflow-events';$env:IPPHP_RENTAL_MAX_POSTCOMMIT_MARKER_SHA256=$settings.max_marker_sha256}
foreach($name in @('TEMP','TMP','TMPDIR')){[Environment]::SetEnvironmentVariable($name,(Join-Path $root 'runtime/tmp'),'Process')}
Set-Location -LiteralPath $root;& $php bin/local-preflight.php;if($LASTEXITCODE-ne0){throw 'T001_LOCAL_PREFLIGHT_FAILED'};$raw=& $php bin/runtime-flags.php;if($LASTEXITCODE-ne0){throw 'CURRENT_RUNTIME_FLAGS_FAILED'};$flags=@($raw|ConvertFrom-Json)
$args=@($flags)+@('-d','max_execution_time=0','-d',('session.save_path='+(Join-Path $root 'runtime/sessions')),'-S',('127.0.0.1:'+$Port),'-t',(Join-Path $root 'public'),(Join-Path $root 'public/router.php'))
$quotedArgs=@($args | ForEach-Object { '"'+$_+'"' });$process=Start-Process -FilePath $php -ArgumentList $quotedArgs -WorkingDirectory $root -WindowStyle Hidden -PassThru
$deadline=[DateTime]::UtcNow.AddSeconds(10);do{Start-Sleep -Milliseconds 200;$native=Get-CimInstance Win32_Process -Filter ('ProcessId='+$process.Id);$listener=@(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)}while(!$listener.Count-and[DateTime]::UtcNow-lt$deadline)
if(!$native-or!$listener.Count-or@($listener|Where-Object OwningProcess -ne $process.Id).Count){throw 'CURRENT_START_UNKNOWN_NO_RETRY'}
$record=[ordered]@{pid=$process.Id;port=$Port;startUTC=$native.CreationDate.ToUniversalTime().ToString('o');appRoot=$root;php=$php;mode='T001; external access and AutoMAX disabled; no workers'};[IO.File]::WriteAllText($receipt,($record|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
$health=Invoke-WebRequest -Uri ('http://127.0.0.1:'+$Port+'/health') -NoProxy -MaximumRedirection 0 -TimeoutSec 15 -SkipHttpErrorCheck;$data=$health.Content|ConvertFrom-Json
if($health.StatusCode-ne200-or$data.database-ne$true){throw 'CURRENT_DATABASE_NOT_READY_SERVER_OWNED'}
if(!$NoBrowser){Open-LocalBrowser};[ordered]@{status='CURRENT_READY';pid=$process.Id;port=$Port;HTTP=200;database=$true;setup=0;MySQLRestart=0}|ConvertTo-Json
