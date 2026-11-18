"""Real HTTP save/conflict/validation/permissions scenario on an isolated local copy."""
import argparse
import json
from html.parser import HTMLParser
from urllib.parse import urlparse
import requests

class Form(HTMLParser):
    def __init__(self, html):
        super().__init__(); self.fields = {}; self.textarea = None; self.editor = False
        self.feed(html)
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == 'form' and a.get('id') == 'template-editor-form': self.editor = True
        if tag == 'input' and a.get('name') and (self.editor or a['name'] == '_csrf'):
            self.fields[a['name']] = a.get('value', '')
        if tag == 'textarea' and self.editor:
            self.textarea = a['name']; self.fields[self.textarea] = ''
        if tag == 'option' and self.editor and 'selected' in a: self.fields['format'] = a['value']
    def handle_data(self, text):
        if self.textarea: self.fields[self.textarea] += text.replace('\r\n','\n').replace('\r','\n')
    def handle_endtag(self, tag):
        if tag == 'textarea': self.textarea = None
        if tag == 'form': self.editor = False

parser = argparse.ArgumentParser()
parser.add_argument('--base', required=True); parser.add_argument('--accounts', required=True)
args = parser.parse_args(); base = args.base.rstrip('/')
assert urlparse(base).hostname in ('127.0.0.1', 'localhost'), 'Mutating tests require isolated loopback URL'
accounts = json.load(open(args.accounts, encoding='utf-8'))['accounts']
def login(role):
    session = requests.Session(); session.trust_env = False
    response = session.get(base+'/login', timeout=20)
    actor = next(x for x in accounts if x['role'] == role)
    response = session.post(base+'/login', data={'_csrf':Form(response.text).fields['_csrf'],
        'username':actor['username'], 'password':actor['password']}, timeout=30)
    assert response.status_code == 200 and '/login' not in response.url, 'Login failed'
    return session

admin = login('admin'); manager = login('manager')
anon = requests.Session(); anon.trust_env = False
for channel, key in [('max','submit'),('email','invoice'),('top','task-received')]:
    path = '/administration/templates-'+channel
    assert anon.get(base+path, allow_redirects=False).status_code == 303
    assert manager.get(base+path).status_code == 403
    response = admin.get(base+path+'?edit='+key); assert response.status_code == 200
    original = Form(response.text).fields
    assert original['source_key'] == key and original['body']
    current = dict(original); changed = False
    try:
        invalid = dict(current, body='Сохранить черновик {unknown_field}')
        response = admin.post(base+'/integrations/catalog-save', data=invalid)
        assert response.status_code == 422 and '{unknown_field}' in response.text
        forbidden = dict(current, _csrf='invalid')
        assert admin.post(base+'/integrations/catalog-save', data=forbidden).status_code == 403
        forbidden['_csrf'] = Form(manager.get(base+'/profile').text).fields['_csrf']
        assert manager.post(base+'/integrations/catalog-save', data=forbidden).status_code == 403
        response = admin.post(base+'/integrations/catalog-save', data=dict(current, body=current['body'].replace('\r\n','\n').replace('\n','\r\n')))
        assert response.status_code == 200
        same = Form(admin.get(base+path+'?edit='+key).text).fields
        assert same['expected_version'] == current['expected_version'], 'No-op changed revision'
        updated = dict(current, body=current['body']+'\nПроверка редактора.')
        response = admin.post(base+'/integrations/catalog-save', data=updated)
        assert response.status_code == 200; changed = True
        current = Form(admin.get(base+path+'?edit='+key).text).fields
        assert current['body'] == updated['body']
        assert int(current['expected_version']) == int(original['expected_version'])+1
        response = admin.post(base+'/integrations/catalog-save', data=updated)
        assert response.status_code == 409 and 'Проверка редактора.' in response.text
        after = Form(admin.get(base+path+'?edit='+key).text).fields
        assert after['expected_version'] == current['expected_version'], 'Duplicate save'
        assert 'История изменений' in admin.get(base+path+'?edit='+key).text
        assert admin.get(base+path+'?q=NOT_FOUND_859372').text.count('Шаблоны не найдены.') == 1
    finally:
        if changed:
            restored = dict(original, expected_version=current['expected_version'])
            response = admin.post(base+'/integrations/catalog-save', data=restored)
            assert response.status_code == 200, 'Original template restore failed'
    print(channel+': PASS save, reopen, history, no-op, duplicate conflict, draft, invalid token, CSRF, role, search')
assert admin.get(base+'/integrations/catalog-save').status_code == 405
print('PASS all three isolated template administration scenarios')
