#!/usr/bin/python3
"""Install scoped HTTPS routes and DNS pins for the current cabinet profiles."""
import ipaddress
import json
import os
import pathlib
import socket
import subprocess
import tempfile

LOCAL = pathlib.Path('/srv/1c-ip-php/ui/data/local')
SOURCE = '172.30.101.10'
GATEWAY = '172.30.101.1'
CHAIN = 'IPPHP-CABINET'
HOSTS = {
    'diadoc': ('auth.kontur.ru', 'diadoc.kontur.ru'),
    'mosenergo': ('lkkjr.mosenergosbyt.ru',),
    'mosvodokanal': ('onewind.mosvodokanal.ru',),
    'pik': ('resident-cabinet-back.pik-software.ru',),
}


def run(*args, check=True):
    return subprocess.run(args, check=check, text=True, capture_output=True)


def pins(admission):
    if admission.get('scope') != 'hourly-cabinet-read' or not isinstance(admission.get('profiles'), dict):
        raise RuntimeError('CABINET_NETWORK_ADMISSION')
    profiles = admission['profiles']
    if set(profiles) - set(HOSTS):
        raise RuntimeError('CABINET_NETWORK_PROVIDER')
    out = {}
    for provider in profiles:
        for name in HOSTS[provider]:
            ips = {x[4][0] for x in socket.getaddrinfo(name, 443, socket.AF_INET, socket.SOCK_STREAM)}
            if not ips or any(not ipaddress.ip_address(ip).is_global for ip in ips):
                raise RuntimeError('CABINET_NETWORK_DNS')
            out[name] = min(ips, key=ipaddress.ip_address)
    return out


def reset(table, parent):
    prefix = ('iptables', '-w', '10', '-t', table)
    if run(*prefix, '-S', CHAIN, check=False).returncode:
        run(*prefix, '-N', CHAIN)
    if run(*prefix, '-C', parent, '-j', CHAIN, check=False).returncode:
        run(*prefix, '-I', parent, '1', '-j', CHAIN)
    run(*prefix, '-F', CHAIN)
    return (*prefix, '-A', CHAIN)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('CABINET_NETWORK_ROOT_REQUIRED')
    marker = LOCAL/'cabinet-hourly-read.json'
    if marker.is_symlink() or not marker.is_file() or marker.stat().st_size > 4096:
        raise RuntimeError('CABINET_NETWORK_ADMISSION')
    mapping = pins(json.loads(marker.read_text()))
    container = json.loads(run('docker', 'inspect', 'ipphp-ui-app-1').stdout)[0]
    networks = container['NetworkSettings']['Networks']
    if (not container['State']['Running'] or set(networks) != {'ipphp-ui_backend'}
            or networks['ipphp-ui_backend']['IPAddress'] != SOURCE):
        raise RuntimeError('CABINET_NETWORK_CONTAINER')
    ip_cmd = ('nsenter', '-t', str(container['State']['Pid']), '-n', 'ip')
    addresses = json.loads(run(*ip_cmd, '-j', 'address', 'show').stdout)
    interfaces = [item['ifname'] for item in addresses
                  if any(addr.get('local') == SOURCE for addr in item.get('addr_info', []))]
    if len(interfaces) != 1:
        raise RuntimeError('CABINET_NETWORK_INTERFACE')
    interface = interfaces[0]
    targets = sorted(set(mapping.values()))
    for target in targets:
        existing = json.loads(run(*ip_cmd, '-j', 'route', 'show', 'exact', target+'/32').stdout)
        if existing and (len(existing) != 1 or existing[0].get('gateway') != GATEWAY
                         or existing[0].get('dev') != interface):
            raise RuntimeError('CABINET_NETWORK_FOREIGN_ROUTE')
    rules = reset('filter', 'DOCKER-USER')
    nat = reset('nat', 'POSTROUTING')
    for target in targets:
        run(*rules, '-s', SOURCE+'/32', '-d', target+'/32', '-p', 'tcp', '--dport', '443', '-j', 'ACCEPT')
        run(*rules, '-s', target+'/32', '-d', SOURCE+'/32', '-p', 'tcp', '--sport', '443',
            '-m', 'conntrack', '--ctstate', 'ESTABLISHED', '-j', 'ACCEPT')
        run(*nat, '-s', SOURCE+'/32', '-d', target+'/32', '-p', 'tcp', '--dport', '443', '-j', 'MASQUERADE')
        run(*ip_cmd, 'route', 'replace', target+'/32', 'via', GATEWAY, 'dev', interface)
    with tempfile.NamedTemporaryFile(mode='w', dir=LOCAL, prefix='.cabinet-network-', delete=False) as handle:
        json.dump(mapping, handle)
        os.fchmod(handle.fileno(), 0o644)
        temporary = handle.name
    os.replace(temporary, LOCAL/'cabinet-network.json')
    print(json.dumps({'cabinet_hosts': sorted(mapping), 'https_routes': len(targets)}))


if __name__ == '__main__':
    main()
