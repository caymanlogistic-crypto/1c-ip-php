<?php
declare(strict_types = 1);
namespace Modules\Integrations;
/** Actual CREATE/GET transport: fixed reviewed OData origin/IP, strict TLS, no redirects or updates. */
final class OnecNativeTransport {
    public function __construct(private array $binding,
        private string $reference,
        private mixed $profileLoader=null,
        private mixed $devFactory=null) {
        Config::reference($reference);
        // Constructor-only fixtures; no request/env capability bypass is introduced.
        if($profileLoader!==null&&!is_callable($profileLoader)||$devFactory!==null&&!is_callable($devFactory))throw new \InvalidArgumentException('ONEC_TRANSPORT_DEPENDENCY_INVALID');
    }
    public function exchange(string $method,
        string $collection,
        ?string $key,
        string $body = '',
        array $query = []): array {
        $collections = [...array_values(OnecMapping::COLLECTIONS),
            ...array_values(OnecMapping::REFS)];
        if (!in_array($collection,
            $collections,
            true) || !in_array($method,
            ['GET',
                'POST'],
            true) || strlen($body) > 200000 || $method === 'GET' && $body !== '' || $method === 'POST' && (!in_array($collection,
            array_values(OnecMapping::COLLECTIONS),
            true) || $key !== null || $query || $body === ''))throw new \RuntimeException('ONEC_ROUTE_REJECTED');
        if ($key !== null)OnecMapping::guid($key);
        if ($method === 'GET' && $key === null){
            $keys = array_keys($query);
            sort($keys);
            if ($collection !== OnecMapping::REFS['counterparty'] || $keys !== ['$filter',
                    '$format',
                    '$select',
                    '$top'] || $query['$format'] !== 'json' || $query['$top'] !== '2' || $query['$select'] !== 'Ref_Key,ИНН,КПП,DeletionMark' || !preg_match("/^DeletionMark eq false and ИНН eq '(?:[0-9]{10}|[0-9]{12})'(?: and КПП eq '[0-9A-Z]{9}')?$/D",
                $query['$filter']))throw new \RuntimeException('ONEC_ROUTE_REJECTED');
        }
        elseif($query)throw new \RuntimeException('ONEC_ROUTE_REJECTED');
        $needsScoped=!Config::liveAllowed()||($method==='POST'&&getenv('IPPHP_ALLOW_ONEC_WRITE')!=='owner-authorized-prepared-create');
        $scoped=$needsScoped&&OnecWriteAdmission::settings()!==null&&OnecWriteAdmission::allows(\App\DB::pdo(),$this->binding,$this->reference,$method,$collection);
        if (!Config::liveAllowed()&&!$scoped)throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        if ($method === 'POST' && getenv('IPPHP_ALLOW_ONEC_WRITE') !== 'owner-authorized-prepared-create'&&!$scoped)throw new \RuntimeException('ONEC_WRITE_NOT_AUTHORIZED');
        $secret = $this->profileLoader!==null?($this->profileLoader)($this->reference):Config::profile('onec',$this->reference);
        $policy = $secret['write_policy']??null;
        if (!is_array($policy) || ($policy['enabled']??false) !== true || ($policy['source_instance']??'') !== ($this->binding['source_instance']??null) || ($policy['project_id']??'') !== CaptureReader::PROJECT || ($policy['organization_key']??'') !== ($this->binding['organization_key']??null) || ($policy['organization_inn']??'') !== ($this->binding['organization_inn']??null))throw new \RuntimeException('ONEC_TRUSTED_SOURCE_POLICY_REQUIRED');
        require_once __DIR__ . '/native/php/onec/OnecProbe.php';
        $path = $collection . ($key !== null?"(guid'" . $key . "')":'');
        $url = 'https://' . \OnecProbe::HOST . \OnecProbe::ROOT . rawurlencode($collection) . ($key !== null?"(guid'" . $key . "')":'') . '?' . http_build_query($query?:['$format' => 'json'],
            '',
            '&',
            PHP_QUERY_RFC3986);
        $username = $secret['username']??'';
        $password = $secret['password']??'';
        if (!is_string($username) || !is_string($password) || $username === '' || $password === '' || preg_match('/[\x00-\x1f\x7f:]/',
            $username) || preg_match('/[\x00-\x1f\x7f]/',
            $password))throw new \RuntimeException('ONEC_LOCAL_CREDENTIAL_INVALID');
        $headers=['Accept: application/json','Accept-Encoding: identity','Content-Type: application/json; charset=utf-8',
            'Authorization: Basic '.base64_encode($username.':'.$password)];
        // Existing route/live/write/source-policy/credential gates above apply to BOTH paths.
        $dev=null;
        if($this->devFactory!==null){
            $dev=($this->devFactory)($secret);
            if(!$dev instanceof OnecDevSshTransport)throw new \RuntimeException('ONEC_TRANSPORT_DEPENDENCY_INVALID');
        }
        elseif(($configuration=OnecDevSshTransport::settings())!==null)$dev=new OnecDevSshTransport($configuration);
        if($dev!==null){
            if(!$dev instanceof OnecDevSshTransport)throw new \RuntimeException('ONEC_TRANSPORT_DEPENDENCY_INVALID');
            // OnecBindings::source already checked the selected current row. The ordinary
            // secret getter adds only _reference; bind a runtime COPY, never seal its store.
            $revision=$this->binding['operative_connection_revision']??null;
            if(($this->binding['project_id']??null)!==OnecDevRequest::PROJECT
                ||(int)($this->binding['enabled']??0)!==1
                ||($this->binding['profile_ref']??null)!==$this->reference
                ||($secret['_reference']??null)!==$this->binding['profile_ref']
                ||!is_int($revision)||$revision<1
                ||array_key_exists('_owner_bound_revision',$secret)&&$secret['_owner_bound_revision']!==$revision){
                throw new \RuntimeException('ONEC_DEV_PROFILE_CHANGED');
            }
            $runtimeProfile=$secret;$runtimeProfile['_owner_bound_revision']=$revision;
            $dev->assertProfile($runtimeProfile);unset($runtimeProfile);
            $response=$dev->exchange(OnecDevRequest::makeNative($method,$collection,$key,$body,$query,$headers));
            // The unchanged native journal owns UUID, post_started, raw201/HMAC and readback.
            return ['status'=>$response['status'],'raw'=>$response['body'],
                'source_instance'=>$this->binding['source_instance'],'provenance'=>'native'];
        }
        $raw = '';
        $large = false;
        $ch = curl_init($url);
        $opts = [CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE => [\OnecProbe::HOST . ':443:' . \OnecProbe::IP],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_WRITEFUNCTION => static function($ch,
                string $chunk)use(&$raw,
                &$large){
                if (strlen($raw)+strlen($chunk) > 2000000){
                    $large = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            }
        ];
        if ($ca = Support::caFile())$opts[CURLOPT_CAINFO] = $ca;
        if ($method === 'POST')$opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch,
            $opts);
        $ok = curl_exec($ch);
        $info = curl_getinfo($ch);
        $code = (int)$info['http_code'];
        if ($ok === false || $large || ($info['primary_ip']??'') !== \OnecProbe::IP || ($info['ssl_verify_result']??-1) !== 0)throw new \RuntimeException('ONEC_NATIVE_OUTCOME_UNKNOWN');
        return ['status' => $code,
            'raw' => $raw,
            'source_instance' => $this->binding['source_instance'],
            'provenance' => 'native'];
    }
}
