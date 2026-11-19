#!/usr/bin/env python3
"""Refresh the admitted MES session through its assigned IMAPS inbox."""

import datetime as dt
from email import policy
from email.parser import BytesParser
from email.utils import getaddresses, parsedate_to_datetime
from html.parser import HTMLParser
import imaplib
import json
import os
from pathlib import Path
import re
import ssl
import subprocess
import time
import uuid

HOST = 'mail.dltkmsk.ru'
SENDER = 'mosenergosbyt.ru'
MAIL_UID = 995
MAX_MESSAGE = 262144
HEADER_FIELDS = ('From', 'To', 'Date', 'Subject')
SUBJECT_WORDS = ('код', 'провер', 'подтверж', 'вход', 'login', 'code', 'verification')
AUTH_CLI = ('docker', 'exec', '-i', '--env',
            'IPPHP_CABINET_OWNER_AUTH=approved-interactive-restore', '--user', 'www-data',
            '--workdir', '/opt/app', 'ipphp-ui-app-1', 'php', 'bin/mes-cabinet-auth.php')


class RefreshError(Exception):
    pass


class PlainHtml(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.text = []
        self.hidden = 0

    def handle_starttag(self, tag, _attrs):
        if tag in ('style', 'script'):
            self.hidden += 1
        elif not self.hidden and tag not in ('span', 'strong', 'b', 'i', 'em'):
            self.text.append(' ')

    def handle_endtag(self, tag):
        if tag in ('style', 'script'):
            self.hidden = max(0, self.hidden-1)
        elif not self.hidden and tag not in ('span', 'strong', 'b', 'i', 'em'):
            self.text.append(' ')

    def handle_data(self, data):
        if not self.hidden:
            self.text.append(data)


def matches(message, address, requested):
    if any(len(message.get_all(field, ())) != 1 or message[field].defects
           for field in HEADER_FIELDS):
        return False
    senders = getaddresses([str(message['From'])])
    addresses = getaddresses([str(message['To'])])
    domain = senders[0][1].casefold().rpartition('@')[2] if len(senders) == 1 else ''
    if domain != SENDER and not domain.endswith('.'+SENDER):
        return False
    if not any(recipient.casefold() == address for _, recipient in addresses):
        return False
    if not any(word in str(message['Subject']).casefold() for word in SUBJECT_WORDS):
        return False
    try:
        sent = parsedate_to_datetime(str(message['Date']))
        return in_window(sent, requested)
    except (TypeError, ValueError, OverflowError):
        return False


def in_window(timestamp, requested):
    now = dt.datetime.now(dt.timezone.utc)
    return (timestamp is not None and timestamp.tzinfo is not None
            and requested-dt.timedelta(seconds=5) <= timestamp <= now+dt.timedelta(seconds=120))


def codes(message):
    result = set()
    for index, part in enumerate(message.walk()):
        if index >= 64 or part.defects:
            raise RefreshError('MES_MAIL_MESSAGE_INVALID')
        kind = part.get_content_type()
        if part.get_filename() or part.get_content_disposition() == 'attachment' or kind not in ('text/plain', 'text/html'):
            continue
        source = part.get_content(errors='strict')
        if kind == 'text/html':
            parser = PlainHtml()
            parser.feed(source)
            source = ''.join(parser.text)
        result.update(re.findall(r'(?<![0-9])[0-9]{5}(?![0-9])', source))
    return result


def imap(action, *arguments, **options):
    # The server routes only the existing mail service UID to the assigned IMAPS endpoint.
    os.seteuid(MAIL_UID)
    try:
        return action(*arguments, **options)
    finally:
        os.seteuid(0)


def mail_profile():
    path = Path(os.environ['CREDENTIALS_DIRECTORY'])/'mes-mail'
    profile = json.loads(path.read_bytes())
    if set(profile) != {'username', 'password', 'host', 'mailbox'} or profile['host'] != HOST:
        raise RefreshError('MES_MAIL_PROFILE_INVALID')
    if any(not isinstance(value, str) or not value or len(value) > 1024 or
           any(ord(char) < 32 or ord(char) == 127 for char in value) for value in profile.values()):
        raise RefreshError('MES_MAIL_PROFILE_INVALID')
    if not re.fullmatch(r'[A-Za-z0-9._+%=-]+@[A-Za-z0-9.-]+', profile['mailbox']):
        raise RefreshError('MES_MAIL_PROFILE_INVALID')
    return profile


def auth(action, key=None, code=None):
    command = (*AUTH_CLI, action) if key is None else (*AUTH_CLI, action, key)
    result = subprocess.run(command, input=code or b'', capture_output=True, timeout=45, check=False)
    try:
        value = json.loads(result.stdout)
    except (ValueError, UnicodeError):
        raise RefreshError('MES_AUTH_CLI_FAILED') from None
    if not isinstance(value, dict) or not isinstance(value.get('code'), str):
        raise RefreshError('MES_AUTH_CLI_FAILED')
    return result.returncode, value['code']


def watermark(mail):
    status, response = imap(mail.status, 'INBOX', '(UIDVALIDITY UIDNEXT)')
    raw = response[0] if status == 'OK' and len(response) == 1 else None
    if isinstance(raw, bytes):
        validity = re.findall(rb'\bUIDVALIDITY ([1-9][0-9]*)\b', raw)
        next_uid = re.findall(rb'\bUIDNEXT ([1-9][0-9]*)\b', raw)
        if len(validity) == len(next_uid) == 1:
            return int(validity[0]), int(next_uid[0])
    raise RefreshError('MES_MAIL_WATERMARK_FAILED')


def fetch(mail, uid, query):
    status, response = imap(mail.uid, 'FETCH', str(uid), query)
    parts = [item for item in response if isinstance(item, tuple)]
    if status != 'OK' or len(parts) != 1:
        raise RefreshError('MES_MAIL_FETCH_FAILED')
    meta, raw = parts[0]
    size = re.search(rb'\{([0-9]+)\}$', meta)
    identity = re.search(rb'\bUID ([0-9]+)\b', meta)
    if not size or not identity or int(size[1]) != len(raw) or len(raw) > MAX_MESSAGE or int(identity[1]) != uid:
        raise RefreshError('MES_MAIL_FETCH_FAILED')
    flags = imaplib.ParseFlags(meta)
    return meta, flags, BytesParser(policy=policy.default).parsebytes(raw)


def current_code(mail, address, requested, first):
    validity, next_uid = watermark(mail)
    if validity != first[0] or next_uid < first[1]:
        raise RefreshError('MES_MAIL_WATERMARK_CHANGED')
    if next_uid == first[1]:
        return None
    status, response = imap(mail.uid, 'SEARCH', None, 'UID', f'{first[1]}:{next_uid-1}',
                            'FROM', f'"{SENDER}"', 'TO', f'"{address}"')
    if status != 'OK' or len(response) != 1 or not isinstance(response[0], bytes):
        raise RefreshError('MES_MAIL_SEARCH_FAILED')
    found = response[0].split()
    if len(found) > 20 or any(not item.isdigit() or not first[1] <= int(item) < next_uid for item in found):
        raise RefreshError('MES_MAIL_SEARCH_LIMIT')

    candidates = set()
    for item in found:
        uid = int(item)
        meta, flags, message = fetch(mail, uid, '(UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER.FIELDS (FROM TO DATE SUBJECT)])')
        size = re.search(rb'\bRFC822.SIZE ([0-9]+)\b', meta)
        received = re.search(rb'INTERNALDATE "([^"]+)"', meta)
        if not size or not received or int(size[1]) > MAX_MESSAGE or not matches(message, address, requested):
            continue
        internal = dt.datetime.strptime(received[1].decode('ascii'), '%d-%b-%Y %H:%M:%S %z')
        if not in_window(internal, requested):
            continue
        _, body_flags, message = fetch(mail, uid, '(UID FLAGS BODY.PEEK[])')
        if flags != body_flags or not matches(message, address, requested):
            raise RefreshError('MES_MAIL_MESSAGE_CHANGED')
        candidates.update(codes(message))
    if watermark(mail)[0] != validity:
        raise RefreshError('MES_MAIL_WATERMARK_CHANGED')
    if len(candidates) > 1:
        raise RefreshError('MES_MAIL_AMBIGUOUS_CODE')
    return next(iter(candidates), None)


def run():
    profile = mail_profile()
    check = auth('check')
    if check == (0, 'MES_AUTHENTICATED'):
        return 'MES_SESSION_VALID'
    if check != (3, 'AUTH_REQUIRED'):
        raise RefreshError('MES_SESSION_CHECK_FAILED')

    context = ssl.create_default_context()
    mail = imap(imaplib.IMAP4_SSL, HOST, 993, ssl_context=context, timeout=12)
    try:
        imap(mail.login, profile['username'], profile['password'])
        if imap(mail.select, 'INBOX', True)[0] != 'OK':
            raise RefreshError('MES_MAIL_EXAMINE_FAILED')
        key = str(uuid.uuid4())
        started = auth('start', key)
        if started == (0, 'MES_AUTHENTICATED'):
            return 'MES_SESSION_RESTORED'
        if started != (0, 'OTP_REQUIRED'):
            raise RefreshError('MES_LOGIN_FAILED')
        first = watermark(mail)
        requested = dt.datetime.now(dt.timezone.utc)
        if auth('request-code', key) != (0, 'OTP_SENT'):
            raise RefreshError('MES_EMAIL_REQUEST_FAILED')
        deadline = time.monotonic()+105
        while time.monotonic() < deadline:
            code = current_code(mail, profile['mailbox'].casefold(), requested, first)
            if code is not None:
                if auth('confirm', key, code.encode('ascii')+b'\n') != (0, 'MES_AUTHENTICATED'):
                    raise RefreshError('MES_CODE_CONFIRM_FAILED')
                return 'MES_SESSION_RESTORED_BY_EMAIL'
            time.sleep(5)
        raise RefreshError('MES_MAIL_CODE_TIMEOUT')
    finally:
        try:
            imap(mail.logout)
        except (imaplib.IMAP4.error, OSError):
            pass


if __name__ == '__main__':
    try:
        print(run())
    except RefreshError as error:
        print(str(error))
        raise SystemExit(1)
    except (OSError, imaplib.IMAP4.error, subprocess.SubprocessError, KeyError, ValueError, TypeError):
        print('MES_AUTOMATIC_AUTH_FAILED')
        raise SystemExit(1)
