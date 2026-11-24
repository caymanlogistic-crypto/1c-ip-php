<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use PDO;
use RuntimeException;

final class OwnerWordTemplates
{
    public const REPLACE='template.replace';
    public const MAX_BYTES=5*1024*1024;
    private const NS='owner_word_template';
    private const REQUIRED=[
        'office'=>['number','date_words','party_intro','office_subjects','rent_object_rates','total','office_plan'],
        'parking'=>['number','date_words','party_intro','parking_subjects','rent_object_rates','total'],
        'services'=>['service_number','service_date_words','party_full_name','room_numbers','service_gross_words'],
        'handover'=>['number','act_date_words','party_act_intro','party_act_signature','room_numbers'],
        'room-change'=>['number','addendum_date_words','party_full_name','building_room_numbers','clause_reference'],
        'parking-handover'=>['number','act_date_words','party_act_intro','party_act_signature','room_numbers'],
    ];

    public static function digest(PDO $db,string $kind): string
    {
        $definition=self::definition($kind);
        $query=$db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.sha256')) FROM rental_facts
            WHERE namespace=? AND source_key=? AND operative=1");
        $query->execute([self::NS,$kind]);
        return $query->fetchColumn() ?: $definition['sha256'];
    }

    private static function definition(string $kind): array
    {
        return OriginalDocuments::catalog()[$kind] ?? throw new RuntimeException('Шаблон не найден.',404);
    }

    public static function current(PDO $db,string $kind): ?array
    {
        $definition=self::definition($kind);
        $query=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute([self::NS,$kind]);
        $stored=$query->fetchColumn();
        if ($stored===false) return null;
        $source=Importer::decodeExact($stored);
        $bytes=base64_decode($source['content'],true);
        if ($bytes===false || strlen($bytes)>self::MAX_BYTES || !hash_equals($source['sha256'],hash('sha256',$bytes))) {
            throw new RuntimeException('Текущий Word-шаблон повреждён.',409);
        }
        unset($source['content']);
        return $source+['bytes'=>$bytes,'filename'=>$definition['filename'],'kind'=>$kind,'template_id'=>0,'template_version'=>0];
    }

    public static function withFile(array $source,callable $callback): mixed
    {
        if (isset($source['path'])) return $callback($source['path']);
        $path=tempnam(sys_get_temp_dir(),'owner-word-');
        if ($path===false) throw new RuntimeException('Не удалось подготовить Word-шаблон.');
        try {
            if (file_put_contents($path,$source['bytes'])!==strlen($source['bytes'])) {
                throw new RuntimeException('Не удалось прочитать Word-шаблон.');
            }
            return $callback($path);
        } finally {
            unlink($path);
        }
    }

    public static function replace(PDO $db,array $input): array
    {
        Access::pages(['templates'],2);
        Service::roles(['lawyer']);
        $kind=(string)($input['kind']??'');
        $expected=(string)($input['expected_sha256']??'');
        $old=self::digest($db,$kind);
        $content=$input['content']??null;
        if (!is_string($content) || strlen($content)>4*(int)ceil(self::MAX_BYTES/3)
            || ($bytes=base64_decode($content,true))===false || strlen($bytes)<1 || strlen($bytes)>self::MAX_BYTES) {
            throw new RuntimeException('Выберите файл DOCX до 5 МБ.',422);
        }
        $sha256=hash('sha256',$bytes);
        $result=['kind'=>$kind,'message'=>'Текущий Word-шаблон сохранён.'];
        if ($sha256===$old) return $result;
        if (!hash_equals($old,$expected)) throw new RuntimeException('Шаблон уже заменили. Откройте его заново.',409);
        $fields=self::withFile(['bytes'=>$bytes],static fn(string $path): array=>self::inspect($path,$kind));
        $fact=$fields+['sha256'=>$sha256,'content'=>base64_encode($bytes)];
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json),operative=1')
            ->execute([self::NS,$kind,Importer::json($fact)]);
        Audit::record(self::REPLACE,'template',$kind,['sha256'=>$old],['sha256'=>$sha256]);
        return $result;
    }

    private static function inspect(string $path,string $kind): array
    {
        $fields=OriginalDocuments::fieldsForKind($path,$kind);
        $clause=WordSecurityTerms::legacyClause($kind);
        $supported=array_merge(self::definition($kind)['fields'],OriginalDocuments::OPTIONAL_FIELDS,
            $clause===null?[]:[WordSecurityTerms::FIELD,'start_date_words']);
        if ($unknown=array_diff($fields,$supported)) {
            throw new RuntimeException('Поля не поддерживаются для этого шаблона: '.implode(', ',array_map(
                static fn(string $field): string=>'{{'.$field.'}}',$unknown)).'.',422);
        }
        $missing=array_diff(self::REQUIRED[$kind],$fields);
        if ($missing) {
            throw new RuntimeException('В шаблоне не хватает полей: '.implode(', ',array_map(static fn(string $field): string=>'{{'.$field.'}}',$missing)).'.',422);
        }
        if ($clause!==null) {
            $layout=DocxTemplate::ownerLayout($path,$clause);
            if ($kind==='office' && !$layout['plan']) {
                throw new RuntimeException('Поместите {{office_plan}} в отдельный абзац приложения к договору.',422);
            }
            if ($layout['security_fields']+$layout['security_clauses']!==1 || $layout['security_outside_body']) {
                throw new RuntimeException('Оставьте действующий абзац об обеспечительном платеже или поставьте поле {{security_clause}} на его месте.',422);
            }
            return ['fields'=>$fields,'owner_security_literal'=>$layout['security_clauses']===1];
        }
        return ['fields'=>$fields];
    }
}
