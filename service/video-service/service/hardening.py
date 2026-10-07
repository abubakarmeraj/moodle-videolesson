# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Bounded ASGI ingress and shared fail-closed rate limiting; never log request URLs."""
import asyncio
import hashlib
import json
import os
import re
import time
from functools import lru_cache
import redis
from starlette.concurrency import run_in_threadpool
from . import config
from .security import Rejected

@lru_cache(maxsize=1)
def redis_client():
    return redis.Redis.from_url(config.REDIS_URL, socket_connect_timeout=2, socket_timeout=2,
        max_connections=32, decode_responses=True)

LUA = "local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],120) end; return n"

def rate(group, subject, default):
    limit = int(os.environ.get('RATE_' + group.upper(), str(default)))
    if not 1 <= limit <= 1000000:
        raise Rejected('rate_configuration',503)
    identity = hashlib.sha256(str(subject).encode()).hexdigest()
    key = f'{config.REDIS_NAMESPACE}:rate:{group}:{identity}:{int(time.time())//60}'
    try:
        count = redis_client().eval(LUA, 1, key)
    except redis.RedisError:
        raise Rejected('rate_store_unavailable',503)
    if count > limit:
        raise Rejected('rate_limited',429)

class Ingress:
    """No forwarded-header trust. Proxy must preserve an explicitly allowed Host.

    Per-process active requests are capped; Redis quotas are cross-process.
    API control bodies are separately limited/timed before authentication.
    """
    def __init__(self, app, role):
        self.app, self.role, self.active = app, role, 0
        self.limit = int(os.environ.get('MAX_ACTIVE_REQUESTS', '64' if role=='api' else '128'))

    async def __call__(self, scope, receive, send):
        if scope['type'] != 'http':
            return await self.app(scope, receive, send)
        started = False
        async def safe_send(message):
            nonlocal started
            if message['type']=='http.response.start':
                started = True
                headers = list(message.get('headers',[]))
                present = {k.lower() for k,v in headers}
                for k,v in [(b'cache-control',b'no-store'),(b'x-content-type-options',b'nosniff'),(b'referrer-policy',b'no-referrer')]:
                    if k not in present: headers.append((k,v))
                message = {**message,'headers':headers}
            await send(message)
        async def error(code, status):
            if started: return
            body=json.dumps({'ok':False,'error':code}).encode()
            headers=[(b'content-type',b'application/json'),(b'content-length',str(len(body)).encode())]
            if status in (429,503): headers.append((b'retry-after',b'60' if status==429 else b'5'))
            await safe_send({'type':'http.response.start','status':status,'headers':headers})
            await send({'type':'http.response.body','body':body})
        acquired = False
        try:
            hosts=[v.decode('latin1') for k,v in scope['headers'] if k.lower()==b'host']
            if len(hosts)!=1 or not re.fullmatch(r'[A-Za-z0-9.-]+(?::[0-9]{1,5})?',hosts[0]) or hosts[0].split(':')[0].lower() not in config.ALLOWED_HOSTS or (':' in hosts[0] and not 1<=int(hosts[0].split(':')[1])<=65535):
                raise Rejected('host_denied',400)
            if len(scope.get('query_string',b''))>4096 or sum(len(k)+len(v) for k,v in scope['headers'])>16384:
                raise Rejected('header_limit',431)
            if self.active >= self.limit: raise Rejected('busy',503)
            self.active += 1; acquired=True
            if scope['path'] not in ('/health','/live'):
                # Abuse bound, not authentication. Forwarded IPs cannot create fresh buckets.
                peer=(scope.get('client') or ('unknown',0))[0]
                await run_in_threadpool(rate,'ingress_'+self.role,peer,6000 if self.role=='gateway' else 1200)
            scope={**scope,'headers':[(k,v) for k,v in scope['headers'] if k.lower() not in (b'forwarded',b'x-forwarded-for',b'x-forwarded-host',b'x-forwarded-proto')]}
            await self.app(scope,receive,safe_send)
        except Rejected as e:
            await error(e.code,e.status)
        except Exception:
            # No exception repr, tokens, URLs or headers in either response or application log.
            await error('temporarily_unavailable',503)
        finally:
            if acquired: self.active-=1
