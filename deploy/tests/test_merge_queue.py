import copy
import io
import json
import unittest
import zipfile
from deploy.merge_queue import APIError, LABELS, MARKER, Queue, QueueError, REPO, parse_ticket, ticket_body, validate_receipt


def artifact(sha, **extra):
    receipt = dict(commit=sha, stand='ui', database_ready=True, image_id='sha256:'+'1'*64,
                   delivery={'transport': 'registry'}, **extra)
    stream = io.BytesIO()
    with zipfile.ZipFile(stream, 'w') as archive:
        archive.writestr('deployment.json', json.dumps(receipt))
        archive.writestr('image-id.txt', receipt['image_id'])
        archive.writestr('registry-ref.txt', 'ghcr.io/'+REPO+'@sha256:'+'2'*64)
    return stream.getvalue()


class FakeGitHub:
    def __init__(self):
        self.main = 'a'*40
        self.prs, self.comments, self.labels, self.parents = {}, {}, {}, {}
        self.runs = {1: dict(id=1, head_sha=self.main, path='.github/workflows/staging.yml',
                            status='completed', conclusion='success', event='push', display_title='initial')}
        self.events = []
        self.permission = 'write'
        self.fail_check = False
        self.fail_deploy = False
        self.fail_receipt = False
        self.change_head_during_check = False
        self.change_main_during_check = False
        self.new_run_404 = False
        self.visibility_delays = {}

    def add_pr(self, number, head):
        self.prs[number] = dict(number=number, state='open', draft=False, merged=False, mergeable=True,
            head={'sha': head, 'ref': 'task/'+str(number), 'repo': {'full_name': REPO}},
            base={'ref': 'main', 'repo': {'full_name': REPO}})
        self.labels[number] = set()
        self.parents[head] = [self.main]

    def pages(self, path):
        if '/comments' in path:
            number = int(path.split('/')[1])
            return [copy.deepcopy(c) for c in self.comments.values() if c['pr'] == number]
        label = path.split('labels=')[1].replace('%3A', ':')
        return [dict(number=n, pull_request={}) for n, labels in self.labels.items() if label in labels]

    def request(self, method, path, data=None):
        parts = path.split('?')[0].split('/')
        if path.startswith('collaborators/'):
            return {'permission': self.permission}
        if path == 'git/ref/heads/main':
            return {'object': {'sha': self.main}}
        if path.startswith('git/commits/'):
            return {'parents': [{'sha': s} for s in self.parents[parts[-1]]]}
        if path.startswith('compare/'):
            base, head = parts[-1].split('...')
            return {'status': 'ahead' if base in self.parents[head] else 'diverged'}
        if parts[0] == 'pulls':
            n = int(parts[1])
            if method == 'GET':
                return copy.deepcopy(self.prs[n])
            if parts[-1] == 'update-branch':
                self.events.append(('update', n))
                old = self.prs[n]['head']['sha']
                assert data['expected_head_sha'] == old
                head = str(n+4)*40
                self.parents[head] = [old, self.main]
                self.prs[n]['head']['sha'] = head
                return {}
            if parts[-1] == 'merge':
                self.events.append(('merge', n))
                assert data['sha'] == self.prs[n]['head']['sha']
                self.main = str(n+1)*40
                self.prs[n].update(state='closed', merged=True, merge_commit_sha=self.main)
                return {'merged': True, 'sha': self.main}
        if path.startswith('issues/comments/'):
            self.comments[int(parts[-1])]['body'] = data['body']
            return {}
        if parts[0] == 'issues':
            n = int(parts[1])
            if parts[-1] == 'comments':
                cid = len(self.comments)+100
                self.comments[cid] = dict(id=cid, pr=n, body=data['body'], user={'login': 'github-actions[bot]'})
                return copy.deepcopy(self.comments[cid])
            if 'labels' in parts:
                if method == 'POST':
                    self.labels[n].update(data['labels'])
                else:
                    self.labels[n].discard(parts[-1].replace('%3A', ':'))
                return {}
            return {'labels': [{'name': s} for s in self.labels[n]]}
        if 'workflows' in parts:
            if method == 'POST':
                if parts[2] == 'merge-queue.yml':
                    self.events.append(('wake', 0))
                    return {}
                rid = max(self.runs)+1
                nonce = data['inputs']['queue_ticket']
                phase = 'deploy' if data['ref'] == 'main' else 'check'
                fail = self.fail_deploy if phase == 'deploy' else self.fail_check
                self.runs[rid] = dict(id=rid, head_sha=data['inputs']['expected_sha'],
                    path='.github/workflows/staging.yml', status='completed',
                    conclusion='failure' if fail else 'success', event='workflow_dispatch', display_title=nonce)
                if self.new_run_404:
                    self.visibility_delays[rid] = 2
                self.events.append((phase, rid))
                if self.change_head_during_check and phase == 'check':
                    self.prs[int(data['ref'].split('/')[-1])]['head']['sha'] = 'f'*40
                if self.change_main_during_check and phase == 'check':
                    self.main = 'e'*40
                return {'workflow_run_id': rid}
            runs = list(self.runs.values())
            if 'head_sha=' in path:
                sha = path.split('head_sha=')[1].split('&')[0]
                runs = [r for r in runs if r['head_sha'] == sha]
            return {'workflow_runs': copy.deepcopy(runs)}
        if parts[:2] == ['actions', 'runs']:
            rid = int(parts[2])
            if self.visibility_delays.get(rid, 0):
                self.visibility_delays[rid] -= 1
                raise APIError(404, method, path)
            if parts[-1] == 'artifacts':
                return {'artifacts': [{'id': rid, 'name': 'release-'+str(rid), 'expired': False}]}
            return copy.deepcopy(self.runs[rid])
        raise AssertionError((method, path, data))

    def artifact(self, rid):
        self.events.append(('receipt', rid))
        return artifact('0'*40 if self.fail_receipt else self.runs[rid]['head_sha'])


