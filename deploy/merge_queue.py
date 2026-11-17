#!/usr/bin/env python3
"""One durable PR ticket, one Actions worker, and a verified deployment before the next merge."""
import io
import json
import os
import re
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

REPO = 'caymanlogistic-crypto/1c-ip-php'
WORKFLOW = 'staging.yml'
MARKER = '<!-- ipphp-merge-queue-v1 '
LABELS = ('queue:ready', 'queue:running', 'queue:blocked', 'queue:done')
POLL_SECONDS = 15


class QueueError(RuntimeError):
    pass


class APIError(QueueError):
    def __init__(self, status, method='', path=''):
        self.status = status
        super().__init__('GitHub API HTTP '+str(status)+(f' at {method} {path.split("?")[0]}' if path else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class GitHub:
    def __init__(self):
        self.token = os.environ['GH_TOKEN']

    def request(self, method, path, data=None):
        body = None if data is None else json.dumps(data).encode()
        req = urllib.request.Request('https://api.github.com/repos/'+REPO+'/'+path,
            data=body, method=method, headers={'Authorization': 'Bearer '+self.token,
            'Accept': 'application/vnd.github+json', 'Content-Type': 'application/json',
            'X-GitHub-Api-Version': '2026-03-10'})
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                raw = response.read()
                return json.loads(raw) if raw else None
        except urllib.error.HTTPError as exc:
            # Never print request headers, arbitrary API responses or credentials.
            raise APIError(exc.code, method, path) from None
        except urllib.error.URLError:
            raise QueueError('GITHUB_NETWORK_ERROR') from None

    def pages(self, path):
        rows = []
        for page in range(1, 101):
            batch = self.request('GET', path+('&' if '?' in path else '?')+f'per_page=100&page={page}')
            rows.extend(batch)
            if len(batch) < 100:
                return rows
        raise QueueError('PAGINATION_LIMIT')

    def artifact(self, artifact_id):
        req = urllib.request.Request(f'https://api.github.com/repos/{REPO}/actions/artifacts/{artifact_id}/zip',
            headers={'Authorization': 'Bearer '+self.token})
        try:
            urllib.request.build_opener(NoRedirect).open(req, timeout=30)
            raise QueueError('ARTIFACT_REDIRECT_REQUIRED')
        except urllib.error.HTTPError as exc:
            if exc.code != 302:
                raise APIError(exc.code) from None
            url = exc.headers['Location']
        if urllib.parse.urlparse(url).scheme != 'https':
            raise QueueError('ARTIFACT_URL_REJECTED')
        # Signed blob URL: do not forward the GitHub authorization header across hosts.
        with urllib.request.urlopen(url, timeout=30) as response:
            raw = response.read(2*1024*1024+1)
        if len(raw) > 2*1024*1024:
            raise QueueError('ARTIFACT_TOO_LARGE')
        return raw


def ticket_body(state):
    return ('Publication queue: **'+state['stage']+'**'+
            (' — '+state['reason'] if state.get('reason') else '')+
            '\n\n'+MARKER+json.dumps(state, sort_keys=True)+' -->')


def parse_ticket(comment):
    if comment.get('user', {}).get('login') != 'github-actions[bot]':
        return None
    body = comment.get('body', '')
    if MARKER not in body:
        return None
    try:
        state = json.loads(body.split(MARKER, 1)[1].split(' -->', 1)[0])
        state['comment_id'] = comment['id']
        return state
    except (ValueError, KeyError):
        return None


def validate_pr(pr, head):
    if (pr['state'] != 'open' or pr['draft'] or pr['base']['ref'] != 'main'
            or pr['base']['repo']['full_name'] != REPO or pr['head']['repo']['full_name'] != REPO):
        raise QueueError('PR_MUST_BE_OPEN_SAME_REPOSITORY_MAIN_AND_READY')
    if not re.fullmatch(r'[a-f0-9]{40}', head or '') or pr['head']['sha'] != head:
        raise QueueError('HEAD_CHANGED_REQUEST_NEW_APPROVAL')


def validate_receipt(raw, sha):
    try:
        with zipfile.ZipFile(io.BytesIO(raw)) as archive:
            if any(archive.getinfo(n).file_size > 65536 for n in ('deployment.json', 'image-id.txt', 'registry-ref.txt')):
                raise QueueError('DEPLOYMENT_ARTIFACT_TOO_LARGE')
            receipt = json.loads(archive.read('deployment.json'))
            image = archive.read('image-id.txt').decode().strip()
            ref = archive.read('registry-ref.txt').decode().strip()
    except (ValueError, KeyError, zipfile.BadZipFile):
        raise QueueError('DEPLOYMENT_ARTIFACT_INVALID') from None
    if (receipt.get('commit') != sha or receipt.get('stand') != 'ui'
            or not receipt.get('database_ready') or receipt.get('image_id') != image
            or not re.fullmatch(r'sha256:[a-f0-9]{64}', image)
            or not re.fullmatch(re.escape('ghcr.io/'+REPO)+r'@sha256:[a-f0-9]{64}', ref)
            or receipt.get('delivery', {}).get('transport') != 'registry'):
        raise QueueError('DEPLOYMENT_RECEIPT_MISMATCH')
    return receipt


class Queue:
    def __init__(self, api, sleep=time.sleep):
        self.api, self.sleep = api, sleep

    def pr(self, number):
        return self.api.request('GET', f'pulls/{number}')

    def main_sha(self):
        return self.api.request('GET', 'git/ref/heads/main')['object']['sha']

    def state(self, number):
        tickets = [s for c in self.api.pages(f'issues/{number}/comments') if (s := parse_ticket(c))]
        return max(tickets, key=lambda s: s['comment_id']) if tickets else None

    def save(self, state, stage=None, reason=None):
        if stage:
            state['stage'] = stage
        state['reason'] = reason
        self.api.request('PATCH', f"issues/comments/{state['comment_id']}", {'body': ticket_body(state)})
        label = 'queue:done' if state['stage'] == 'done' else ('queue:blocked' if state['stage'] == 'blocked' else
                ('queue:ready' if state['stage'] == 'queued' else 'queue:running'))
        current = self.api.request('GET', f"issues/{state['pr']}")
        for old in {x['name'] for x in current['labels']} & set(LABELS) - {label}:
            self.api.request('DELETE', f"issues/{state['pr']}/labels/"+urllib.parse.quote(old, safe=''))
        self.api.request('POST', f"issues/{state['pr']}/labels", {'labels': [label]})
        print(f"PR #{state['pr']}: {state['stage']}"+(f' ({reason})' if reason else ''), flush=True)

    def enqueue(self, number, head, actor, request_id):
        permission = self.api.request('GET', 'collaborators/'+urllib.parse.quote(actor, safe='')+'/permission')['permission']
        if permission not in ('admin', 'maintain', 'write'):
            raise QueueError('REQUESTER_HAS_NO_WRITE_PERMISSION')
        validate_pr(self.pr(number), head)
        old = self.state(number)
        if old and old['stage'] not in ('blocked', 'done'):
            if old['approved_head'] != head:
                raise QueueError('PR_ALREADY_QUEUED_WITH_ANOTHER_HEAD')
            return old
        state = dict(pr=number, approved_head=head, head=head, stage='queued', request_id=request_id,
                     requested_by=actor, requested_at=time.time())
        if old:
            state['comment_id'] = old['comment_id']
        else:
            comment = self.api.request('POST', f'issues/{number}/comments', {'body': ticket_body(state)})
            state['comment_id'] = comment['id']
        self.save(state)
        return state

    def pending(self):
        numbers = set()
        for label in LABELS[:3]:
            for issue in self.api.pages('issues?state=all&labels='+urllib.parse.quote(label, safe='')):
                if 'pull_request' in issue:
                    numbers.add(issue['number'])
        states = [s for n in numbers if (s := self.state(n)) and s['stage'] != 'done']
        # A merged release must finish before any unmerged PR is considered.
        active = [s for s in states if s.get('merged_sha') or s.get('blocked_main') or s['stage'] in ('updating', 'checking', 'merging')]
        queued = [s for s in states if s['stage'] == 'queued']
        return sorted(active or queued, key=lambda s: (s['requested_at'], s['comment_id']))

    def find_run(self, nonce):
        runs = self.api.request('GET', f'actions/workflows/{WORKFLOW}/runs?event=workflow_dispatch&per_page=100')['workflow_runs']
        return next((r for r in runs if r['display_title'] == nonce), None)

    def dispatch(self, ref, sha, nonce):
        run = self.find_run(nonce)
        if run:
            return run['id']
        result = self.api.request('POST', f'actions/workflows/{WORKFLOW}/dispatches',
            {'ref': ref, 'inputs': {'expected_sha': sha, 'queue_ticket': nonce}})
        if result and result.get('workflow_run_id'):
            return result['workflow_run_id']
        for _ in range(20):
            run = self.find_run(nonce)
            if run:
                return run['id']
            self.sleep(3)
        raise QueueError('DISPATCH_RUN_NOT_FOUND')

    def wait_run(self, run_id, sha, deployment=False):
        for attempt in range(400):
            try:
                run = self.api.request('GET', f'actions/runs/{run_id}')
            except APIError as exc:
                # A dispatch response can precede the new run becoming visible to GET.
                # Retry only this read, not dispatch/update/merge with an unknown result.
                if exc.status == 404 and attempt < 8:
                    self.sleep(3)
                    continue
                raise
            if run['head_sha'] != sha or run['path'] != '.github/workflows/staging.yml':
                raise QueueError('WORKFLOW_SHA_OR_PATH_MISMATCH')
            if run['status'] == 'completed':
                if run['conclusion'] != 'success':
                    raise QueueError('WORKFLOW_FAILED_'+str(run_id))
                if deployment:
                    artifacts = self.api.request('GET', f'actions/runs/{run_id}/artifacts')['artifacts']
                    artifact = next((a for a in artifacts if a['name'] == 'release-'+str(run_id) and not a['expired']), None)
                    if not artifact:
                        raise QueueError('DEPLOYMENT_ARTIFACT_REQUIRED')
                    return validate_receipt(self.api.artifact(artifact['id']), sha)
                return None
            self.sleep(POLL_SECONDS)
        raise QueueError('WORKFLOW_WAIT_TIMEOUT_'+str(run_id))

    def baseline(self):
        sha = self.main_sha()
        runs = self.api.request('GET', f'actions/workflows/{WORKFLOW}/runs?branch=main&head_sha={sha}&per_page=100')['workflow_runs']
        runs = [r for r in runs if r['head_sha'] == sha and r['event'] in ('push', 'workflow_dispatch')]
        if runs:
            self.wait_run(max(runs, key=lambda r: r['id'])['id'], sha, deployment=True)
        else:
            run_id = self.dispatch('main', sha, 'queue-baseline-'+sha)
            self.wait_run(run_id, sha, deployment=True)
        if self.main_sha() != sha:
            raise QueueError('MAIN_CHANGED_OUTSIDE_QUEUE')
        return sha

    def update_branch(self, state, pr):
        if state['stage'] == 'updating' and pr['head']['sha'] != state['head']:
            commit = self.api.request('GET', 'git/commits/'+pr['head']['sha'])
            parents = {p['sha'] for p in commit['parents']}
            if parents != {state['head'], state['base']}:
                raise QueueError('HEAD_CHANGED_REQUEST_NEW_APPROVAL')
            state['head'] = pr['head']['sha']
            self.save(state, 'queued')
        validate_pr(pr, state['head'])
        comparison = self.api.request('GET', f"compare/{state['base']}...{state['head']}")
        if comparison['status'] not in ('ahead', 'identical'):
            if pr.get('mergeable') is False:
                raise QueueError('MERGE_CONFLICT_AGENT_ACTION_REQUIRED')
            self.save(state, 'updating')
            self.api.request('PUT', f"pulls/{state['pr']}/update-branch", {'expected_head_sha': state['head']})
            for _ in range(40):
                updated = self.pr(state['pr'])
                if updated['head']['sha'] != state['head']:
                    return self.update_branch(state, updated)
                self.sleep(3)
            raise QueueError('BRANCH_UPDATE_TIMEOUT')

    def process(self, state):
        pr = self.pr(state['pr'])
        # Recover an interrupted/ambiguous merge by reading GitHub, never by merging again blindly.
        if pr.get('merged'):
            if state['stage'] not in ('merging', 'deploying', 'blocked'):
                raise QueueError('PR_MERGED_OUTSIDE_QUEUE')
            state['merged_sha'] = pr['merge_commit_sha']
        if not state.get('merged_sha'):
            try:
                state['base'] = self.baseline()
                state.pop('blocked_main', None)
            except QueueError:
                state['blocked_main'] = True
                raise
            self.update_branch(state, pr)
            nonce = f"queue-{state['comment_id']}-check-{state['request_id']}-{state['head'][:12]}"
            self.save(state, 'checking')
            run_id = self.dispatch(pr['head']['ref'], state['head'], nonce)
            self.wait_run(run_id, state['head'])
            validate_pr(self.pr(state['pr']), state['head'])
            if self.main_sha() != state['base']:
                raise QueueError('MAIN_CHANGED_OUTSIDE_QUEUE')
            self.save(state, 'merging')
            merged = self.api.request('PUT', f"pulls/{state['pr']}/merge",
                {'sha': state['head'], 'merge_method': 'merge', 'commit_title': f"Merge queued PR #{state['pr']}"})
            if not merged.get('merged'):
                raise QueueError('MERGE_REJECTED')
            state['merged_sha'] = merged['sha']
            self.save(state, 'deploying')
        sha = state['merged_sha']
        if self.main_sha() != sha:
            raise QueueError('MAIN_CHANGED_OUTSIDE_QUEUE')
        nonce = f"queue-{state['comment_id']}-deploy-{sha[:12]}"
        state['deploy_run'] = self.dispatch('main', sha, nonce)
        self.save(state, 'deploying')
        receipt = self.wait_run(state['deploy_run'], sha, deployment=True)
        if self.main_sha() != sha:
            raise QueueError('MAIN_CHANGED_OUTSIDE_QUEUE')
        state['image_id'] = receipt['image_id']
        self.save(state, 'done')

    def drain_one(self):
        pending = self.pending()
        if not pending:
            print('Queue is empty', flush=True)
            return
        state = pending[0]
        try:
            self.process(state)
        except QueueError as exc:
            self.save(state, 'blocked', str(exc))
            if not state.get('merged_sha') and not state.get('blocked_main') and self.pending():
                self.wake_next()
            raise
        if self.pending():
            self.wake_next()

    def wake_next(self):
        # Event-driven recovery, with no idle cron jobs consuming private-repo Actions minutes.
        self.api.request('POST', 'actions/workflows/merge-queue.yml/dispatches', {'ref': 'main', 'inputs': {}})


def main():
    import sys
    queue = Queue(GitHub())
    if sys.argv[1:] == ['enqueue']:
        queue.enqueue(int(os.environ['QUEUE_PR']), os.environ['QUEUE_HEAD'],
                      os.environ['GITHUB_ACTOR'], os.environ['GITHUB_RUN_ID'])
    elif sys.argv[1:] == ['drain']:
        queue.drain_one()
    else:
        raise QueueError('INVALID_INVOCATION')


if __name__ == '__main__':
    try:
        main()
    except QueueError as exc:
        print('QUEUE_BLOCKED: '+str(exc), flush=True)
        raise SystemExit(1)
