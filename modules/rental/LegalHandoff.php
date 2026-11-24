<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit, BillableTerms, PagePolicy};
use PDO;
use RuntimeException;

final class LegalHandoff
{
    public const PARTY='contract.legal-party';
    public const TERMS='contract.legal-terms';
    public const SECURITY_MULTIPLE='contract.legal-security-multiple';
    public const BLOCK='contract.legal-block';
    public const UPLOAD='contract.legal-upload';
    public const REMOVE='contract.legal-remove';
    public const ACTIONS=[self::PARTY,self::TERMS,self::SECURITY_MULTIPLE,self::BLOCK,self::UPLOAD,self::REMOVE];
    private const DRAFT='legal_handoff_draft';
    private const SENT='legal_handoff_sent';
    private const PARTY_PROOF='contract-party-v2:';

    private static function fact(PDO $db,string $namespace,string $key): array
    {
        $query=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=?');
        $query->execute([$namespace,$key]);
        $value=$query->fetchColumn();
        return $value ? Importer::decodeExact($value) : [];
    }

    private static function store(PDO $db,string $namespace,string $key,array $value): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute([$namespace,$key,Importer::json($value),$namespace===self::DRAFT?1:0]);
    }

    public static function partyBasis(PDO $db,array $contract,bool $legacy=false): string
    {
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        $review=PartyLegalReview::state($db,(int)$party['id']);
        $data=[$party['id'],$party['version'],$party['blocked'],$review];
        if ($legacy) return hash('sha256',Importer::canonicalJson($data));
        $file=PartyLegalReview::contractSource($db,$party,$review);
        $data[]=(int)$contract['id'];
        $data[]=PartyLegalReview::signature($db,$party);
        $data[]=$file ? [$file['id'],$file['sha256']] : null;
        return self::PARTY_PROOF.hash('sha256',Importer::canonicalJson($data));
    }

    private static function termsBasis(PDO $db,array $contract): string
    {
        $terms=array_map(static function(array $term): array {
            unset($term['record_version']);
            return $term;
        },BillableTerms::forContract($db,(int)$contract['id']));
        $data=[$contract['party_id'],$contract['number'],$contract['document_date'],$contract['start_date'],
            $contract['end_date'],$contract['amount'],$terms,InitialInvoice::approvalTerms($db,(int)$contract['id'])];
        return hash('sha256',Importer::canonicalJson($data));
    }

    private static function matchesParty(PDO $db,array $contract,array $draft): bool
    {
        $saved=$draft['party']??null;
        return is_string($saved) && hash_equals($saved,self::partyBasis($db,$contract,!str_starts_with($saved,self::PARTY_PROOF)));
    }

    private static function continues(array $contract,array $draft): bool
    {
        return ($draft['version']??null)===(int)$contract['version']
            || (($contract['status']==='returned' && $contract['return_to']==='lawyer')
                && ($draft['version']??null)===(int)$contract['version']-2);
    }

    private static function draft(PDO $db,array $contract): array
    {
        return self::fact($db,self::DRAFT,(string)$contract['id']);
    }

    public static function checks(PDO $db,array $contract): array
    {
        $draft=self::draft($db,$contract);
        $allowed=self::continues($contract,$draft);
        return [
            'party'=>$allowed && self::matchesParty($db,$contract,$draft),
            'terms'=>$allowed && ($draft['terms']??null)===self::termsBasis($db,$contract),
        ];
    }

    public static function assertCounterparty(PDO $db,array $contract,bool $requireApproval=false): void
    {
        $draft=self::draft($db,$contract);
        $eligible=self::continues($contract,$draft) || !empty($draft['sealed']);
        if (!str_starts_with((string)($draft['party']??''),self::PARTY_PROOF)) {
            if ($requireApproval && $eligible && self::matchesParty($db,$contract,$draft)) {
                PartyLegalReview::assertCurrent($db,(int)$contract['party_id'],allowNewOnlyBlock:true);
                return;
            }
            PartyLegalReview::assertCurrent($db,(int)$contract['party_id'],$requireApproval,allowNewOnlyBlock:!$requireApproval);
            return;
        }
        if (!$eligible || !self::matchesParty($db,$contract,$draft)) {
            throw new RuntimeException('Подтвердите актуальные реквизиты контрагента по этому договору.',409);
        }
        self::verifyCounterparty($db,$contract);
    }

    private static function verifyCounterparty(PDO $db,array $contract): array
    {
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        if ($party['blocked'] && !self::blocksOnlyNew($party)) {
            throw new RuntimeException('Работа с контрагентом заблокирована.',409);
        }
        $review=PartyLegalReview::state($db,(int)$party['id']);
        PartyLegalReview::assertContractSource($db,$party,$review);
        return [$party,$review];
    }

    public static function ready(PDO $db,array $contract): bool
    {
        return !in_array(false,self::checks($db,$contract),true);
    }

    public static function files(PDO $db,array $contract): array
    {
        $draft=self::draft($db,$contract);
        return ($draft['version']??null)===(int)$contract['version'] ? $draft['files'] : [];
    }

    public static function execute(PDO $db,array $actor,string $action,array $input,?ContractReviewFiles $materials): array
    {
        if ($actor['role']!=='lawyer') throw new RuntimeException('Действие доступно юристу.',403);
        $id=(int)($input['id']??0);
        $contract=Access::contract($id,2);
        Service::version($contract,$input);
        if (!LegalReview::assigned($contract) || !RegistryState::managed($contract)) {
            throw new RuntimeException('Договор не у юриста.',409);
        }
        $draft=self::draft($db,$contract);
        $current=(int)$contract['version'];
        if (($draft['version']??null)!==$current) {
            $draft=['version'=>$current,'files'=>[]]+array_intersect_key(
                self::continues($contract,$draft)?$draft:[],['party'=>true,'terms'=>true]);
        }
        if ($action===self::PARTY) {
            $basis=self::partyBasis($db,$contract);
            if (!hash_equals($basis,(string)($input['party_basis']??''))) {
                throw new RuntimeException('Реквизиты или исходная карточка изменились. Откройте договор заново.',409);
            }
            [$party,$review]=self::verifyCounterparty($db,$contract);
            if (($review['status']??null)==='pending' && !LegalHandoff::blocksOnlyNew($party)
                && hash_equals((string)($review['signature']??''),PartyLegalReview::signature($db,$party))) {
                PagePolicy::requireCommand(PartyLegalReview::ACCEPT);
                PartyLegalReview::decision($db,$actor,PartyLegalReview::ACCEPT,[
                    'id'=>$party['id'],'version'=>$party['version'],'file_id'=>$review['file_id'],'verified'=>1,
                ]);
                $basis=self::partyBasis($db,$contract);
            }
            $draft['party']=$basis;
            $message='Работа с контрагентом разрешена.';
        } elseif ($action===self::TERMS) {
            $draft['terms']=self::termsBasis($db,$contract);
            $message='Условия договора подтверждены.';
        } elseif ($action===self::SECURITY_MULTIPLE) {
            $termsConfirmed=self::checks($db,$contract)['terms'];
            InitialInvoice::confirmLegalSecurityMultiple($db,$actor,$contract,$input);
            if ($termsConfirmed) $draft['terms']=self::termsBasis($db,$contract);
            $message='Кратность обеспечения согласована.';
        } else {
            if (!self::ready($db,$contract)) throw new RuntimeException('Сначала подтвердите контрагента и условия.',409);
            if ($action===self::UPLOAD) {
                if (!$materials) throw new RuntimeException('Добавьте хотя бы один файл.',422);
                if (count($draft['files'])+count($materials->fingerprint())>20)
                    throw new RuntimeException('В комплекте может быть до 20 файлов.',422);
                foreach ($materials->saveForLegal($db,$contract) as $file) {
                    $draft['files'][]=$file;
                }
                $message='Файлы добавлены к комплекту.';
            } else {
                $fileId=(string)($input['file_id']??'');
                if (!in_array($fileId,array_column($draft['files'],'id'),true)) {
                    throw new RuntimeException('Файл уже исключён. Обновите окно.',409);
                }
                $draft['files']=array_values(array_filter($draft['files'],static fn(array $file): bool=>$file['id']!==$fileId));
                $message='Файл исключён из текущего комплекта.';
            }
        }
        self::store($db,self::DRAFT,(string)$id,$draft);
        Service::event($db,$actor,$action,$message,$id);
        return ['id'=>$id,'message'=>$message];
    }

    public static function blocksOnlyNew(array $party): bool
    {
        return !empty($party['blocked']) && !empty(Importer::decodeExact($party['source_json'])['legal_block_new_only']);
    }

    public static function block(PDO $db,array $actor,array $contract,string $reason): void
    {
        if ($actor['role']!=='lawyer' || !LegalReview::assigned($contract)) throw new RuntimeException('Договор не у юриста.',403);
        if ($reason==='') throw new RuntimeException('Укажите причину.',422);
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        if (!$party['blocked']) {
            $db->prepare("UPDATE parties SET blocked=1,block_reason=?,version=version+1,
                source_json=JSON_SET(source_json,'$.legal_block_new_only',CAST('true' AS JSON)) WHERE id=?")
                ->execute([$reason,$party['id']]);
            Audit::record(self::BLOCK,'party',$party['id'],['blocked'=>false],['blocked'=>true,'reason'=>$reason]);
        }
    }

    public static function seal(PDO $db,array $actor,array $contract,array $input): array
    {
        if (!self::ready($db,$contract)) throw new RuntimeException('Сначала подтвердите контрагента и условия.',409);
        $multiple=InitialInvoice::legalSecurityMultiple($db,$contract);
        if ($multiple===null) throw new RuntimeException('Согласуйте кратность обеспечения по текущим условиям договора.',409);
        if (filter_var($input['security_multiple']??null,FILTER_VALIDATE_INT)!==$multiple) {
            throw new RuntimeException('Кратность обеспечения изменилась. Откройте договор заново.',409);
        }
        self::assertCounterparty($db,$contract);
        $id=(int)$contract['id'];
        $draft=self::draft($db,$contract);
        $files=self::files($db,$contract);
        if (!$files) throw new RuntimeException('Загрузите итоговый файл для бухгалтера.',422);
        $expected=(string)($input['packet_fingerprint']??'');
        if (!hash_equals(self::fingerprint($files),$expected)) throw new RuntimeException('Комплект изменился. Проверьте файлы повторно.',409);
        $validated=self::verifyFiles($db,$id,$files);
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        $source=PartyLegalReview::contractSource($db,$party,PartyLegalReview::state($db,(int)$party['id']));
        $bundle=['contract_id'=>$id,'version'=>(int)$contract['version'],'files'=>$files,'actor'=>$actor['name'],
            'party_proof'=>$draft['party'],'terms_proof'=>$draft['terms'],'security_multiple'=>$multiple,
            'party_source'=>$source ? array_intersect_key($source,array_flip(['id','filename','sha256'])) : null,
            'sent_at'=>date('Y-m-d H:i:s')];
        self::store($db,self::SENT,$id.':'.$contract['version'],$bundle);
        self::store($db,self::DRAFT,(string)$id,$draft+['sealed'=>true]);
        return $validated[0];
    }

    public static function fingerprint(array $files): string
    {
        return hash('sha256',Importer::canonicalJson($files));
    }

    private static function verifyFiles(PDO $db,int $id,array $files): array
    {
        $valid=[];
        $query=$db->prepare("SELECT * FROM rental_files WHERE id=? AND entity_type='contract' AND entity_id=? AND kind='lawyer-file'");
        foreach ($files as $entry) {
            $query->execute([$entry['id'],$id]);
            $file=$query->fetch();
            if (!$file || !hash_equals($file['sha256'],$entry['sha256'])) throw new RuntimeException('Файл комплекта изменился.',409);
            Evidence::integrity($file);
            $valid[]=$file;
        }
        return $valid;
    }

    public static function approvedFiles(PDO $db,int $id,array $primary): ?array
    {
        $bundle=self::fact($db,self::SENT,$id.':'.$primary['version']);
        if (!$bundle) return null;
        $valid=self::verifyFiles($db,$id,$bundle['files']);
        if (($valid[0]['id']??null)!==$primary['id']) throw new RuntimeException('Комплект юриста изменился.',409);
        return $bundle['files'];
    }

    public static function history(PDO $db,int $id): array
    {
        $query=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace='legal_handoff_sent'
            AND source_key LIKE ? ORDER BY id DESC");
        $query->execute([$id.':%']);
        return array_map([Importer::class,'decodeExact'],$query->fetchAll(PDO::FETCH_COLUMN));
    }
}
