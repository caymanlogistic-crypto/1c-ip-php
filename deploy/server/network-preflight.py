#!/usr/bin/python3
"""Unauthenticated DNS/TCP/TLS/HEAD prerequisites only; no provider login."""
import concurrent.futures,datetime,http.client,json,pathlib,socket,ssl,time
TARGETS=[('1c','1c.dltkmsk.ru',443),('bank-via-1c','1c.dltkmsk.ru',443),
 ('diadoc','diadoc.kontur.ru',443),('diadoc-auth','auth.kontur.ru',443),
 ('mosenergo','lkkjr.mosenergosbyt.ru',443),('mosvodokanal','onewind.mosvodokanal.ru',443),
 ('pik','resident-cabinet-back.pik-software.ru',443),('email-configured','imap.spaceweb.ru',465),
 ('max','platform-api2.max.ru',443),('cian','www.cian.ru',443),('yandex','realty.yandex.ru',443),
 ('morrow','morrowgroup.ru',443),('fortex','fortexgroup.ru',443)]
def check(target):
 name,host,port=target;out={'provider':name,'host':host,'port':port,'dns':False,'tcp':False,'tls_verified':False}
 started=time.monotonic()
 try:
  out['addresses']=sorted(set(r[4][0] for r in socket.getaddrinfo(host,port,type=socket.SOCK_STREAM)));out['dns']=True
  with socket.create_connection((host,port),timeout=6) as raw:
   out['tcp']=True
   with ssl.create_default_context().wrap_socket(raw,server_hostname=host) as tls:
    out['tls_verified']=True;out['tls_version']=tls.version()
    if port==443:
     tls.sendall(('HEAD / HTTP/1.1\r\nHost: '+host+'\r\nConnection: close\r\nUser-Agent: ipphp-network-preflight\r\n\r\n').encode())
     out['http_status']=int(tls.recv(1024).split(b' ',2)[1])
    else:out['smtp_greeting_received']=tls.recv(256).startswith(b'220')
 except Exception as e:out['error_type']=type(e).__name__
 out['duration_ms']=round((time.monotonic()-started)*1000);return out
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:results=list(pool.map(check,TARGETS))
value={'checked_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'scope':'DNS/TCP/TLS and anonymous HEAD; no login/read-data/send','results':results}
path=pathlib.Path('/srv/1c-ip-php/network-preflight.json');path.write_text(json.dumps(value,indent=2)+'\n');print(json.dumps(value,indent=2))
