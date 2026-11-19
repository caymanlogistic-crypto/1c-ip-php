<?php
declare(strict_types=1);

namespace Modules\Integrations {
    final class CabinetNetwork
    {
        public static function options(string $host): array
        {
            if ($host!=='onewind.mosvodokanal.ru') throw new \RuntimeException('WRONG_CABINET_HOST');
            return [CURLOPT_RESOLVE=>[$host.':443:192.0.2.11']];
        }
    }
}

namespace Mosvodokanal {
    function curl_setopt(\CurlHandle $curl,int $option,mixed $value): bool
    {
        $GLOBALS['mvk_route_test_options'][$option]=$value;
        return true;
    }

    function curl_setopt_array(\CurlHandle $curl,array $options): bool
    {
        $GLOBALS['mvk_route_test_options']=$options+($GLOBALS['mvk_route_test_options']??[]);
        return true;
    }

    function curl_exec(\CurlHandle $curl): bool
    {
        $options=$GLOBALS['mvk_route_test_options'];
        if ($options[CURLOPT_URL]==='https://onewind.mosvodokanal.ru/api/ReportConfiguration/get') {
            if ($options[CURLOPT_POST]!==true
                || $options[CURLOPT_POSTFIELDS]!=='reportId=NATURAL_PERSON_ACCOUNT_BILL_REGISTER') {
                throw new \RuntimeException('WRONG_SUBSCRIBER_CONFIGURATION_REQUEST');
            }
            $body=$GLOBALS['mvk_report_fixture'];
            return $options[CURLOPT_WRITEFUNCTION]($curl,$body)===strlen($body);
        }
        if (($options[CURLOPT_RESOLVE]??null)!==['onewind.mosvodokanal.ru:443:192.0.2.11']
            || !str_starts_with($options[CURLOPT_URL],'https://onewind.mosvodokanal.ru/api/publisherReport?')
            || $options[CURLOPT_HTTPGET]!==true || $options[CURLOPT_POST]!==false
            || $options[CURLOPT_FOLLOWLOCATION]!==false || $options[CURLOPT_SSL_VERIFYPEER]!==true
            || $options[CURLOPT_SSL_VERIFYHOST]!==2) {
            throw new \RuntimeException('FILE_DID_NOT_USE_PINNED_HTTPS_ROUTE');
        }
        $body='%PDF-1.4 fixture';
        return $options[CURLOPT_WRITEFUNCTION]($curl,$body)===strlen($body);
    }

    function curl_getinfo(\CurlHandle $curl,int $key): int|string
    {
        return match($key) {
            CURLINFO_RESPONSE_CODE=>200,
            CURLINFO_CONTENT_TYPE=>'application/pdf',
            default=>throw new \RuntimeException('UNKNOWN_CURL_INFO'),
        };
    }

    function curl_errno(\CurlHandle $curl): int
    {
        return 0;
    }
}

namespace {
    require dirname(__DIR__).'/modules/integrations/Support.php';
    require dirname(__DIR__).'/modules/integrations/MvkMapping.php';
    require dirname(__DIR__).'/modules/integrations/native/php/mosvodokanal/Api.php';
    $GLOBALS['IPPHP_EMBEDDED_NATIVE']=true;
    $action=['ctype'=>'DownloadFinancialDocumentFromBZCommand',
        'properties'=>['publisherReportCd'=>'MVK_TEST_BILL','windowName'=>'document'],
        'parameters'=>[['name'=>'id','valueContext'=>'FIELD','valueField'=>'ID'],
            ['name'=>'type','valueContext'=>'FIELD','valueField'=>'FL_DOC']]];
    $plugin=['uiElement'=>['propertyData'=>json_encode($action,JSON_THROW_ON_ERROR)]];
    $fixture=['reportId'=>'NATURAL_PERSON_ACCOUNT_BILL_REGISTER','plugins'=>[$plugin]];
    $GLOBALS['mvk_report_fixture']=json_encode($fixture,JSON_THROW_ON_ERROR);
    $client=new \Mosvodokanal\Api('',recordResponses:false);
    $metadata=$client->printMetadata();
    $binding=\Modules\Integrations\MvkMapping::inspect($metadata);
    if ($binding['publisher_report_cd']!=='MVK_TEST_BILL'||$metadata['source_sha256']!==hash('sha256',$GLOBALS['mvk_report_fixture'])) {
        throw new RuntimeException('SUBSCRIBER_REPORT_BINDING_NOT_VERIFIED');
    }
    foreach ([
        [array_replace($fixture,['reportId'=>'OTHER_REPORT']),'SOURCE_SCHEMA_CHANGED'],
        [array_replace($fixture,['plugins'=>[$plugin,$plugin]]),'FILE_MAPPING_NOT_CONFIRMED'],
    ] as [$invalid,$expected]) {
        try {
            \Modules\Integrations\MvkMapping::inspect(array_replace($metadata,['definition'=>$invalid]));
            throw new LogicException('INVALID_REPORT_WAS_ACCEPTED');
        } catch (RuntimeException $error) {
            if($error->getMessage()!==$expected)throw $error;
        }
    }
    $file=$client->sourceFinancialFile($binding['publisher_report_cd'],'7','3');
    if ($file['mime']!=='application/pdf' || $file['sha256']!==hash('sha256','%PDF-1.4 fixture')) {
        throw new RuntimeException('WRONG_SOURCE_FILE');
    }
    echo "PASS subscriber report configuration, strict binding and verified publisher PDF\n";
}
