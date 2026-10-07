# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Operator-only bounded status; no user identities, secrets or object/grant URLs."""
import json
import shutil
import tempfile
import time
from .db import transaction
from .hardening import redis_client
from . import config

def status():
    with transaction() as c:
        c.execute('SELECT state,COUNT(*) AS count,MIN(created) AS oldest FROM job GROUP BY state')
        jobs=c.fetchall()
        c.execute("SELECT COUNT(*) AS count FROM job WHERE state='processing' AND lease_until<%s",(int(time.time()),))
        expired=c.fetchone()['count']
    return {'version':config.VERSION,'database':True,'redis':bool(redis_client().ping()),
        'jobs':jobs,'expired_leases':expired,'temp_free_bytes':shutil.disk_usage(tempfile.gettempdir()).free,
        'stream':config.STREAM,'group':config.GROUP}

if __name__=='__main__':
    try: print(json.dumps(status()))
    except Exception:
        print(json.dumps({'ready':False,'error':'dependency_unavailable'}));raise SystemExit(1)
