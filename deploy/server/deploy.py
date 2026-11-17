#!/usr/bin/python3
"""Root-owned receiver. CI can deploy only an OIDC-attested main commit for the one existing site."""
import datetime, fcntl, gzip, hashlib, json, os, pathlib, re, shutil, subprocess, sys, tempfile, time
import jwt

BASE = pathlib.Path('/srv/1c-ip-php')
REPO = 'caymanlogistic-crypto/1c-ip-php'
WORKFLOW = REPO + '/.github/workflows/staging.yml@refs/heads/main'
REGISTRY_IMAGE = 'ghcr.io/' + REPO

def validate_registry_header(header):
    if not re.fullmatch(re.escape(REGISTRY_IMAGE) + r'@sha256:[a-f0-9]{64}', header.get('registry_ref', '')):
        raise RuntimeError('REGISTRY_REF_REJECTED')
    if not re.fullmatch(r'[a-zA-Z0-9_.\[\]-]{1,100}', header.get('registry_user', '')):
        raise RuntimeError('REGISTRY_USER_REJECTED')
    token = header.get('registry_token', '')
    if not isinstance(token, str) or not 20 <= len(token) <= 2000 or any(c.isspace() for c in token):
        raise RuntimeError('REGISTRY_TOKEN_REJECTED')

def pull_registry_image(header):
    validate_registry_header(header)
    # Remove the credential before a receipt can be constructed, even on failure.
    token = header.pop('registry_token')
    started = time.monotonic()
    with tempfile.TemporaryDirectory(prefix='ipphp-registry-') as config:
        env = {**os.environ, 'DOCKER_CONFIG': config}
        try:
            run('docker', 'login', 'ghcr.io', '--username', header['registry_user'], '--password-stdin',
                input=token+'\n', env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
            result = run('docker', 'pull', header['registry_ref'], env=env,
                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=900)
        except subprocess.TimeoutExpired:
            raise RuntimeError('REGISTRY_PULL_TIMEOUT') from None
        except subprocess.CalledProcessError:
            raise RuntimeError('REGISTRY_PULL_FAILED') from None
    # Inspect the immutable reference, not a mutable tag or an unrelated local image ID.
    actual = json.loads(run('docker', 'image', 'inspect', header['registry_ref'], stdout=subprocess.PIPE).stdout)[0]
    if actual['Id'] != header['image_id'] or actual['Config']['Labels'].get('org.opencontainers.image.revision') != header['commit']:
        raise RuntimeError('IMAGE_REVISION_MISMATCH')
    return {'transport': 'registry', 'pull_seconds': round(time.monotonic()-started, 3),
            'layers_downloaded': result.stdout.count('Pull complete'),
            'layers_already_present': result.stdout.count('Already exists'), 'image_bytes': actual['Size']}

def validate_claims(claims, sha, run_id):
    expected = {'repository': REPO, 'repository_owner_id': '251781345',
                'ref': 'refs/heads/main', 'sha': sha, 'run_id': str(run_id),
                'event_name': 'push', 'workflow_ref': WORKFLOW}
    if any(str(claims.get(k)) != v for k, v in expected.items()):
        raise RuntimeError('OIDC_SCOPE_REJECTED')

def run(*args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)

def write_json(path, value):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, indent=2)+'\n')
    os.chmod(tmp, 0o644)
    tmp.replace(path)

def compose(root, *args):
    return run('docker', 'compose', '--project-name', 'ipphp-'+root.name,
               '--env-file', str(root/'stack.env'), '-f', str(root/'compose.yaml'), *args,
               cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)

