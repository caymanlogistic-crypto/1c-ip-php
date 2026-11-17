#!/usr/bin/python3
"""One pinned HTTPS destination for the existing internal app network. No default route."""
import json
import os
import pathlib
import subprocess
import time

ROOT = pathlib.Path('/srv/1c-ip-php/ui')
TARGET = '89.208.213.32'
SOURCE = '172.30.101.10'
GATEWAY = '172.30.101.1'
CHAIN = 'IPPHP-ONEC'


def run(*args, check=True):
    return subprocess.run(args, check=check, text=True, capture_output=True)


def validate_marker(value):
    if (set(value) != {'enabled', 'profile_reference', 'project_id', 'provider', 'scope', 'stand'}
            or value['enabled'] is not True or value['stand'] != 'ui'
            or value['project_id'] != 'f6e21e3c-d974-4604-82fb-333da66559d2'
            or value['provider'] != 'onec' or value['scope'] != 'bounded-check-only'
            or value['profile_reference'] != 'working-mounted-main-20261116-v1'):
        raise RuntimeError('ONEC_NETWORK_ADMISSION_REJECTED')


def target_interface(container, addresses):
    network = container['NetworkSettings']['Networks'].get('ipphp-ui_backend', {})
    if (not container['State']['Running'] or network.get('IPAddress') != SOURCE
            or len(container['NetworkSettings']['Networks']) != 1):
        raise RuntimeError('ONEC_NETWORK_CONTAINER_BINDING')
    interfaces = [item['ifname'] for item in addresses
                  if any(address.get('local') == SOURCE for address in item.get('addr_info', []))]
    if len(interfaces) != 1:
        raise RuntimeError('ONEC_NETWORK_INTERFACE_BINDING')
    return interfaces[0]


def validate_route(routes, interface):
    if routes and (len(routes) != 1 or routes[0].get('gateway') != GATEWAY
                   or routes[0].get('dev') != interface):
        raise RuntimeError('ONEC_NETWORK_FOREIGN_ROUTE')


def ensure_rule(table, chain, rule):
    prefix = ['iptables', '-w', '10', '-t', table]
    if run(*prefix, '-C', chain, *rule, check=False).returncode:
        run(*prefix, '-I', chain, '1', *rule)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('ONEC_NETWORK_ROOT_REQUIRED')
    marker = ROOT/'data/local/onec-read-admission.json'
    if not marker.exists():
        print(json.dumps({'onec_read_check': False, 'changed': False}))
        return
    if marker.is_symlink() or marker.stat().st_size > 4096:
        raise RuntimeError('ONEC_NETWORK_ADMISSION_REJECTED')
    validate_marker(json.loads(marker.read_text()))
    container = None
    for _ in range(20):
        result = run('docker', 'inspect', 'ipphp-ui-app-1', check=False)
        if result.returncode == 0:
            container = json.loads(result.stdout)[0]
            if container['State']['Running']:
                break
        time.sleep(1)
    if not container or not container['State']['Running']:
        raise RuntimeError('ONEC_NETWORK_APP_NOT_RUNNING')
    pid = str(container['State']['Pid'])
    addresses = json.loads(run('nsenter', '-t', pid, '-n', 'ip', '-j', 'address', 'show').stdout)
    interface = target_interface(container, addresses)
    routes = json.loads(run('nsenter', '-t', pid, '-n', 'ip', '-j', 'route', 'show', 'exact', TARGET+'/32').stdout)
    validate_route(routes, interface)
    # Only these two flows can cross Docker's internal-network isolation.
    if run('iptables', '-w', '10', '-S', CHAIN, check=False).returncode:
        run('iptables', '-w', '10', '-N', CHAIN)
    ensure_rule('filter', CHAIN, ['-s', SOURCE+'/32', '-d', TARGET+'/32', '-p', 'tcp', '--dport', '443', '-j', 'ACCEPT'])
    ensure_rule('filter', CHAIN, ['-s', TARGET+'/32', '-d', SOURCE+'/32', '-p', 'tcp', '--sport', '443', '-m', 'conntrack', '--ctstate', 'ESTABLISHED', '-j', 'ACCEPT'])
    ensure_rule('filter', 'DOCKER-USER', ['-j', CHAIN])
    ensure_rule('nat', 'POSTROUTING', ['-s', SOURCE+'/32', '-d', TARGET+'/32', '-p', 'tcp', '--dport', '443', '-m', 'comment', '--comment', 'ipphp-onec-https', '-j', 'MASQUERADE'])
    run('nsenter', '-t', pid, '-n', 'ip', 'route', 'replace', TARGET+'/32', 'via', GATEWAY, 'dev', interface)
    print(json.dumps({'onec_read_check': True, 'destination': TARGET+':443', 'source': SOURCE, 'default_route_added': False}))


if __name__ == '__main__':
    main()
