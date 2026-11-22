import importlib.util
import json
import pathlib
import subprocess
import unittest
from types import SimpleNamespace
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    'dadata_network', pathlib.Path(__file__).with_name('dadata-network.py'))
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)
ADDRESS = '185.65.148.8'
TARGET = ADDRESS + '/32'


class DadataNetworkTest(unittest.TestCase):
    def test_pin_rejects_other_hosts_and_nonpublic_addresses(self):
        self.assertEqual(network.validate_pin({'host': network.HOST, 'ipv4': ADDRESS}), ADDRESS)
        for pin in ({'host': 'example.org', 'ipv4': ADDRESS},
                    {'host': network.HOST, 'ipv4': '172.30.101.1'},
                    {'host': network.HOST, 'ipv4': '224.0.0.1'},
                    {'host': network.HOST, 'ipv4': ADDRESS + '/24'}):
            with self.subTest(pin=pin), self.assertRaisesRegex(RuntimeError, 'PIN_REJECTED'):
                network.validate_pin(pin)

    def test_foreign_route_is_never_replaced(self):
        route = [{'gateway': network.GATEWAY, 'dev': 'eth0', 'protocol': 'static'}]
        with patch.object(network, 'run', return_value=SimpleNamespace(stdout=json.dumps(route))):
            with self.assertRaisesRegex(RuntimeError, 'FOREIGN_ROUTE'):
                network.route_exists(('ip',), 'eth0', TARGET)
            route[0]['protocol'] = network.PROTOCOL
        with patch.object(network, 'run', return_value=SimpleNamespace(stdout=json.dumps(route))):
            self.assertTrue(network.route_exists(('ip',), 'eth0', TARGET))

    def test_outgoing_and_established_return_are_only_app_to_pin_on_443(self):
        nat, outgoing, incoming = network.rules_for(TARGET)
        self.assertEqual(nat[:2], ('nat', 'POSTROUTING'))
        self.assertEqual(outgoing[:2], incoming[:2])
        self.assertEqual(outgoing[2][:8],
                         ('-s', network.SOURCE+'/32', '-d', TARGET, '-p', 'tcp', '--dport', '443'))
        self.assertEqual(nat[2][:8], outgoing[2][:8])
        self.assertEqual(incoming[2][:12],
                         ('-s', TARGET, '-d', network.SOURCE+'/32', '-p', 'tcp', '--sport', '443',
                          '-m', 'conntrack', '--ctstate', 'ESTABLISHED'))

    def test_repeat_and_remove_are_scoped(self):
        installed = set()
        route = False

        def firewall(rule, operation, check=True):
            if operation == '-C':
                return SimpleNamespace(returncode=0 if rule in installed else 1)
            if operation == '-I':
                self.assertNotIn(rule, installed)
                installed.add(rule)
            else:
                installed.remove(rule)

        def change_route(*args):
            nonlocal route
            self.assertIn(TARGET, args)
            route = args[2] == 'add'

        with patch.object(network, 'app_namespace', return_value=(('ip',), 'eth0')), \
                patch.object(network, 'route_exists', side_effect=lambda *_: route), \
                patch.object(network, 'firewall', side_effect=firewall), \
                patch.object(network, 'run', side_effect=change_route):
            network.configure(ADDRESS, 'restore')
            network.configure(ADDRESS, 'restore')
            self.assertEqual(len(installed), 3)
            self.assertTrue(route)
            network.configure(ADDRESS, 'remove')
            self.assertFalse(installed)
            self.assertFalse(route)

    def test_failed_route_add_rolls_back_new_rules(self):
        with patch.object(network, 'app_namespace', return_value=(('ip',), 'eth0')), \
                patch.object(network, 'route_exists', return_value=False), \
                patch.object(network, 'firewall', return_value=SimpleNamespace(returncode=1)) as firewall, \
                patch.object(network, 'run', side_effect=subprocess.CalledProcessError(1, 'ip')):
            with self.assertRaises(subprocess.CalledProcessError):
                network.configure(ADDRESS, 'restore')
        self.assertEqual([call.args[1] for call in firewall.call_args_list].count('-D'), 3)


if __name__ == '__main__':
    unittest.main()