class QueueTests(unittest.TestCase):
    def setUp(self):
        self.api = FakeGitHub()
        self.queue = Queue(self.api, sleep=lambda _: None)
        self.api.add_pr(1, 'b'*40)
        self.api.add_pr(2, 'c'*40)

    def enqueue(self, n):
        return self.queue.enqueue(n, self.api.prs[n]['head']['sha'], 'owner', str(n))

    def test_two_requests_survive_worker_coalescing_and_deploy_before_next_merge(self):
        self.enqueue(1); self.enqueue(2)
        self.queue.drain_one()
        self.assertEqual(self.queue.state(1)['stage'], 'done')
        self.assertEqual(self.queue.state(2)['stage'], 'queued')
        self.queue.drain_one()
        self.assertEqual(self.queue.state(2)['stage'], 'done')
        self.assertIn(('update', 2), self.api.events)
        first_deploy = next(rid for phase, rid in self.api.events if phase == 'deploy')
        self.assertLess(self.api.events.index(('receipt', first_deploy)), self.api.events.index(('merge', 2)))

    def test_duplicate_enqueue_is_idempotent(self):
        self.enqueue(1); self.enqueue(1)
        self.assertEqual(len(self.api.comments), 1)

    def test_unapproved_new_head_and_read_only_actor_are_rejected(self):
        with self.assertRaisesRegex(QueueError, 'HEAD_CHANGED'):
            self.queue.enqueue(1, 'd'*40, 'owner', '1')
        self.api.permission = 'read'
        with self.assertRaisesRegex(QueueError, 'NO_WRITE_PERMISSION'):
            self.enqueue(1)
        self.assertFalse(self.api.comments)

    def test_pushed_code_after_enqueue_cannot_be_merged(self):
        self.enqueue(1)
        self.api.prs[1]['head']['sha'] = 'd'*40
        with self.assertRaisesRegex(QueueError, 'HEAD_CHANGED'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 1), self.api.events)

    def test_pushed_code_during_checks_cannot_be_merged(self):
        self.enqueue(1); self.api.change_head_during_check = True
        with self.assertRaisesRegex(QueueError, 'HEAD_CHANGED'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 1), self.api.events)

    def test_failed_check_leaves_main_and_returns_only_that_pr_to_agent(self):
        self.enqueue(1); self.api.fail_check = True
        with self.assertRaisesRegex(QueueError, 'WORKFLOW_FAILED'):
            self.queue.drain_one()
        self.assertEqual(self.api.main, 'a'*40)
        self.assertEqual(self.queue.state(1)['stage'], 'blocked')

    def test_failed_deployment_stops_next_merge_and_can_resume_same_run(self):
        self.enqueue(1); self.enqueue(2); self.api.fail_deploy = True
        with self.assertRaisesRegex(QueueError, 'WORKFLOW_FAILED'):
            self.queue.drain_one()
        with self.assertRaisesRegex(QueueError, 'WORKFLOW_FAILED'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 2), self.api.events)
        self.api.fail_deploy = False
        rid = self.queue.state(1)['deploy_run']; self.api.runs[rid]['conclusion'] = 'success'
        self.queue.drain_one()
        self.assertEqual(self.api.events.count(('merge', 1)), 1)
        self.assertEqual(self.queue.state(1)['stage'], 'done')

    def test_successful_workflow_with_old_site_receipt_does_not_advance(self):
        self.enqueue(1); self.api.fail_receipt = True
        with self.assertRaisesRegex(QueueError, 'RECEIPT_MISMATCH'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 1), self.api.events)

    def test_human_cannot_forge_a_queue_ticket(self):
        state = {'stage': 'queued'}
        self.assertIsNone(parse_ticket({'id': 1, 'user': {'login': 'owner'}, 'body': ticket_body(state)}))

    def test_conflict_is_not_merged(self):
        self.api.parents['b'*40] = ['d'*40]
        self.api.prs[1]['mergeable'] = False
        self.enqueue(1)
        with self.assertRaisesRegex(QueueError, 'MERGE_CONFLICT'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 1), self.api.events)

    def test_bypass_of_queue_is_detected_before_the_next_merge(self):
        self.enqueue(1); self.api.change_main_during_check = True
        with self.assertRaisesRegex(QueueError, 'MAIN_CHANGED_OUTSIDE_QUEUE'):
            self.queue.drain_one()
        self.assertNotIn(('merge', 1), self.api.events)

    def test_recovery_after_merge_does_not_merge_twice(self):
        state = self.enqueue(1)
        self.queue.save(state, 'merging')
        self.api.main = '2'*40
        self.api.prs[1].update(state='closed', merged=True, merge_commit_sha=self.api.main)
        self.queue.drain_one()
        self.assertEqual(self.queue.state(1)['stage'], 'done')
        self.assertNotIn(('merge', 1), self.api.events)

    def test_failed_current_main_holds_the_entire_queue(self):
        self.enqueue(1); self.enqueue(2)
        self.api.runs[1]['conclusion'] = 'failure'
        for _ in range(2):
            with self.assertRaisesRegex(QueueError, 'WORKFLOW_FAILED'):
                self.queue.drain_one()
        self.assertTrue(self.queue.state(1)['blocked_main'])
        self.assertEqual(self.queue.state(2)['stage'], 'queued')
        self.assertNotIn(('merge', 2), self.api.events)
        self.assertNotIn(('wake', 0), self.api.events)

    def test_premerge_conflict_wakes_next_ticket_without_idle_polling(self):
        self.api.parents['b'*40] = ['d'*40]; self.api.prs[1]['mergeable'] = False
        self.enqueue(1); self.enqueue(2)
        with self.assertRaisesRegex(QueueError, 'MERGE_CONFLICT'):
            self.queue.drain_one()
        self.assertIn(('wake', 0), self.api.events)
        self.assertEqual(self.queue.state(2)['stage'], 'queued')

    def test_new_run_temporarily_invisible_is_read_again_without_redispatch(self):
        self.enqueue(1); self.api.new_run_404 = True
        self.queue.drain_one()
        self.assertEqual(self.queue.state(1)['stage'], 'done')
        self.assertEqual(len([p for p, _ in self.api.events if p == 'check']), 1)
        self.assertEqual(len([p for p, _ in self.api.events if p == 'deploy']), 1)
        self.assertEqual(self.api.events.count(('merge', 1)), 1)

    def test_missing_run_has_a_bounded_read_retry_and_a_useful_error(self):
        calls = []
        def missing(method, path, data=None):
            calls.append(path)
            raise APIError(404, method, path)
        self.api.request = missing
        with self.assertRaisesRegex(APIError, 'GET actions/runs/999'):
            self.queue.wait_run(999, 'b'*40)
        self.assertEqual(len(calls), 9)

    def test_denied_run_is_not_retried_as_if_it_were_new(self):
        calls = []
        def denied(method, path, data=None):
            calls.append(path)
            raise APIError(403, method, path)
        self.api.request = denied
        with self.assertRaises(APIError):
            self.queue.wait_run(999, 'b'*40)
        self.assertEqual(len(calls), 1)


if __name__ == '__main__':
    unittest.main()
