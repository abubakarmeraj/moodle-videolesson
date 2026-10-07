# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Authenticated setup protocol. Only sanitized, bounded readiness data leaves service."""
import json
from . import config, storage
from .db import transaction
from .hardening import redis_client

PROTOCOL_VERSION = 1
EXPECTED_SCHEMA = [1, 2, 3, 4, 5]

def describe():
    schema = []
    try:
        with transaction() as c:
            c.execute('SELECT version FROM schema_version ORDER BY version')
            schema = [int(row['version']) for row in c.fetchall()]
    except Exception:
        pass
    redis_ready, worker_ready = False, False
    try:
        r = redis_client()
        redis_ready = bool(r.ping())
        raw = r.get(config.REDIS_NAMESPACE + ':staging')
        if raw:
            worker = json.loads(raw)
            worker_ready = (type(worker.get('free')) is int and worker['free'] >= 512 * 1024**2 and
                            type(worker.get('maxbytes')) is int and worker['maxbytes'] > 0 and
                            type(worker.get('duration')) is int and worker['duration'] > 0)
    except Exception:
        pass
    buckets = storage.readiness()
    return {'protocol_version': PROTOCOL_VERSION, 'service_version': config.VERSION,
            'schema_ready': schema == EXPECTED_SCHEMA, 'schema_versions': schema,
            'storage_backend': config.STORAGE_BACKEND, 'storage_config_valid': True,
            **buckets, 'redis_ready': redis_ready, 'worker_capacity_ready': worker_ready,
            'max_duration_seconds': config.MAX_DURATION, 'max_source_bytes': config.MAX_SIZE}
