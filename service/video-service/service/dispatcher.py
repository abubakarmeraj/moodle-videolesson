# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""DB -> Redis outbox. Redis loss causes re-publication, never loss of durable jobs."""
import json
import os
import signal
import time
import redis
from .db import transaction
from . import config

running = True
def stop(*_):
    global running
    running = False

def dispatch():
    client = redis.Redis.from_url(config.REDIS_URL,decode_responses=True,socket_timeout=5,socket_connect_timeout=5)
    try: client.xgroup_create(config.STREAM,config.GROUP,id='0',mkstream=True)
    except redis.ResponseError as e:
        if 'BUSYGROUP' not in str(e): raise
    with transaction() as c:
        current=int(time.time())
        # Expired lease transitions are fenced; completed/failed jobs are never silently requeued.
        c.execute("UPDATE job SET state='queued',available=%s WHERE state='processing' AND lease_until<%s AND attempt<3",(current,current))
        c.execute("UPDATE video v JOIN job j ON j.video=v.uuid SET v.state='queued' WHERE j.state='queued' AND v.state IN ('verifying','processing')")
        c.execute("UPDATE video v JOIN job j ON j.video=v.uuid SET v.state='failed',v.error_code='retry_exhausted',j.state='failed' WHERE j.state='processing' AND j.lease_until<%s AND j.attempt>=3",(current,))
        c.execute("SELECT j.id FROM job j JOIN outbox o ON o.job=j.id WHERE j.state='queued' AND j.available<=%s AND o.published<%s ORDER BY j.created LIMIT 20 FOR UPDATE",(current,current-15))
        jobs=c.fetchall()
        for job in jobs:
            client.xadd(config.STREAM,{'job':job['id']},maxlen=1000,approximate=True)
            c.execute('UPDATE outbox SET published=%s WHERE job=%s',(current,job['id']))
    return len(jobs)

if __name__=='__main__':
    signal.signal(signal.SIGTERM,stop); signal.signal(signal.SIGINT,stop)
    while running:
        try:
            n=dispatch()
            if n: print(json.dumps({'component':'dispatcher','published':n}))
        except Exception:
            print(json.dumps({'component':'dispatcher','error':'transport_unavailable','durable_jobs':'retained'}))
        time.sleep(2)
