#!/usr/bin/python3
"""Maintain the single approved DaData HTTPS route for the internal UI container."""
import argparse
import ipaddress
import json
import os
import pathlib
import socket
import ssl
import subprocess

PIN = pathlib.Path('/srv/1c-ip-php/ui/data/local/dadata-network.json')
LOCK = pathlib.Path('/run/lock/ipphp-dadata-network.lock')
HOST = 'suggestions.dadata.ru'
SOURCE = '172.30.101.10'
GATEWAY = '172.30.101.1'
NETWORK = 'ipphp-ui_backend'
CONTAINER = 'ipphp-ui-app-1'
PROTOCOL = '196'
TAG = ('-m', 'comment', '--comment', 'ipphp-dadata')


def run(*args, check=True):
    return subprocess.run(args, check=check, text=True, capture_output=True)


def validate_pin(pin):
    if (not isinstance(pin, dict) or set(pin) != {'host', 'ipv4'} or pin['host'] != HOST
            or not isinstance(pin['ipv4'], str)):
        raise RuntimeError('DADATA_NETWORK_PIN_REJECTED')
    try:
        address = ipaddress.IPv4Address(pin['ipv4'])
    except ValueError as error:
        raise RuntimeError('DADATA_NETWORK_PIN_REJECTED') from error
    if not address.is_global or address.is_multicast:
        raise RuntimeError('DADATA_NETWORK_PIN_REJECTED')
    return str(address)


def check_endpoint(address):
    addresses = {entry[4][0] for entry in socket.getaddrinfo(
        HOST, 443, socket.AF_INET, socket.SOCK_STREAM)}
    if address not in addresses:
        raise RuntimeError('DADATA_NETWORK_DNS_PIN_CHANGED')
    with socket.create_connection((address, 443), timeout=5) as connection:
        with ssl.create_default_context().wrap_socket(connection, server_hostname=HOST):
            pass


def app_namespace(allow_stopped=False):
    network = json.loads(run('docker', 'network', 'inspect', NETWORK).stdout)[0]
    if not network.get('Internal'):
        raise RuntimeError('DADATA_NETWORK_NOT_INTERNAL')
    inspected = run('docker', 'inspect', CONTAINER, check=False)
    if inspected.returncode and allow_stopped:
        return None, None
    inspected.check_returncode()
    container = json.loads(inspected.stdout)[0]
    if not container['State']['Running'] and allow_stopped:
        return None, None
    networks = container['NetworkSettings']['Networks']
    if (not container['State']['Running'] or set(networks) != {NETWORK}
            or networks[NETWORK]['IPAddress'] != SOURCE):
        raise RuntimeError('DADATA_NETWORK_APP_BINDING')
    ip = ('nsenter', '-t', str(container['State']['Pid']), '-n', 'ip', '-4')
    if json.loads(run(*ip, '-j', 'route', 'show', 'default').stdout):
        raise RuntimeError('DADATA_NETWORK_DEFAULT_ROUTE')
    interfaces = [item['ifname'] for item in json.loads(run(*ip, '-j', 'address', 'show').stdout)
                  if any(a.get('local') == SOURCE for a in item.get('addr_info', []))]
    if len(interfaces) != 1:
        raise RuntimeError('DADATA_NETWORK_INTERFACE')
    return ip, interfaces[0]


def route_exists(ip, interface, target):
    routes = json.loads(run(*ip, '-j', 'route', 'show', 'exact', target).stdout)
    if not routes:
        return False
    if (len(routes) != 1 or routes[0].get('gateway') != GATEWAY or routes[0].get('dev') != interface
            or str(routes[0].get('protocol')) != PROTOCOL):
        raise RuntimeError('DADATA_NETWORK_FOREIGN_ROUTE')
    return True


def rules_for(target):
    outgoing = ('-s', SOURCE + '/32', '-d', target, '-p', 'tcp', '--dport', '443')
    return [
        ('nat', 'POSTROUTING', (*outgoing, *TAG, '-j', 'MASQUERADE')),
        ('filter', 'DOCKER-USER', (*outgoing, *TAG, '-j', 'ACCEPT')),
        ('filter', 'DOCKER-USER',
         ('-s', target, '-d', SOURCE + '/32', '-p', 'tcp', '--sport', '443',
          '-m', 'conntrack', '--ctstate', 'ESTABLISHED', *TAG, '-j', 'ACCEPT')),
    ]


def firewall(rule, operation, check=True):
    table, chain, arguments = rule
    return run('iptables', '-w', '10', '-t', table, operation, chain, *arguments, check=check)


def configure(address, operation):
    ip, interface = app_namespace(allow_stopped=operation == 'remove')
    target = address + '/32'
    rules = rules_for(target)
    existing_route = ip is not None and route_exists(ip, interface, target)
    if operation == 'check':
        check_endpoint(address)
        return
    route = (target, 'via', GATEWAY, 'dev', interface, 'proto', PROTOCOL)
    if operation == 'remove':
        for rule in reversed(rules):
            if firewall(rule, '-C', check=False).returncode == 0:
                firewall(rule, '-D')
        if existing_route:
            run(*ip, 'route', 'del', *route)
        return
    added = []
    try:
        for rule in rules:
            if firewall(rule, '-C', check=False).returncode:
                firewall(rule, '-I')
                added.append(rule)
        if not existing_route:
            run(*ip, 'route', 'add', *route)
    except subprocess.CalledProcessError:
        for rule in reversed(added):
            firewall(rule, '-D')
        raise


def main():
    import fcntl

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', nargs='?', choices=('restore', 'check', 'remove'), default='restore')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise RuntimeError('DADATA_NETWORK_ROOT_REQUIRED')
    with LOCK.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if PIN.is_symlink() or (PIN.exists() and (not PIN.is_file() or PIN.stat().st_size > 512
                or PIN.stat().st_uid != 0 or PIN.stat().st_mode & 0o022)):
            raise RuntimeError('DADATA_NETWORK_PIN_REJECTED')
        if not PIN.exists():
            if args.operation != 'restore':
                raise RuntimeError('DADATA_NETWORK_PIN_MISSING')
            print('DaData network: disabled')
            return
        configure(validate_pin(json.loads(PIN.read_text())), args.operation)
    print(f'DaData network: {args.operation} complete')


if __name__ == '__main__':
    main()
