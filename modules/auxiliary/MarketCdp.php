<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use RuntimeException;

/** Small PHP DevTools client for an explicitly owned loopback browser endpoint. */
final class MarketCdp
{
    private $socket;
    private int $id = 0;
    private array $events = [];
    private int $eventBytes = 0;
    private float $deadline;
    private const MAX_FRAME = 4194304;
    private ?\Closure $handler = null;
    private bool $inHandler = false;
    private array $notifications = [];

    public function __construct(string $endpoint, private int $timeoutMs = 10000)
    {
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'ws' || ($parts['host'] ?? '') !== '127.0.0.1'
            || !is_int($parts['port'] ?? null) || $parts['port'] < 1024 || $parts['port'] > 65535
            || in_array($parts['port'],[3306,43061,8765],true) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']) || !preg_match('~^/devtools/(?:browser|page)/[A-Za-z0-9-]{1,100}$~D',$parts['path'] ?? '')
            || $timeoutMs < 1000 || $timeoutMs > 60000) throw new RuntimeException('Explicit owned loopback DevTools endpoint required.');
        $socket = stream_socket_client('tcp://127.0.0.1:'.$parts['port'],$errno,$error,$timeoutMs/1000,STREAM_CLIENT_CONNECT);
        if (!$socket) throw new RuntimeException('Owned browser DevTools connection failed.');
        $this->socket=$socket;
        $this->deadline=microtime(true)+$timeoutMs/1000;
        stream_set_timeout($socket,intdiv($timeoutMs,1000),($timeoutMs%1000)*1000);
        $key=base64_encode(random_bytes(16));
        try {
            $this->write('GET '.$parts['path']." HTTP/1.1\r\nHost: 127.0.0.1:".$parts['port']."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$key."\r\nSec-WebSocket-Version: 13\r\n\r\n");
            $header=''; $deadline=microtime(true)+$timeoutMs/1000;
            while (!str_ends_with($header,"\r\n\r\n")) {
                if (strlen($header)>=16384 || microtime(true)>$deadline) throw new RuntimeException('DevTools upgrade header exceeded bound.');
                $header.=$this->read(1);
            }
            if (!preg_match('~^HTTP/1\.[01] 101(?: |\r)~',$header)
                || !preg_match('/^Upgrade:\s*websocket\s*\r?$/im',$header)
                || !preg_match('/^Connection:[^\r\n]*\bUpgrade\b[^\r\n]*\r?$/im',$header)
                || preg_match('/^Sec-WebSocket-(?:Extensions|Protocol):/im',$header)
                || !preg_match('/^Sec-WebSocket-Accept:\s*([^\r\n]+)\r?$/im',$header,$match)
                || !hash_equals(base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true)),trim($match[1]))) throw new RuntimeException('DevTools WebSocket upgrade identity failed.');
        } catch (\Throwable $failure) { fclose($socket); $this->socket=null; throw $failure; }
    }

    private function write(string $bytes): void
    {
        $offset=0;
        while ($offset<strlen($bytes)) {
            if (microtime(true)>$this->deadline) throw new RuntimeException('DevTools write deadline exceeded.');
            $written=fwrite($this->socket,substr($bytes,$offset));
            if ($written===false || $written===0) throw new RuntimeException('DevTools write failed.');
            $offset+=$written;
        }
    }

    private function read(int $length): string
    {
        $bytes='';
        while (strlen($bytes)<$length) {
            $remaining=$this->deadline-microtime(true);
            if ($remaining<=0) throw new RuntimeException('DevTools read deadline exceeded.');
            $microseconds=max(1,(int)ceil($remaining*1000000));
            stream_set_timeout($this->socket,intdiv($microseconds,1000000),$microseconds%1000000);
            $part=fread($this->socket,$length-strlen($bytes));
            if ($part===false || $part==='') throw new RuntimeException('DevTools stream closed or timed out.');
            $bytes.=$part;
        }
        return $bytes;
    }

    private function frame(string $bytes, int $opcode = 1): void
    {
        $size=strlen($bytes);
        if ($size>self::MAX_FRAME) throw new RuntimeException('DevTools outgoing frame exceeds bound.');
        $mask=random_bytes(4);
        $header=chr(0x80|$opcode);
        if ($size<126) $header.=chr(0x80|$size);
        elseif ($size<=65535) $header.=chr(0x80|126).pack('n',$size);
        else $header.=chr(0x80|127).pack('NN',0,$size);
        $this->write($header.$mask.($bytes ^ substr(str_repeat($mask,(int)ceil($size/4)),0,$size)));
    }

    private function message(): string
    {
        $message=''; $started=false;
        $deadline=microtime(true)+$this->timeoutMs/1000;
        do {
            if (microtime(true)>$deadline) throw new RuntimeException('DevTools message deadline exceeded.');
            $header=unpack('Cfirst/Csecond',$this->read(2));
            $opcode=$header['first']&15; $final=($header['first']&0x80)!==0;
            if (($header['first']&0x70)!==0 || ($header['second']&0x80)!==0) throw new RuntimeException('Unsupported DevTools frame encoding.');
            $length=$header['second']&127;
            if ($length===126) $length=unpack('nlength',$this->read(2))['length'];
            elseif ($length===127) {
                $wide=unpack('Nhigh/Nlow',$this->read(8));
                if ($wide['high']!==0) throw new RuntimeException('DevTools frame exceeds bound.');
                $length=$wide['low'];
            }
            if ($length>self::MAX_FRAME || strlen($message)+$length>self::MAX_FRAME) throw new RuntimeException('DevTools message exceeds bound.');
            if ($opcode>=8 && (!$final || $length>125)) throw new RuntimeException('Invalid DevTools control frame.');
            $payload=$this->read($length);
            if ($opcode===8) throw new RuntimeException('Owned DevTools target closed.');
            if ($opcode===9) { $this->frame($payload,10); continue; }
            if ($opcode===10) continue;
            if (($opcode===1 && $started) || ($opcode===0 && !$started) || !in_array($opcode,[0,1],true)) throw new RuntimeException('Invalid DevTools fragmented message.');
            $started=true; $message.=$payload;
            if ($final) return $message;
        } while (true);
    }

    public function call(string $method, array $params = []): array
    {
        if ($this->inHandler || !preg_match('/^[A-Za-z]+\.[A-Za-z0-9]+$/D',$method) || !is_resource($this->socket)) throw new RuntimeException('Invalid or reentrant DevTools call.');
        $id=++$this->id;
        $this->deadline=microtime(true)+$this->timeoutMs/1000;
        $this->frame(json_encode(['id'=>$id,'method'=>$method,'params'=>$params===[]?(object)[]:$params],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $deadline=microtime(true)+$this->timeoutMs/1000;
        while (microtime(true)<$deadline) {
            $raw=$this->message();
            $message=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (!is_array($message)) throw new RuntimeException('Invalid DevTools message JSON.');
            if (($message['id'] ?? null)===$id) {
                if (isset($message['error'])) throw new RuntimeException('DevTools command rejected: '.$method);
                return $message['result'] ?? [];
            }
            if (isset($message['id'],$this->notifications[$message['id']])) {
                unset($this->notifications[$message['id']]);
                if(isset($message['error']))throw new RuntimeException('DevTools resource notification rejected.');
                continue;
            }
            if (isset($message['method'])) {
                if($this->handler!==null){
                    $this->inHandler=true;
                    try{($this->handler)($message);}finally{$this->inHandler=false;}
                }
                $this->eventBytes+=strlen($raw);
                if (count($this->events)>=1000 || $this->eventBytes>8000000) throw new RuntimeException('DevTools event queue exceeds bound.');
                $this->events[]=$message;
            }
        }
        throw new RuntimeException('DevTools command deadline exceeded.');
    }

    /** Event handlers may send resource acknowledgements without recursively reading responses. */
    public function notify(string $method,array $params=[]): void
    {
        if(!preg_match('/^[A-Za-z]+\.[A-Za-z0-9]+$/D',$method) || !is_resource($this->socket)
            || count($this->notifications)>=500)throw new RuntimeException('Invalid or excessive DevTools notification.');
        if(!$this->inHandler)$this->deadline=microtime(true)+$this->timeoutMs/1000;
        $id=++$this->id;$this->notifications[$id]=true;
        $this->frame(json_encode(['id'=>$id,'method'=>$method,'params'=>$params===[]?(object)[]:$params],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public function onEvent(?callable $handler): void
    {
        $this->handler=$handler===null?null:\Closure::fromCallable($handler);
    }

    public function events(): array
    {
        $events=$this->events; $this->events=[]; $this->eventBytes=0;
        return $events;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            $this->deadline=microtime(true)+1;
            try { $this->frame('',8); } catch (\Throwable) {}
            fclose($this->socket);
        }
        $this->socket=null;
    }

    public function __destruct() { $this->close(); }
}
