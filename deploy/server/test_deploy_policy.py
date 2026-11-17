"""Exercise the actual receiver policy without root, Linux fcntl, Docker or network."""
import ast
import pathlib
import unittest


def load_policy():
    source = ast.parse(pathlib.Path(__file__).with_name('deploy.py').read_text(encoding='utf-8'))
    # Only the two constants and the pure function; importing deploy.py starts the receiver.
    nodes = [node for node in source.body if
             isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id in ('REPO', 'WORKFLOW') for t in node.targets)
             or isinstance(node, ast.FunctionDef) and node.name == 'validate_claims']
    scope = {}
    exec(compile(ast.Module(body=nodes, type_ignores=[]), 'receiver-policy', 'exec'), scope)
    return scope['validate_claims']


class ReleasePolicyTest(unittest.TestCase):
    def setUp(self):
        self.validate = load_policy()
        self.sha = 'a' * 40
        self.run_id = '123'
        self.claims = {
            'repository': 'caymanlogistic-crypto/1c-ip-php',
            'repository_owner_id': '251781345', 'ref': 'refs/heads/main',
            'sha': self.sha, 'run_id': self.run_id, 'event_name': 'push',
            'workflow_ref': 'caymanlogistic-crypto/1c-ip-php/.github/workflows/staging.yml@refs/heads/main',
        }

    def test_exact_main_release(self):
        self.validate(self.claims, self.sha, self.run_id)

    def test_queue_can_dispatch_only_the_exact_main_release(self):
        self.validate({**self.claims, 'event_name': 'workflow_dispatch'}, self.sha, self.run_id)
        for ref in ('refs/heads/task/example', 'refs/pull/1/merge'):
            with self.assertRaisesRegex(RuntimeError, 'OIDC_SCOPE_REJECTED'):
                self.validate({**self.claims, 'event_name': 'workflow_dispatch', 'ref': ref}, self.sha, self.run_id)
        for event in ('pull_request', 'workflow_run', 'repository_dispatch', 'schedule'):
            with self.assertRaisesRegex(RuntimeError, 'OIDC_SCOPE_REJECTED'):
                self.validate({**self.claims, 'event_name': event}, self.sha, self.run_id)

    def test_other_branches_and_pr_cannot_deploy(self):
        for ref in ('refs/heads/staging-ui', 'refs/heads/staging-integrations',
                    'refs/heads/task/example', 'refs/pull/1/merge'):
            with self.subTest(ref=ref), self.assertRaisesRegex(RuntimeError, 'OIDC_SCOPE_REJECTED'):
                self.validate({**self.claims, 'ref': ref}, self.sha, self.run_id)

    def test_each_claim_is_required_and_bound(self):
        for key in self.claims:
            for value in (None, 'different'):
                altered = dict(self.claims)
                if value is None:
                    del altered[key]
                else:
                    altered[key] = value
                with self.subTest(claim=key, value=value), self.assertRaisesRegex(RuntimeError, 'OIDC_SCOPE_REJECTED'):
                    self.validate(altered, self.sha, self.run_id)


if __name__ == '__main__':
    unittest.main()
