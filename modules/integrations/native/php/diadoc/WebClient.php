<?php
declare(strict_types=1);

namespace Diadoc;

use RuntimeException;

/** Uses the web cabinet's existing login flow, not a public API subscription. */
final class WebClient
{
    public function __construct(private object $http) {}

    public function bootstrap(): array
    {
        $response = $this->http->request('GET', 'https://auth.kontur.ru/?customize=diadoc&back=https%3A%2F%2Fdiadoc.kontur.ru%2F');
        if ($response['status'] !== 200 || $this->http->cookie('AntiForgery') === '') {
            throw new RuntimeException('AUTH_BOOTSTRAP_FAILED');
        }
        return $response;
    }

    private function auth(string $route, mixed $body): array
    {
        $csrf = $this->http->cookie('AntiForgery');
        if ($csrf === '') {
            throw new RuntimeException('AUTH_SESSION_MISSING');
        }
        $response = $this->http->request('POST', 'https://auth.kontur.ru/api/authentication/' . $route . '?customize=diadoc', $body, [
            'X-CSRF-Token: ' . $csrf, 'Origin: https://auth.kontur.ru', 'Referer: https://auth.kontur.ru/',
        ]);
        $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('INVALID_AUTH_RESPONSE');
        }
        return ['status' => $response['status'], 'data' => $data];
    }

    public function login(string $login, string $password): array
    {
        if ($login === '' || $password === '') {
            throw new RuntimeException('LOGIN_AND_PASSWORD_REQUIRED');
        }
        $this->bootstrap();
        $result = $this->auth('password/auth-by-password', [
            'Login' => $login, 'Password' => $password, 'Remember' => false, 'PartialFactorToken' => null,
        ]);
        if ($result['status'] === 403 && ($result['data']['errorStatus'] ?? '') === 'NeedApproveSecondFactorByTotpPush') {
            $details = $result['data']['details'] ?? [];
            foreach (['PushId', 'UserId', 'PartialFactorToken'] as $key) {
                if (!is_string($details[$key] ?? null) || $details[$key] === '') {
                    throw new RuntimeException('INVALID_PUSH_CHALLENGE');
                }
            }
            return ['state' => 'awaiting_confirmation', 'created_at' => time(), 'details' => $details];
        }
        if ($result['status'] !== 200 || isset($result['data']['errorStatus'])) {
            if (($result['data']['errorStatus'] ?? '') === 'BadPassword') {
                throw new RuntimeException('LOGIN_REJECTED');
            }
            throw new RuntimeException('LOGIN_REJECTED_HTTP_' . $result['status']);
        }
        return ['state' => 'authenticated', 'created_at' => time()];
    }

    /** One status check per invocation; does not send another push. */
    public function confirm(array $pending): array
    {
        if (($pending['state'] ?? '') !== 'awaiting_confirmation' || !is_array($pending['details'] ?? null)) {
            throw new RuntimeException('NO_PENDING_CONFIRMATION');
        }
        if (!is_int($pending['created_at'] ?? null) || time() - $pending['created_at'] > 600) {
            throw new RuntimeException('CONFIRMATION_EXPIRED_RUN_LOGIN');
        }
        $details = $pending['details'];
        $result = $this->auth('totp/get-code-by-pushid', $details['PushId']);
        if ($result['status'] !== 200) {
            throw new RuntimeException('CONFIRMATION_CHECK_FAILED_HTTP_' . $result['status']);
        }
        $status = $result['data']['Status'] ?? null;
        if ($status === 'Refused') {
            throw new RuntimeException('CONFIRMATION_REFUSED');
        }
        if ($status !== 'Confirmed') {
            return ['state' => 'awaiting_confirmation', 'created_at' => $pending['created_at'], 'details' => $details];
        }
        $code = $result['data']['Code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new RuntimeException('CONFIRMATION_CODE_MISSING');
        }
        $result = $this->auth('totp/auth-by-code', [
            'Code' => $code, 'PartialFactorToken' => $details['PartialFactorToken'],
            'Remember' => false, 'UserId' => $details['UserId'],
        ]);
        if ($result['status'] !== 200 || isset($result['data']['errorStatus'])) {
            throw new RuntimeException('CONFIRMATION_FAILED_HTTP_' . $result['status']);
        }
        return ['state' => 'authenticated', 'created_at' => time()];
    }

    public function page(string $box, string $category, int $limit, ?string $from = null): array
    {
        if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $box)) {
            throw new RuntimeException('INVALID_BOX_ID');
        }
        if (!in_array($category, ['Any', 'Incoming', 'Outgoing', 'Internal', 'Deleted'], true) || $limit < 1 || $limit > 50) {
            throw new RuntimeException('INVALID_EXPORT_OPTIONS');
        }
        $query = ['category' => $category, 'limit' => $limit, 'hideLogisticsDocuments' => 'false'];
        if ($from !== null && $from !== '') {
            $query['from'] = $from;
        }
        $response = $this->http->request('GET', 'https://diadoc.kontur.ru/webapi/boxes/' . $box . '/documents?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, ['X-Requested-With: XMLHttpRequest']);
        if (in_array($response['status'], [301, 302, 303, 307, 308, 401, 403], true)) {
            throw new RuntimeException('SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN');
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException('EXPORT_FAILED_HTTP_' . $response['status']);
        }
        if(empty($GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'])&&is_callable($GLOBALS['IPPHP_CAPTURE_RAW']??null))($GLOBALS['IPPHP_CAPTURE_RAW'])($response['body']);
        $data = \Modules\Integrations\Support::decode($response['body']);
        if (!is_array($data) || !is_array($data['documentsByMessage'] ?? null)
            || !is_array($data['paging'] ?? null) || !is_array($data['totalCounters'] ?? null)) {
            throw new RuntimeException('DOCUMENT_LIST_SCHEMA_CHANGED');
        }
        return $data;
    }

    /** Exact selected canonical card HTML, inert parsing; no linked requests. */
    public function printMetadata(string $box,array $document):array
    {
        $message=$document['message_id']??'';$id=$document['document_id']??'';
        if(!is_string($message)||!is_string($id)||!PrintDiscovery::allowed($box,$message,$id))throw new RuntimeException('ACCESS_DENIED');
        $response=$this->http->request('GET','https://diadoc.kontur.ru/'.$box.'/Document/Show?'.http_build_query(['letterId'=>$message,'documentId'=>$id],'','&',PHP_QUERY_RFC3986),null,[],2*1024*1024);
        if(in_array($response['status'],[301,302,303,307,308,401,403],true))throw new RuntimeException('SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN');
        if($response['status']!==200||!str_starts_with(strtolower($response['headers']['content-type']??''),'text/html'))throw new RuntimeException('DOCUMENT_LIST_SCHEMA_CHANGED');
        return PrintDiscovery::project($response['body'],$box,$message,$id,(string)($response['headers']['content-security-policy']??''));
    }

    /** Fetches the original attachment directly; does not visit the document card. */
    public function original(string $box, array $document): array
    {
        foreach ([$box, $document['message_id'] ?? '', $document['document_id'] ?? ''] as $id) {
            if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id)) {
                throw new RuntimeException('INVALID_DOCUMENT_ID');
            }
        }
        $expected = $document['file_size'] ?? null;
        if (!is_int($expected) || $expected < 1 || $expected > 24 * 1024 * 1024) {
            throw new RuntimeException('DOCUMENT_SIZE_NOT_SUPPORTED');
        }
        $response = $this->http->request('GET', 'https://diadoc.kontur.ru/webapi/' . $box . '/Download/Attachment?'
            . http_build_query(['letterId' => $document['message_id'], 'attachmentId' => $document['document_id']], '', '&', PHP_QUERY_RFC3986),
            null, ['X-Requested-With: XMLHttpRequest'], $expected + 4096);
        if (in_array($response['status'],[301,302,303,307,308,401,403],true)) throw new RuntimeException('SESSION_OR_BOX_ACCESS_DENIED_RUN_LOGIN');
        if ($response['status'] !== 200) {
            throw new RuntimeException('DOCUMENT_DOWNLOAD_HTTP_' . $response['status']);
        }
        if (strlen($response['body']) !== $expected) {
            throw new RuntimeException('DOCUMENT_CONTENT_SIZE_MISMATCH');
        }
        $contentType = strtolower($response['headers']['content-type'] ?? '');
        if (str_contains($contentType, 'text/html') || str_contains($contentType, 'application/json')) {
            throw new RuntimeException('DOCUMENT_CONTENT_NOT_A_FILE');
        }
        $filename = $document['name'];
        $disposition = $response['headers']['content-disposition'] ?? '';
        if (preg_match("/filename\*=UTF-8''([^;]+)/i", $disposition, $match)) {
            $candidate = rawurldecode(trim($match[1], '" '));
            if (preg_match('//u', $candidate) === 1) {
                $filename = $candidate;
            }
        } elseif (preg_match('/filename="([^"]+)"|filename=([^;]+)/i', $disposition, $match)) {
            $candidate = trim(($match[1] ?? '') !== '' ? $match[1] : $match[2]);
            if (preg_match('//u', $candidate) === 1) {
                $filename = $candidate;
            }
        }
        return ['body' => $response['body'], 'content_type' => $contentType, 'name' => $filename];
    }
}
