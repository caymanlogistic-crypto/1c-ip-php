#!/usr/bin/python3
"""Pinned MAX destination for the existing internal app network. No default route."""
import json
import os
import pathlib
import re
import subprocess
import time

ROOT = pathlib.Path('/srv/1c-ip-php/ui')
TARGETS = ('155.212.204.149',)
SOURCE = '172.30.101.10'
GATEWAY = '172.30.101.1'
CHAIN = 'IPPHP-OBJECT-MAX'


def run(*args, check=True):
    return subprocess.run(args, check=check, text=True, capture_output=True)


def validate_marker(value):
    expected = {'schema': 1, 'enabled': True, 'project_id': 'f6e21e3c-d974-4604-82fb-333da66559d2',
                'scope': 'object-activity', 'stand': 'ui', 'database': 'ipphp_ui', 'group_name': 'ТЕСТОВАЯ'}
    keys = set(expected) | {'revision', 'group_id', 'chat_id', 'vault_sha256', 'event_floor'}
    if (not isinstance(value, dict) or set(value) != keys
            or any(value.get(k) != v for k, v in expected.items())
            or type(value.get('enabled')) is not bool or type(value.get('schema')) is not int
            or type(value.get('revision')) is not int or value['revision'] < 1
            or type(value.get('event_floor')) is not int or value['event_floor'] < 0):
        raise RuntimeError('OBJECT_MAX_NETWORK_ADMISSION_REJECTED')
    for key, pattern in [('group_id', r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}'),
                         ('chat_id', r'-?[0-9]{1,24}'), ('vault_sha256', r'[a-f0-9]{64}')]:
        if not isinstance(value.get(key), str) or not re.fullmatch(pattern, value[key]):
            raise RuntimeError('OBJECT_MAX_NETWORK_ADMISSION_REJECTED')



def target_interface(container, addresses):
    network = container['NetworkSettings']['Networks'].get('ipphp-ui_backend', {})
    if (not container['State']['Running'] or network.get('IPAddress') != SOURCE
            or len(container['NetworkSettings']['Networks']) != 1):
        raise RuntimeError('OBJECT_MAX_NETWORK_CONTAINER_BINDING')
    interfaces = [item['ifname'] for item in addresses
                  if any(address.get('local') == SOURCE for address in item.get('addr_info', []))]
    if len(interfaces) != 1:
        raise RuntimeError('OBJECT_MAX_NETWORK_INTERFACE_BINDING')
    return interfaces[0]


def validate_route(routes, interface):
    if routes and (len(routes) != 1 or routes[0].get('gateway') != GATEWAY
                   or routes[0].get('dev') != interface):
        raise RuntimeError('OBJECT_MAX_NETWORK_FOREIGN_ROUTE')


def ensure_rule(table, chain, rule):
    prefix = ['iptables', '-w', '10', '-t', table]
    if run(*prefix, '-C', chain, *rule, check=False).returncode:
        run(*prefix, '-I', chain, '1', *rule)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('OBJECT_MAX_NETWORK_ROOT_REQUIRED')
    marker = ROOT/'data/local/object-max-admission.json'
    if not marker.exists():
        print(json.dumps({'object_activity_max': False, 'changed': False}))
        return
    if marker.is_symlink() or marker.stat().st_size > 4096:
        raise RuntimeError('OBJECT_MAX_NETWORK_ADMISSION_REJECTED')
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
        raise RuntimeError('OBJECT_MAX_NETWORK_APP_NOT_RUNNING')
    pid = str(container['State']['Pid'])
    addresses = json.loads(run('nsenter', '-t', pid, '-n', 'ip', '-j', 'address', 'show').stdout)
    interface = target_interface(container, addresses)
    # Validate every existing route before changing any network rule.
    for target in TARGETS:
        routes = json.loads(run('nsenter', '-t', pid, '-n', 'ip', '-j', 'route', 'show', 'exact', target+'/32').stdout)
        validate_route(routes, interface)
    if run('iptables', '-w', '10', '-S', CHAIN, check=False).returncode:
        run('iptables', '-w', '10', '-N', CHAIN)
    for target in TARGETS:
        ensure_rule('filter', CHAIN, ['-s', SOURCE+'/32', '-d', target+'/32', '-p', 'tcp', '--dport', '443', '-j', 'ACCEPT'])
        ensure_rule('filter', CHAIN, ['-s', target+'/32', '-d', SOURCE+'/32', '-p', 'tcp', '--sport', '443', '-m', 'conntrack', '--ctstate', 'ESTABLISHED', '-j', 'ACCEPT'])
        ensure_rule('nat', 'POSTROUTING', ['-s', SOURCE+'/32', '-d', target+'/32', '-p', 'tcp', '--dport', '443', '-m', 'comment', '--comment', 'ipphp-object-max', '-j', 'MASQUERADE'])
        run('nsenter', '-t', pid, '-n', 'ip', 'route', 'replace', target+'/32', 'via', GATEWAY, 'dev', interface)
    ensure_rule('filter', 'DOCKER-USER', ['-j', CHAIN])
    print(json.dumps({'object_activity_max': True, 'destinations': [target+':443' for target in TARGETS], 'source': SOURCE, 'default_route_added': False}))


if __name__ == '__main__':
    main()
