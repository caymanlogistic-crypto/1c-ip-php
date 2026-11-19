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
    function curl_setopt_array(\CurlHandle $curl,array $options): bool
    {
        $GLOBALS['mvk_route_test_options']=$options+($GLOBALS['mvk_route_test_options']??[]);
        return true;
    }

    function curl_exec(\CurlHandle $curl): bool
    {
        $options=$GLOBALS['mvk_route_test_options'];
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
    require dirname(__DIR__).'/modules/integrations/native/php/mosvodokanal/Api.php';
    $client=new \Mosvodokanal\Api('',recordResponses:false);
    $file=$client->sourceFinancialFile('MVK_TEST_BILL','7','3');
    if ($file['mime']!=='application/pdf' || $file['sha256']!==hash('sha256','%PDF-1.4 fixture')) {
        throw new RuntimeException('WRONG_SOURCE_FILE');
    }
    echo "PASS Mosvodokanal file GET uses the pinned HTTPS route and validates the response\n";
}
