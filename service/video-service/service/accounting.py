# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Conservative object ledger and serialized tenant upload reservations. No browser byte authority."""
import time
from . import config
from .db import transaction,one
from .security import Rejected,identifier

MAX_QUOTA=100*1024**4
RECOVERY=(0,1,3,7,14,30)

def policy(c,lock=False):
    if not lock:
        existing=one(c,'SELECT * FROM storage_policy WHERE tenant=%s',(config.TENANT,))
        if existing:return existing
    # INSERT IGNORE takes a shared duplicate-key lock, which deadlocks when
    # concurrent admissions both promote it to FOR UPDATE. Acquire X directly.
    c.execute('INSERT INTO storage_policy(tenant) VALUES(%s) ON DUPLICATE KEY UPDATE tenant=VALUES(tenant)',(config.TENANT,))
    return one(c,'SELECT * FROM storage_policy WHERE tenant=%s'+(' FOR UPDATE' if lock else ''),(config.TENANT,))

def configure(quota,days):
    if type(quota) is not int or not 0<=quota<=MAX_QUOTA or type(days) is not int or days not in RECOVERY:
        raise Rejected('invalid_storage_policy',400)
    with transaction() as c:
        policy(c,True)
        c.execute('UPDATE storage_policy SET quota_bytes=%s,recovery_days=%s WHERE tenant=%s',(quota,days,config.TENANT))
    return {'configured':True}

def totals(c):
    c.execute("SELECT o.bucket_kind,v.state,SUM(o.bytes) bytes FROM storage_object o JOIN video v ON v.uuid=o.video "
              "WHERE o.tenant=%s GROUP BY o.bucket_kind,v.state",(config.TENANT,))
    active=recycle=raw=0
    for r in c.fetchall():
        if r['bucket_kind']=='raw':raw+=int(r['bytes'])
        elif r['state'] in ('recycle','deletion-pending','cleaning'):recycle+=int(r['bytes'])
        else:active+=int(r['bytes'])
    r=one(c,'SELECT COALESCE(SUM(bytes),0) bytes,COALESCE(SUM(part_bytes),0) parts FROM storage_reservation WHERE tenant=%s',(config.TENANT,))
    raw+=int(r['parts']);reserved=int(r['bytes'])-int(r['parts'])
    reclaim=one(c,"SELECT COALESCE(SUM(o.bytes),0) bytes FROM storage_object o JOIN video v ON v.uuid=o.video "
                "WHERE o.tenant=%s AND v.state IN ('recycle','deletion-pending','cleaning') AND v.refs='[]' AND v.hold_flag=0",(config.TENANT,))
    unchecked=one(c,'SELECT COUNT(*) n FROM video WHERE tenant=%s AND storage_checked=0',(config.TENANT,))['n']
    return dict(active_processed_bytes=active,recycle_processed_bytes=recycle,raw_bytes=raw,
                reserved_upload_bytes=reserved,total_physical_bytes=active+recycle+raw,
                reclaimable_bytes=int(reclaim['bytes']),unchecked_videos=unchecked)

def reserve(c,upload,size):
    # Caller acquired policy row before video row; all admissions serialize on that row.
    p=policy(c,True);t=totals(c)
    if p['quota_bytes'] and t['unchecked_videos']:raise Rejected('storage_reconciliation_required',409)
    if p['quota_bytes'] and t['total_physical_bytes']+t['reserved_upload_bytes']+size>p['quota_bytes']:
        raise Rejected('storage_full',409)
    c.execute('INSERT INTO storage_reservation(upload_id,tenant,bytes) VALUES(%s,%s,%s)',(upload,config.TENANT,size))

def record(c,video,kind,key,size,confirmed=1):
    identifier(video)
    if kind not in ('raw','processed') or not key.startswith(f'{config.TENANT}/{video}/') or len(key)>255 or size<0:
        raise Rejected('object_scope',400)
    c.execute('INSERT INTO storage_object VALUES(%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE bytes=VALUES(bytes),confirmed=VALUES(confirmed)',
              (config.TENANT,video,kind,key,size,confirmed))

def forget(c,kind,key):
    c.execute('DELETE FROM storage_object WHERE tenant=%s AND bucket_kind=%s AND object_key=%s',(config.TENANT,kind,key))

def status(after='',kept_only=False):
    if after:identifier(after)
    if type(kept_only) is not bool:raise Rejected('invalid_filter',400)
    with transaction() as c:
        p=policy(c);t=totals(c)
        condition="v.state='ready' AND v.keep_flag=1" if kept_only else "(v.recycle_at>0 OR v.keep_flag=1 OR v.state IN ('deletion-pending','cleaning'))"
        c.execute("SELECT v.uuid,v.title,v.state,v.recycle_at,v.delete_after,v.hold_flag,v.keep_flag,v.refs,"
                  "COALESCE(o.bytes,0) bytes FROM video v LEFT JOIN "
                  "(SELECT tenant,video,SUM(bytes) bytes FROM storage_object GROUP BY tenant,video) o ON o.video=v.uuid AND o.tenant=v.tenant "
                  "WHERE v.tenant=%s AND v.uuid>%s AND "+condition+" "
                  "ORDER BY v.uuid LIMIT 51",(config.TENANT,after))
        rows=c.fetchall()
        import json
        for row in rows:row['references']=len(json.loads(row.pop('refs')));row['bytes']=int(row['bytes'])
        return dict(t,quota_bytes=p['quota_bytes'],recovery_days=p['recovery_days'],
                    available_bytes=max(0,p['quota_bytes']-t['total_physical_bytes']-t['reserved_upload_bytes']) if p['quota_bytes'] else None,
                    videos=rows[:50],next=rows[49]['uuid'] if len(rows)>50 else '')
