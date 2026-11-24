<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;
use RuntimeException;
use ZipArchive;

final class WordSecurityTerms
{
    public const FIELD='security_clause';
    private const STAMP='word_security_generation';
    private const MESSAGE='Кратность обеспечения изменилась. Сформируйте договор заново и замените прежний файл в комплекте.';
    private const GENERIC='Обеспечительный платёж и аренда учитываются раздельно.';
    private const OFFICE='5.1. Арендатор выплачивает Арендодателю Обеспечительный платеж в размере равном размеру постоянной арендной платы за один месяц аренды (кроме того НДС по ставке {{vat}}% (п.8 ст. 164 НК)) в течение 2 (двух) рабочих дней с момента подписания настоящего договора (далее – Обеспечительный платеж).';
    private const PARKING='2.2. Помимо арендной платы Арендатор одновременно с первым платежом вносит также обеспечительный платеж в размере равном размеру арендной платы за один месяц аренды, кроме того НДС {{vat}}% (п.8 ст. 164 НК).';

    public static function current(PDO $db,array $contract): ?array
    {
        $multiple=InitialInvoice::legalSecurityMultiple($db,$contract);
        if ($multiple===null) {
            if (!empty(Importer::decodeExact($contract['source_json'])['imported'])) return null;
            throw new RuntimeException('Сначала согласуйте кратность обеспечения в карточке договора.',409);
        }
        return ['multiple'=>$multiple,'amount'=>InitialInvoice::securityAmount($contract['amount'],$multiple)];
    }

    public static function legacyClause(string $kind): ?string
    {
        return match($kind) {
            'office'=>self::OFFICE,
            'parking'=>self::PARKING,
            default=>null,
        };
    }

    private static function sourceClause(array $source): ?string
    {
        $kind=$source['kind'];
        if (isset(TemplateAssets::KINDS[$kind]) && hash_equals(TemplateAssets::KINDS[$kind]['sha256'],$source['sha256'])) {
            return self::GENERIC;
        }
        $clause=self::legacyClause($kind);
        if ($clause===null) return null;
        if (!empty($source['owner_security_literal'])) return $clause;
        return hash_equals(OriginalDocuments::catalog()[$kind]['sha256'],$source['sha256'])?$clause:null;
    }

    public static function compatible(array $source): bool
    {
        return in_array(self::FIELD,$source['fields'],true) || self::sourceClause($source)!==null;
    }

    public static function wording(array $source,?array $security): array
    {
        if ($security===null) return ['values'=>[],'phrases'=>[]];
        $sourceClause=self::sourceClause($source);
        $multiple=$security['multiple'];
        $zero='Обеспечительный платёж по настоящему Договору не предусмотрен. Условия Договора об обеспечительном платеже не применяются.';
        $amount=Documents::amountText($security['amount']).' руб. с НДС (кратность к месячной арендной плате — '.$multiple.')';
        $values=[self::FIELD=>$multiple===0?$zero:'Обеспечительный платёж составляет '.$amount.'.'];
        $replacement=match($sourceClause) {
            self::OFFICE => $multiple===0 ? '5.1. '.$zero : '5.1. Арендатор вносит Арендодателю обеспечительный платёж '
                .'в размере '.$amount.' в течение 2 (двух) рабочих дней с момента подписания настоящего Договора.',
            self::PARKING => $multiple===0 ? '2.2. '.$zero : '2.2. Помимо арендной платы Арендатор одновременно с первым платежом '
                .'вносит обеспечительный платёж в размере '.$amount.'.',
            self::GENERIC => $multiple===0 ? $zero : $values[self::FIELD].' '.self::GENERIC,
            default => null,
        };
        return ['values'=>$values,'phrases'=>$replacement===null?[]:[$sourceClause=>$replacement]];
    }

    public static function matches(?array $expected,?array $actual): bool
    {
        if ($expected===null) return true;
        return ($actual['multiple']??null)===$expected['multiple'] && ($actual['amount']??null)===$expected['amount'];
    }

    public static function remember(PDO $db,array $contract,string $digest,?array $security): void
    {
        if ($security===null) return;
        $key=$contract['id'].':'.$digest;
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,0)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")
            ->execute([self::STAMP,$key,Importer::json($security+['version'=>(int)$contract['version']])]);
    }

    private static function known(PDO $db,array $contract,string $digest): ?array
    {
        $query=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=?');
        $query->execute([self::STAMP,$contract['id'].':'.$digest]);
        if ($proof=$query->fetchColumn()) return Importer::decodeExact($proof);
        $query=$db->prepare("SELECT 1 FROM rental_files WHERE entity_type='contract' AND entity_id=? AND sha256=?
            AND kind IN ('word-office','word-parking','word-mixed','word-office-person','word-parking-person','word-apartment-person') LIMIT 1");
        $query->execute([$contract['id'],$digest]);
        if ($query->fetchColumn()) return [];
        $query=$db->prepare("SELECT 1 FROM audit_log WHERE action='document.generate' AND entity_type='contract'
            AND entity_id=? AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.sha256'))=? LIMIT 1");
        $query->execute([(string)$contract['id'],$digest]);
        return $query->fetchColumn() ? [] : null;
    }

    private static function archivePrimaryDigest(array $file): ?string
    {
        $zip=new ZipArchive();
        $path=Documents::directory().'/'.basename($file['path']);
        if ($zip->open($path,ZipArchive::RDONLY)!==true) return null;
        try {
            // The application puts exactly the contract first in its own ZIPs.
            $primary=$zip->statIndex(0);
            if (!$primary || $primary['size']>20*1024*1024 || !str_ends_with($primary['name'],'.docx')) return null;
            $name=pathinfo($primary['name'],PATHINFO_FILENAME);
            if (!str_contains($name,' №')) return null;
            if (!in_array(explode(' №',$name,2)[0],array_intersect_key(OriginalDocuments::TITLES,
                array_flip(['office','parking','mixed','office-person','parking-person','apartment-person'])),true)) return null;
            $stream=$zip->getStreamIndex(0);
            if ($stream===false) return null;
            try {
                $context=hash_init('sha256');
                $count=hash_update_stream($context,$stream,$primary['size']+1);
                return $count===$primary['size']?hash_final($context):null;
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    public static function rememberDerivative(PDO $db,array $contract,string $sourceDigest,string $digest): void
    {
        $proof=self::known($db,$contract,$sourceDigest);
        if ($proof && ($proof['version']??null)===(int)$contract['version']) self::remember($db,$contract,$digest,$proof);
    }

    public static function verifyFile(PDO $db,array $contract,array $file,?array $security=null): void
    {
        $proof=self::known($db,$contract,$file['sha256']);
        if ($proof===null && $file['mime']==='application/zip' && ($primary=self::archivePrimaryDigest($file))!==null) {
            $proof=self::known($db,$contract,$primary);
        }
        if ($proof===null && $file['mime']==='application/pdf') {
            $query=$db->prepare("SELECT baseline_hash FROM rental_files
                WHERE entity_type='contract' AND entity_id=? AND sha256=? AND kind='word-pdf' LIMIT 1");
            $query->execute([$contract['id'],$file['sha256']]);
            if ($sourceDigest=$query->fetchColumn()) $proof=self::known($db,$contract,$sourceDigest);
        }
        if ($proof===null) return;
        $security??=self::current($db,$contract);
        if ($security===null) return;
        if (!self::matches($security,$proof) || ($proof['version']??null)!==(int)$contract['version']) {
            throw new RuntimeException(self::MESSAGE,409);
        }
    }
}