def activate(root, receipt):
    write_json(root/'state/deployment.json', receipt)
    # The env file has no credentials. DB passwords are separate file secrets.
    lines=(root/'stack.env').read_text().splitlines()
    lines=[line for line in lines if not line.startswith('APP_IMAGE=')]
    lines.append('APP_IMAGE='+receipt['image_id'])
    (root/'stack.env').write_text('\n'.join(lines)+'\n')
    compose(root, 'up', '-d', '--no-deps', '--force-recreate', 'app')
    if (root/'data/local/onec-read-admission.json').is_file():
        run('/usr/local/sbin/ipphp-onec-network', stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    import time
    error=None
    for _ in range(40):
        try:
            result=compose(root, 'exec', '-T', 'app', 'php', '/opt/app/bin/server-diagnostics.php', '--check')
            data=json.loads(result.stdout)
            if data['commit']!=receipt['commit'] or data['image_id']!=receipt['image_id']:
                raise RuntimeError('RUNNING_VERSION_MISMATCH')
            import urllib.request
            app_ip=next(l.split('=',1)[1] for l in lines if l.startswith('APP_IP='))
            for route in ('/health','/login'):
                with urllib.request.urlopen('http://'+app_ip+route,timeout=5) as response:
                    if response.status!=200: raise RuntimeError('HTTP_NOT_READY')
            return data
        except Exception as exc:
            error=type(exc).__name__;time.sleep(3)
    raise RuntimeError('APP_NOT_READY_'+str(error))

def main():
    if os.geteuid()!=0 or len(sys.argv)!=3 or sys.argv[1] != 'ui':
        raise RuntimeError('INVALID_INVOCATION')
    stand,operation=sys.argv[1:];root=BASE/stand
    if not (root/'state/INITIAL_IMPORT_COMPLETE').is_file():raise RuntimeError('INITIAL_IMPORT_REQUIRED')
    with (root/'state/deploy.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        current_file=root/'state/current.json'
        current=json.loads(current_file.read_text()) if current_file.exists() else None
        if operation=='rollback':
            previous=json.loads((root/'state/previous.json').read_text())
            previous['deployed_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
            previous['operation']='rollback-image-only'
            result=activate(root,previous)
            if current:write_json(root/'state/previous.json',current)
            write_json(current_file,previous);print(json.dumps(result));return
        if operation!='release':raise RuntimeError('INVALID_OPERATION')
        line=sys.stdin.buffer.readline(32769)
        if len(line)>32768:raise RuntimeError('HEADER_TOO_LARGE')
        header=json.loads(line)
        sha=header['commit'];run_id=str(header['run_id']);image=header['image_id']
        if not re.fullmatch(r'[a-f0-9]{40}',sha) or not re.fullmatch(r'[0-9]{1,20}',run_id) \
            or not re.fullmatch(r'sha256:[a-f0-9]{64}',image):
            raise RuntimeError('INVALID_RELEASE_HEADER')
        transport=header.get('transport', 'archive')
        if transport == 'registry':
            validate_registry_header(header)
        elif transport == 'archive':
            archive_sha=header['archive_sha256']
            if not re.fullmatch(r'[a-f0-9]{64}',archive_sha):raise RuntimeError('INVALID_RELEASE_HEADER')
        else:raise RuntimeError('INVALID_TRANSPORT')
        token=header.pop('oidc')
        signing_key=jwt.PyJWKClient('https://token.actions.githubusercontent.com/.well-known/jwks').get_signing_key_from_jwt(token)
        claims=jwt.decode(token,signing_key.key,algorithms=['RS256'],audience='ipphp-deploy',
                          issuer='https://token.actions.githubusercontent.com')
        validate_claims(claims, sha, run_id)
        highest_file=root/'state/highest-run.json'
        highest=json.loads(highest_file.read_text()) if highest_file.exists() else {'run_id':'0','commit':''}
        if int(run_id)<int(highest['run_id']) or (run_id==highest['run_id'] and sha!=highest['commit']):
            raise RuntimeError('STALE_RELEASE_REJECTED')
        incoming=root/'state'/('incoming-'+run_id+'.tar.gz')
        digest=hashlib.sha256();size=0
        try:
            if transport == 'registry':
                delivery=pull_registry_image(header)
            else:
                # Compatibility for an already-started old main workflow; new CI never uses this path.
                with incoming.open('wb') as stream:
                    while chunk:=sys.stdin.buffer.read(1024*1024):
                        size+=len(chunk)
                        if size>1073741824:raise RuntimeError('ARCHIVE_TOO_LARGE')
                        digest.update(chunk);stream.write(chunk)
                if digest.hexdigest()!=archive_sha:raise RuntimeError('ARCHIVE_HASH_MISMATCH')
                with gzip.open(incoming,'rb') as source:
                    proc=subprocess.Popen(['docker','load','--quiet'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                    shutil.copyfileobj(source,proc.stdin);proc.stdin.close();proc.stdin=None
                    out,err=proc.communicate()
                    if proc.returncode:raise RuntimeError('IMAGE_LOAD_FAILED')
                delivery={'transport': 'archive', 'archive_bytes': size}
            actual=json.loads(run('docker','image','inspect',image,stdout=subprocess.PIPE).stdout)[0]
            if actual['Id']!=image or actual['Config']['Labels'].get('org.opencontainers.image.revision')!=sha:
                raise RuntimeError('IMAGE_REVISION_MISMATCH')
            write_json(highest_file,{'run_id':run_id,'commit':sha})
            public_header={k:header[k] for k in ('commit','run_id','image_id','registry_ref','archive_sha256') if k in header}
            receipt={**public_header,'delivery':delivery,'stand':stand,'deployed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
                     'operation':'deploy','run_url':f'https://github.com/{REPO}/actions/runs/{run_id}'}
            try:result=activate(root,receipt)
            except Exception:
                if current:activate(root,current)
                raise
            if current:write_json(root/'state/previous.json',current)
            write_json(current_file,receipt)
            write_json(root/'state'/('release-'+run_id+'.json'),receipt)
            print(json.dumps({**result,'delivery':delivery}))
        finally:incoming.unlink(missing_ok=True)

try:main()
except Exception as exc:
    # Tokens, SQL, credentials and arbitrary remote output are never logged.
    print('DEPLOY_FAILED: '+str(exc) if isinstance(exc,RuntimeError) else 'DEPLOY_FAILED: '+type(exc).__name__,file=sys.stderr)
    sys.exit(1)

