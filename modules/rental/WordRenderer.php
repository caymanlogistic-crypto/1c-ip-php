<?php
declare(strict_types=1);
namespace Modules\Rental;

use RuntimeException;

/** Genuine isolated writer_pdf_Export. No HTML conversion, shell, global profile or process kill. */
final class WordRenderer
{
    private const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';

    private const RESERVE=512*1024*1024;
    private const JOB_BUDGET=256*1024*1024;

    public function __construct(private array $config,private mixed $runner=null,private mixed $spaceProbe=null) {}

    public static function configured(): self
    {
        $path=APP_ROOT.'/.local/word-renderer.json';
        if (!is_file($path) || is_link($path) || filesize($path)>16384) throw new RuntimeException('Обработчик Word PDF не настроен.');
        return new self(json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR));
    }

    private static function childEnvironment(string $temporary): array
    {
        $environment=getenv();
        foreach (['TMP','TEMP','TMPDIR'] as $key) $environment[$key]=$temporary;
        return $environment;
    }

    private function preflight(string $target): void
    {
        $probe=is_callable($this->spaceProbe)?$this->spaceProbe:disk_free_space(...);
        $appFree=$probe(APP_ROOT);$targetFree=$probe($target);
        if ($appFree===false || $targetFree===false || $appFree<self::RESERVE || $targetFree<self::RESERVE+self::JOB_BUDGET) {
            throw new RuntimeException('Недостаточно свободного места для обработки Word PDF.');
        }
    }

    private static function child(string $root,string $name): string
    {
        $path=$root.'/'.$name;
        if (file_exists($path) || !mkdir($path,0700)) throw new RuntimeException('Не удалось создать отдельный каталог Word PDF.');
        $resolved=realpath($path);
        if (!$resolved || is_link($path) || !str_starts_with(strtolower(str_replace('\\','/',$resolved)),strtolower(str_replace('\\','/',$root)).'/')) {
            throw new RuntimeException('Неверный каталог Word PDF.');
        }
        return str_replace('\\','/',$resolved);
    }

    private static function fileUrl(string $path): string
    {
        $path=str_replace('\\','/',$path);
        $encoded=implode('/',array_map('rawurlencode',explode('/',$path)));
        $encoded=preg_replace('/^([A-Za-z])%3A/','$1:',$encoded);
        return str_starts_with($path,'/') ? 'file://'.$encoded : 'file:///'.$encoded;
    }

    private static function run(array $arguments,array $environment,string $job): array
    {
        $descriptors=[
            0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],
            1=>['file',$job.'/stdout.log','ab'],
            2=>['file',$job.'/stderr.log','ab'],
        ];
        $process=proc_open($arguments,$descriptors,$pipes,$job,$environment,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Не удалось запустить обработчик Word PDF.',5201);
        $start=microtime(true);
        do {
            $status=proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true)-$start>45) {
                // Preserve this isolated job and report uncertainty; never terminate another renderer.
                file_put_contents($job.'/process-state.json',Importer::json([
                    'state'=>'timeout_unsettled','stage'=>'process_wait','category'=>'word-pdf-timeout',
                    'pid'=>$status['pid'],'running'=>$status['running'],'status_exit_code'=>$status['exitcode'],
                    'close_exit_code'=>null,'exit_code'=>null,'elapsed_seconds'=>round(microtime(true)-$start,3),
                    'stdout_bytes'=>is_file($job.'/stdout.log')?filesize($job.'/stdout.log'):null,
                    'stderr_bytes'=>is_file($job.'/stderr.log')?filesize($job.'/stderr.log'):null,
                ]));
                throw new RuntimeException('Обработка Word PDF не завершилась вовремя. Результат требует проверки; повтор автоматически не запускается.',5202);
            }
            usleep(100000);
        } while (true);
        $exit=$status['exitcode'];
        $closed=proc_close($process);
        if ($exit<0) $exit=$closed;
        $result=['stage'=>'process_completed','category'=>'word-pdf-process',
            'pid'=>$status['pid'],'running'=>$status['running'],
            'exit_code'=>$exit,'elapsed_seconds'=>round(microtime(true)-$start,3),
            'status_exit_code'=>$status['exitcode'],'close_exit_code'=>$closed,
            'stdout_bytes'=>is_file($job.'/stdout.log')?filesize($job.'/stdout.log'):null,
            'stderr_bytes'=>is_file($job.'/stderr.log')?filesize($job.'/stderr.log'):null];
        // Keep only bounded process facts, never arguments, environment or document contents.
        if(file_put_contents($job.'/process-result.json',Importer::json($result),LOCK_EX)===false)
            throw new RuntimeException('Не удалось сохранить результат обработчика Word PDF.',5206);
        return $result;
    }

    public function render(string $source,string $expectedSha): array
    {
        if (($this->config['project_id'] ?? '')!==self::PROJECT) throw new RuntimeException('Обработчик Word PDF принадлежит другому проекту.');
        $executable=realpath((string)($this->config['executable'] ?? ''));
        $rootPath=(string)($this->config['artifact_root'] ?? '');
        $absolute=str_replace('\\','/',$rootPath);
        if (str_contains($rootPath,"\0") || !preg_match('~^(?:[A-Za-z]:/|/)~',$absolute)
            || str_starts_with($absolute,'//') || preg_match('~(?:^|/)\.\.(?:/|$)~',$absolute)) throw new RuntimeException('Не настроен отдельный каталог Word PDF.');
        $root=realpath($rootPath);
        $pin=(string)($this->config['executable_sha256'] ?? '');
        if (!$executable || !is_file($executable) || is_link($this->config['executable']) || !preg_match('/^[a-f0-9]{64}$/D',$pin)
            || !hash_equals($pin,hash_file('sha256',$executable))) throw new RuntimeException('Обработчик Word PDF не соответствует проверенной версии.');
        $engine=dirname($executable).'/soffice.bin';
        $enginePin=(string)($this->config['executable_binary_sha256'] ?? '');
        if (!is_file($engine) || is_link($engine) || !preg_match('/^[a-f0-9]{64}$/D',$enginePin)
            || !hash_equals($enginePin,hash_file('sha256',$engine))) throw new RuntimeException('Основной обработчик Word PDF не соответствует проверенной версии.');
        if (!$root && $rootPath!=='' && !file_exists($rootPath)) {
            $parent=realpath(dirname($rootPath));
            $name=basename($rootPath);
            if ($parent && !is_link(dirname($rootPath)) && strlen(str_replace('\\','/',$parent))>12 && preg_match('/^[A-Za-z0-9._-]+$/D',$name)) {
                $this->preflight($parent);
                $root=self::child($parent,$name);
            }
        }
        if (!$root || !is_dir($root) || is_link($rootPath) || strlen(str_replace('\\','/',$root))<12) throw new RuntimeException('Не настроен отдельный каталог Word PDF.');
        $this->preflight($root);
        $sourcePath=realpath($source);
        if (!$sourcePath || is_link($source) || !preg_match('/^[a-f0-9]{64}$/D',$expectedSha)
            || !hash_equals($expectedSha,hash_file('sha256',$sourcePath))) throw new RuntimeException('Исходный Word изменился.');
        DocxTemplate::validate($sourcePath,20*1024*1024);
        $job=self::child($root,'rental-word-'.bin2hex(random_bytes(12)));
        $input=self::child($job,'input');
        $profile=self::child($job,'profile');
        $temporary=self::child($job,'temp');
        $output=self::child($job,'output');
        $copy=$input.'/document.docx';
        if (!copy($sourcePath,$copy) || !hash_equals($expectedSha,hash_file('sha256',$copy))) throw new RuntimeException('Не удалось сохранить точный Word для обработки.');
        $arguments=[
            $executable,'-env:UserInstallation='.self::fileUrl($profile),
            '--headless','--nologo','--nodefault','--norestore',
            '--convert-to','pdf:writer_pdf_Export','--outdir',$output,$copy,
        ];
        $environment=self::childEnvironment($temporary);
        $process=is_callable($this->runner)
            ? ($this->runner)($arguments,$environment,$job)
            : self::run($arguments,$environment,$job);
        $pdf=$output.'/document.pdf';
        $outputState=['stage'=>'output_checked','category'=>'word-pdf-output',
            'exit_code'=>$process['exit_code']??null,'elapsed_seconds'=>$process['elapsed_seconds']??null,
            'output_exists'=>file_exists($pdf),'output_is_regular'=>is_file($pdf),
            'output_link'=>is_link($pdf),'output_bytes'=>is_file($pdf)?filesize($pdf):null];
        if(file_put_contents($job.'/output-state.json',Importer::json($outputState),LOCK_EX)===false)
            throw new RuntimeException('Не удалось сохранить состояние Word PDF.',5206);
        if (($process['exit_code'] ?? -1)!==0) {
            throw new RuntimeException('Обработчик Word не подтвердил успешное завершение.',5203);
        }
        if (!is_file($pdf)) {
            throw new RuntimeException('Обработчик Word завершился без ожидаемого PDF.',5204);
        }
        if (is_link($pdf) || filesize($pdf)>20*1024*1024) {
            throw new RuntimeException('Обработчик Word создал недопустимый PDF.',5205);
        }
        if (!hash_equals($expectedSha,hash_file('sha256',$sourcePath)) || !hash_equals($expectedSha,hash_file('sha256',$copy))) {
            throw new RuntimeException('Исходный Word изменился при обработке.');
        }
        Documents::validateFile($pdf,'pdf');
        $receipt=[
            'schema'=>1,'project_id'=>self::PROJECT,'engine'=>'LibreOffice writer_pdf_Export',
            'executable_sha256'=>$pin,'executable_binary_sha256'=>$enginePin,'source_sha256'=>$expectedSha,'output_sha256'=>hash_file('sha256',$pdf),
            'source_bytes'=>filesize($sourcePath),'output_bytes'=>filesize($pdf),'profile'=>$profile,
            'process'=>$process,'global_environment_changed'=>false,'original_unchanged'=>true,
        ];
        $receiptPath=$job.'/receipt.json';
        if (file_put_contents($receiptPath,Importer::json($receipt),LOCK_EX)===false) throw new RuntimeException('Не удалось сохранить подтверждение Word PDF.');
        return ['path'=>$pdf,'sha256'=>$receipt['output_sha256'],'receipt_path'=>$receiptPath,'receipt'=>$receipt];
    }
}
