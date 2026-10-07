# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
import base64
import hashlib
import hmac
import json
import re
import time
import uuid
from . import config
from .db import transaction

class Rejected(Exception):
    def __init__(self, code='denied', status=403):
        self.code, self.status = code, status

def digest(value):
    return hashlib.sha256(value.encode() if isinstance(value, str) else value).hexdigest()

def identifier(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-f0-9-]{36}', value):
        raise Rejected('invalid_identifier', 400)
    try:
        if str(uuid.UUID(value)) != value:
            raise ValueError()
    except ValueError:
        raise Rejected('invalid_identifier', 400)
    return value

def authenticate(headers, body):
    keyid, timestamp, nonce, signature = [headers.get('x-cf-' + k, '') for k in ('key', 'time', 'nonce', 'signature')]
    selected=config.AUTH_KEYS.get(keyid)
    if not selected or (selected[1] is not None and selected[1]<=time.time()) or not re.fullmatch(r'[0-9]{10}', timestamp) or abs(time.time()-int(timestamp)) > 60:
        raise Rejected('service_auth')
    if not re.fullmatch(r'[a-f0-9]{64}', nonce) or not re.fullmatch(r'[a-f0-9]{64}', signature):
        raise Rejected('service_auth')
    canonical = '\n'.join(['POST', '/v1/rpc', keyid, timestamp, nonce, digest(body)])
    expected = hmac.new(selected[0].encode(), canonical.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise Rejected('service_auth')
    with transaction() as c:
        c.execute('DELETE FROM auth_nonce WHERE expires < %s', (int(time.time()),))
        try:
            c.execute('INSERT INTO auth_nonce(key_id,nonce,expires) VALUES(%s,%s,%s)', (keyid, nonce, int(time.time())+120))
        except Exception:
            raise Rejected('service_replay')

def grant(video, subject, session):
    now = int(time.time())
    payload = {'aud':'codefortex-media', 'tenant':video['tenant'], 'video':video['uuid'],
        'revision':video['revision'], 'sub':subject, 'session':session, 'exp':now+config.GRANT_TTL, 'iat':now}
    data = base64.urlsafe_b64encode(json.dumps(payload, separators=(',',':')).encode()).rstrip(b'=')
    mac = hmac.new(config.GRANT_KEY.encode(), data, hashlib.sha256).hexdigest()
    return data.decode()+'.'+mac, payload['exp']

def verify_grant(token):
    try:
        data, signature = token.split('.')
        if len(token) > 2048 or not hmac.compare_digest(hmac.new(config.GRANT_KEY.encode(), data.encode(), hashlib.sha256).hexdigest(), signature):
            raise ValueError()
        result = json.loads(base64.urlsafe_b64decode(data+'='*((-len(data))%4)))
        if result['aud'] != 'codefortex-media' or result['tenant'] != config.TENANT or result['exp'] <= time.time():
            raise ValueError()
        identifier(result['video'])
        if not re.fullmatch(r'[a-f0-9]{64}', result['sub']) or not re.fullmatch(r'[a-f0-9]{64}', result['session']):
            raise ValueError()
        return result
    except (ValueError, KeyError, TypeError):
        raise Rejected('grant_denied')
