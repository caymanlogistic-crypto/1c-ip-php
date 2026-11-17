<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
// Build only from Git's committed file list, never private state or untracked work.
$sha=trim((string)shell_exec('git -C '.escapeshellarg(APP_ROOT).' rev-parse HEAD'));
if(!preg_match('/^[a-f0-9]{40}$/',$sha))throw new RuntimeException('Git SHA unavailable');
$dirty=trim((string)shell_exec('git -C '.escapeshellarg(APP_ROOT).' status --porcelain --untracked-files=no'));
$options=getopt('', ['output-dir:', 'plan']);
$directory=rtrim($options['output-dir']??APP_ROOT.'/private','/\\');
$archive=$directory.'/release-'.$sha.'.zip';
// Reserve both the source archive and ZipArchive's update copy before any write.
$sourceBytes=0;$sourceFiles=0;
$inventory=proc_open(['git','-C',APP_ROOT,'ls-tree','-r','-l','-z',$sha],[1=>['pipe','w'],2=>['pipe','w']],$inventoryPipes);
if(!is_resource($inventory))throw new RuntimeException('Cannot inspect release inventory');
$entries=stream_get_contents($inventoryPipes[1]);stream_get_contents($inventoryPipes[2]);fclose($inventoryPipes[1]);fclose($inventoryPipes[2]);
if(proc_close($inventory)!==0)throw new RuntimeException('Cannot inspect release inventory');
foreach(explode("\0",$entries)as $entry){
    if($entry==='')continue;
    if(!preg_match('/^100(?:644|755) blob [a-f0-9]{40} +([0-9]+)\t(.+)$/sD',$entry,$match))throw new RuntimeException('Release source contains unsupported file/symlink');
    if(preg_match('~^(\.local|private|runtime|uploads|\.git)/~',$match[2])||str_contains($match[2],'..'))throw new RuntimeException('Private path in committed source');
    $sourceBytes+=(int)$match[1];$sourceFiles++;
}
if(!is_file(APP_ROOT.'/vendor/autoload.php'))throw new RuntimeException('Install exact composer.lock dependencies before packaging');
$vendorBytes=0;$vendorFiles=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/vendor',FilesystemIterator::SKIP_DOTS))as $entry){
    if($entry->isLink())throw new RuntimeException('Vendor symlink rejected');
    if($entry->isFile()){$vendorBytes+=$entry->getSize();$vendorFiles++;}
}
$budget=2*($sourceBytes+$vendorBytes)+16777216;
App\StorageBudget::target($archive,$budget);
if(isset($options['plan'])){
    echo json_encode(['mode'=>'PLAN_ONLY_NO_ARCHIVE','sha'=>$sha,'source_files'=>$sourceFiles,'vendor_files'=>$vendorFiles,'uncompressed_bytes'=>$sourceBytes+$vendorBytes,'required_budget_bytes'=>$budget,'target'=>$archive,'tracked_changes'=>$dirty!=='','release_ready'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if($dirty!=='')throw new RuntimeException('Commit tracked changes before packaging');
if(file_exists($archive)||is_link($archive))throw new RuntimeException('Release artifact already exists; preserve it');
if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create private release directory');
$process=proc_open(['git','-C',APP_ROOT,'archive','--format=zip','--output='.$archive,$sha],[1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process)||proc_close($process)!==0)throw new RuntimeException('Cannot archive exact Git SHA');
$zip=new ZipArchive();if($zip->open($archive)!==true)throw new RuntimeException('Cannot open release');
$manifest=[];for($i=0;$i<$zip->numFiles;$i++){$file=$zip->getNameIndex($i);if(str_ends_with($file,'/'))continue;if(preg_match('~^(\.local|private|runtime|uploads|\.git)/~',$file)||str_contains($file,'..'))throw new RuntimeException('Private path in tracked source');$stat=$zip->statIndex($i);$attributes=0;$opsys=0;$zip->getExternalAttributesIndex($i,$opsys,$attributes);if((($attributes>>16)&0170000)===0120000)throw new RuntimeException('Release cannot include symlink');$manifest[$file]=hash('sha256',$zip->getFromIndex($i));}
if(!is_file(APP_ROOT.'/vendor/autoload.php'))throw new RuntimeException('Install exact composer.lock dependencies before packaging');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT.'/vendor',FilesystemIterator::SKIP_DOTS))as $entry){if($entry->isLink())throw new RuntimeException('Vendor symlink rejected');if(!$entry->isFile())continue;$file=str_replace('\\','/',substr($entry->getPathname(),strlen(APP_ROOT)+1));$zip->addFile($entry->getPathname(),$file);$manifest[$file]=hash_file('sha256',$entry->getPathname());}
$zip->addFromString('RELEASE-MANIFEST.json',json_encode(['sha'=>$sha,'php'=>PHP_VERSION,'composer_lock_sha256'=>hash('sha256',$zip->getFromName('composer.lock')),'files'=>$manifest,'secrets'=>false,'data'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$zip->close();echo json_encode(['sha'=>$sha,'file'=>basename($archive),'sha256'=>hash_file('sha256',$archive),'files'=>count($manifest)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
