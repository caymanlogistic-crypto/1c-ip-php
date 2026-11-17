import importlib.util
import pathlib
import unittest

spec = importlib.util.spec_from_file_location('object_max_network', pathlib.Path(__file__).parents[1]/'server/object-max-network.py')
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)


class ObjectMaxNetworkTest(unittest.TestCase):
    def marker(self):
        return dict(schema=1, enabled=True, project_id='f6e21e3c-d974-4604-82fb-333da66559d2',
                    scope='object-activity', stand='ui', database='ipphp_ui', group_name='ТЕСТОВАЯ',
                    revision=6, group_id='d426f2fc-0acb-537b-9139-331fc45a76ce', chat_id='-123',
                    vault_sha256='a'*64, event_floor=1000)

    def test_scope(self):
        network.validate_marker(self.marker())
        for change in [dict(scope='all'), dict(group_name='other'), dict(enabled=1), dict(revision=0),
                       dict(event_floor=-1), dict(extra=True), dict(chat_id='https://other')]:
            with self.assertRaises(RuntimeError):
                network.validate_marker(self.marker() | change)
        self.assertEqual(network.TARGETS, ('155.212.204.149',))

    def test_foreign_route(self):
        network.validate_route([], 'eth0')
        network.validate_route([dict(gateway=network.GATEWAY, dev='eth0')], 'eth0')
        with self.assertRaises(RuntimeError):
            network.validate_route([dict(gateway='10.0.0.1', dev='eth0')], 'eth0')


if __name__ == '__main__':
    unittest.main()
