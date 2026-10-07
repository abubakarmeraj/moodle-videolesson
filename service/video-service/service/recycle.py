# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Reference-serialized lifecycle. Permanent cleanup never trusts browser counts/prefixes."""
import json,time
from . import config,accounting
from .db import one,transaction
from .security import Rejected,identifier

def sync(c,v,refs,generation,days,title=''):
    if type(days) is not int or days not in accounting.RECOVERY:raise Rejected('invalid_storage_policy',400)
    if not isinstance(title,str) or len(title)>200:raise Rejected('invalid_title',400)
    if refs and v['state'] in ('archived','deletion-pending','cleaning','deleted'):raise Rejected('media_unavailable',409)
    state=v['state'];at=v['recycle_at'];deadline=v['delete_after'];keep=v['keep_flag']
    if refs:
        if state=='recycle':state='ready'
        at=deadline=keep=0
    elif state=='ready' and not keep:
        state='recycle';at=int(time.time());deadline=at+days*86400
    c.execute('UPDATE video SET refs=%s,refgeneration=%s,state=%s,recycle_at=%s,delete_after=%s,keep_flag=%s,title=IF(%s=\'\',title,%s),modified=%s WHERE uuid=%s',
              (json.dumps(refs),generation,state,at,deadline,keep,title,title,int(time.time()),v['uuid']))

def admin(op,vid,generation):
    identifier(vid)
    with transaction() as c:
        v=one(c,'SELECT * FROM video WHERE uuid=%s AND tenant=%s FOR UPDATE',(vid,config.TENANT))
        if not v:raise Rejected('video_denied',403)
        if type(generation) is not int or generation<1 or generation!=v['refgeneration']:raise Rejected('stale_reference',409)
        if json.loads(v['refs']) or v['hold_flag']:raise Rejected('media_referenced_or_held',409)
        if one(c,"SELECT id FROM job WHERE video=%s AND state IN ('queued','processing')",(vid,)):
            raise Rejected('operation_active',409)
        if op=='recycle_restore':
            if v['state']=='ready' and v['keep_flag']:return {'state':'ready'}
            if v['state']!='recycle' or v['delete_after']<int(time.time()):raise Rejected('recovery_unavailable',409)
            # Durable explicit keep protects the interval before a new Moodle activity commits.
            c.execute("UPDATE video SET state='ready',keep_flag=1,delete_after=0 WHERE uuid=%s",(vid,))
            return {'state':'ready'}
        if v['state']=='deleted':return {'state':'deleted'}
        if v['state'] not in ('ready','recycle','deletion-pending','cleaning'):raise Rejected('cleanup_unavailable',409)
        if v['state']!='cleaning':
            c.execute("UPDATE video SET state='deletion-pending',keep_flag=0 WHERE uuid=%s",(vid,))
        return {'state':'deletion-pending'}
