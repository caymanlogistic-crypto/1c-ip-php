"""Check immutable delivery, credential cleanup and build-cache boundaries without a live registry."""
import ast
import json
import os
import pathlib
import re
import subprocess
import tempfile
import time
import unittest
from types import SimpleNamespace


def receiver_helpers(run):
    tree = ast.parse(pathlib.Path(__file__).with_name('deploy.py').read_text(encoding='utf-8'))
    names = ('REPO', 'REGISTRY_IMAGE')
    nodes = [n for n in tree.body if
             isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id in names for t in n.targets)
             or isinstance(n, ast.FunctionDef) and n.name in ('validate_registry_header', 'pull_registry_image')]
    scope = dict(re=re, os=os, tempfile=tempfile, time=time, subprocess=subprocess, json=json, run=run)
    exec(compile(ast.Module(body=nodes, type_ignores=[]), 'registry-receiver', 'exec'), scope)
    return scope


class RegistryDeliveryTest(unittest.TestCase):
    def setUp(self):
        self.header = dict(commit='a'*40, image_id='sha256:'+'b'*64,
                           registry_ref='ghcr.io/caymanlogistic-crypto/1c-ip-php@sha256:'+'c'*64,
                           registry_user='github-actions[bot]', registry_token='ephemeral-test-token-123456')
        self.calls = []
        self.configs = []
        self.actual = dict(Id=self.header['image_id'], Config={'Labels': {'org.opencontainers.image.revision': self.header['commit']}}, Size=615000000)

    def fake_run(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        if 'env' in kwargs:
            config = pathlib.Path(kwargs['env']['DOCKER_CONFIG'])
            self.assertTrue(config.is_dir())
            self.configs.append(config)
        if args[1:3] == ('image', 'inspect'):
            return SimpleNamespace(stdout=json.dumps([self.actual]))
        return SimpleNamespace(stdout='old: Already exists\nnew: Pull complete\n')

    def test_only_exact_private_image_digest_can_be_pulled(self):
        validate = receiver_helpers(self.fake_run)['validate_registry_header']
        validate(self.header)
        for ref in ('ghcr.io/other/project@sha256:'+'c'*64,
                    'ghcr.io/caymanlogistic-crypto/1c-ip-php:latest',
                    self.header['registry_ref']+'/extra', '--help'):
            with self.subTest(ref=ref), self.assertRaisesRegex(RuntimeError, 'REGISTRY_REF_REJECTED'):
                validate({**self.header, 'registry_ref': ref})

    def test_pull_reuses_layers_and_inspects_the_requested_digest(self):
        delivery = receiver_helpers(self.fake_run)['pull_registry_image'](self.header)
        self.assertEqual(delivery['layers_downloaded'], 1)
        self.assertEqual(delivery['layers_already_present'], 1)
        self.assertEqual(self.calls[-1][0], ('docker', 'image', 'inspect', self.header['registry_ref']))
        self.assertNotIn('registry_token', self.header)
        self.assertNotIn('ephemeral-test-token', json.dumps(delivery))
        self.assertTrue(all(not p.exists() for p in self.configs))
        login_args, login_kwargs = self.calls[0]
        self.assertIn('--password-stdin', login_args)
        self.assertNotIn('ephemeral-test-token-123456', login_args)
        self.assertEqual(login_kwargs['input'], 'ephemeral-test-token-123456\n')

    def test_login_and_pull_failures_remove_credentials_without_logging_them(self):
        for failed_command in ('login', 'pull'):
            self.configs = []
            def failing(*args, **kwargs):
                result = self.fake_run(*args, **kwargs)
                if args[1] == failed_command:
                    raise subprocess.CalledProcessError(1, args, stderr='secret-response')
                return result
            header = dict(self.header)
            with self.assertRaisesRegex(RuntimeError, '^REGISTRY_PULL_FAILED$'):
                receiver_helpers(failing)['pull_registry_image'](header)
            self.assertNotIn('registry_token', header)
            self.assertTrue(all(not p.exists() for p in self.configs))

    def test_config_id_and_commit_must_both_match_before_activation(self):
        for field in ('id', 'commit'):
            self.actual['Id'] = 'sha256:'+'d'*64 if field == 'id' else self.header['image_id']
            self.actual['Config']['Labels']['org.opencontainers.image.revision'] = 'e'*40 if field == 'commit' else self.header['commit']
            with self.subTest(field=field), self.assertRaisesRegex(RuntimeError, 'IMAGE_REVISION_MISMATCH'):
                receiver_helpers(self.fake_run)['pull_registry_image'](dict(self.header))

    def test_timeout_keeps_a_specific_nonsecret_error(self):
        def timeout(*args, **kwargs):
            self.fake_run(*args, **kwargs)
            raise subprocess.TimeoutExpired(args, 30)
        with self.assertRaisesRegex(RuntimeError, '^REGISTRY_PULL_TIMEOUT$'):
            receiver_helpers(timeout)['pull_registry_image'](self.header)
        self.assertTrue(all(not p.exists() for p in self.configs))

    def test_commit_metadata_does_not_invalidate_runtime_and_vendor(self):
        root = pathlib.Path(__file__).resolve().parents[2]
        dockerfile = (root/'deploy/container/Dockerfile').read_text(encoding='utf-8')
        self.assertGreater(dockerfile.index('ARG SOURCE_COMMIT'), dockerfile.index('RUN composer install'))
        workflow = (root/'.github/workflows/staging.yml').read_text(encoding='utf-8')
        self.assertNotIn('docker save', workflow)
        self.assertNotIn('image.tar.gz', workflow)
        self.assertIn('--cache-from "type=registry,ref=$registry:buildcache"', workflow)
        self.assertIn('test "$GITHUB_REF" = refs/heads/main', workflow)
        self.assertIn('if: github.event_name == \'push\' && github.ref == \'refs/heads/main\'', workflow)


if __name__ == '__main__':
    unittest.main()
