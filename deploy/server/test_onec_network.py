import importlib.util
import pathlib
import unittest
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('onec_network', pathlib.Path(__file__).with_name('onec-network.py'))
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)


class OnecNetworkTest(unittest.TestCase):
    def test_only_current_app_interface_is_accepted(self):
        container = {'State': {'Running': True}, 'NetworkSettings': {'Networks': {'ipphp-ui_backend': {'IPAddress': network.SOURCE}}}}
        addresses = [{'ifname': 'eth0', 'addr_info': [{'local': network.SOURCE}]}]
        self.assertEqual(network.target_interface(container, addresses), 'eth0')
        container['NetworkSettings']['Networks']['other'] = {}
        with self.assertRaisesRegex(RuntimeError, 'CONTAINER_BINDING'):
            network.target_interface(container, addresses)
        del container['NetworkSettings']['Networks']['other']
        with self.assertRaisesRegex(RuntimeError, 'INTERFACE_BINDING'):
            network.target_interface(container, [])

    def test_foreign_route_is_not_replaced(self):
        network.validate_route([], 'eth0')
        network.validate_route([{'gateway': network.GATEWAY, 'dev': 'eth0'}], 'eth0')
        for route in [{'gateway': '1.2.3.4', 'dev': 'eth0'}, {'gateway': network.GATEWAY, 'dev': 'eth1'}]:
            with self.assertRaisesRegex(RuntimeError, 'FOREIGN_ROUTE'):
                network.validate_route([route], 'eth0')

    def test_admission_cannot_enable_other_provider_or_scope(self):
        marker = {'enabled': True, 'profile_reference': 'working-mounted-main-20261116-v1',
                  'project_id': 'f6e21e3c-d974-4604-82fb-333da66559d2',
                  'provider': 'onec', 'scope': 'bounded-check-only', 'stand': 'ui'}
        network.validate_marker(marker)
        for key, value in [('provider', 'bank'), ('scope', 'sync'), ('enabled', False), ('stand', 'integrations'), ('profile_reference', 'other')]:
            with self.subTest(key=key), self.assertRaises(RuntimeError):
                network.validate_marker({**marker, key: value})

    def test_firewall_install_is_idempotent(self):
        installed = set()
        writes = []
        def fake_run(*args, **kwargs):
            action = '-C' if '-C' in args else '-I'
            index = args.index(action)
            rule = args[index+2:] if action == '-C' else args[index+3:]
            if action == '-C':
                return SimpleNamespace(returncode=0 if rule in installed else 1)
            installed.add(rule); writes.append(args)
            return SimpleNamespace(returncode=0)
        rule = ['-s', network.SOURCE+'/32', '-d', network.TARGET+'/32', '-p', 'tcp', '--dport', '443', '-j', 'ACCEPT']
        with patch.object(network, 'run', fake_run):
            network.ensure_rule('filter', network.CHAIN, rule)
            network.ensure_rule('filter', network.CHAIN, rule)
        self.assertEqual(len(writes), 1)


if __name__ == '__main__':
    unittest.main()
