<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, BillableTerms, DB};
use RuntimeException;
use ZipArchive;

final class InitialInvoicePreview
{
    public const URL='/rental/initial-invoice-preview.zip';
    public const LABEL='Посмотреть предварительный комплект счетов';

    public static function route(int $id): string
    {
        return self::URL.'?id='.$id;
    }

    public static function archive(): never
    {
        if ((Auth::user()['role'] ?? null)!=='accountant') {
            throw new RuntimeException('Действие доступно бухгалтерии.',403);
        }
        Access::finance();
        $id=(int)($_GET['id'] ?? 0);
        $contract=Access::contract($id);
        if (!AccountantReviewCard::available($contract)) {
            throw new RuntimeException('Договор не на проверке бухгалтерии.',409);
        }

        $db=DB::pdo();
        $plan=InitialInvoice::preview($db,$contract,InitialInvoiceCard::defaultIssueDate($contract));
        if (!$plan['rows']) {
            throw new RuntimeException($plan['questions'][0] ?? 'Предварительный расчёт счетов недоступен.',409);
        }
        $payer=Service::get($db,'parties',(int)$contract['party_id']);
        $payee=Billing::invoicePayee($db);
        $terms=InitialBilling::orderedTerms(BillableTerms::forContract($db,$id));
        $basis=self::basis($contract,$terms);
        $path=tempnam(sys_get_temp_dir(),'rental-preview-');
        if ($path===false) throw new RuntimeException('Не удалось подготовить архив счетов.');
        try {
            $zip=new ZipArchive();
            if ($zip->open($path,ZipArchive::OVERWRITE)!==true) {
                throw new RuntimeException('Не удалось открыть архив счетов.');
            }
            foreach (InitialInvoice::securityFirst($plan['rows']) as $index=>$row) {
                $lines=self::lines($row,$terms,$basis,$payee['vat']);
                $pdf=InitialInvoicePrint::pdf($payer,$payee,$basis,$plan['issued'],$row,$lines);
                $entry=($index+1).' · '.InitialInvoice::DUE_PARTS[$row['due_part']].'.pdf';
                if (!$zip->addFromString($entry,$pdf)) throw new RuntimeException('Не удалось добавить счёт в архив.');
            }
            if (!$zip->close()) throw new RuntimeException('Не удалось сохранить архив счетов.');

            $name='Договор №'.$contract['number'].' · предварительные счета.zip';
            header('Content-Type: application/zip');
            header("Content-Disposition: attachment; filename=\"invoice-preview.zip\"; filename*=UTF-8''".rawurlencode($name));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
        } finally {
            unset($zip);
            unlink($path);
        }
        exit;
    }

    private static function basis(array $contract,array $terms): string
    {
        $types=array_unique(array_map(static fn(array $term): string => self::objectType($term['type']),$terms));
        $subject=match (array_values($types)) {
            ['Машиноместо']=>'машино-мест',
            ['Офис']=>'нежилых помещений',
            default=>'объектов',
        };
        return 'Договор аренды '.$subject.' №'.$contract['number'].' от '.Documents::dateText($contract['document_date']);
    }

    private static function objectType(string $type): string
    {
        return in_array($type,TemplateAssets::PARKING_TYPES,true)?'Машиноместо':$type;
    }

    private static function lines(array $row,array $terms,string $basis,?string $vat): array
    {
        if ($row['due_part']==='security') {
            $net=InvoiceDocument::nativeBreakdown($row['amount'],$vat)['net'] ?? null;
            if ($net===null) throw new RuntimeException('Проверьте сумму и НДС обеспечительного платежа.',409);
            return [['description'=>'Обеспечительный платёж. '.$basis,'unit'=>'шт','net'=>$net]];
        }

        $lines=[];
        $monthly='0';
        $netThroughPrevious='0.00';
        $period=Documents::dateText($row['from']).' — '.Documents::dateText($row['to']);
        foreach (InitialInvoice::termsInPeriod($terms,$row['from'],$row['to']) as $term) {
            $monthly=bcadd($monthly,$term['amount'],12);
            $gross=InitialInvoice::prorate($monthly,$row['from'],$row['to']);
            $netThroughCurrent=InvoiceDocument::nativeBreakdown($gross,$vat)['net'] ?? null;
            if ($netThroughCurrent===null) throw new RuntimeException('Проверьте ставки по объектам и НДС.',409);
            $type=self::objectType($term['type']);
            $subject=match ($type) {
                'Машиноместо'=>'машино-места',
                'Офис'=>'офиса',
                default=>'объекта',
            };
            $object=preg_match('/^\d/u',$term['name'])?'№'.$term['name']:'«'.$term['name'].'»';
            // Round the cumulative net so the item amounts add up to the invoice total exactly.
            $lines[]=[
                'description'=>'Аренда '.$subject.' '.$object.' за период '.$period.'. '.$basis,
                'unit'=>$type==='Машиноместо'?'шт':'мес',
                'net'=>bcsub($netThroughCurrent,$netThroughPrevious,2),
            ];
            $netThroughPrevious=$netThroughCurrent;
        }
        return $lines;
    }
}
