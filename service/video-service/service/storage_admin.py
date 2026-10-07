# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Bounded service-known video-prefix reconciliation and verified idempotent cleanup."""
import argparse,json,time
from botocore.exceptions import ClientError
from . import accounting,config,storage
from .db import one,transaction
from .security import identifier,Rejected

def absent(client,bucket,key):
    try:client.head_object(Bucket=bucket,Key=key);return False
    except ClientError as e:
        if e.response['ResponseMetadata']['HTTPStatusCode']==404:return True
        raise

def delete_verified(c,kind,key):
    client=storage.client();bucket=config.RAW if kind=='raw' else config.PROCESSED
    client.delete_object(Bucket=bucket,Key=key)
    if not absent(client,bucket,key):raise Rejected('cleanup_unconfirmed',503)
    accounting.forget(c,kind,key)

def busy(c,vid):
    return one(c,"SELECT id FROM job WHERE video=%s AND state IN ('queued','processing') LIMIT 1",(vid,)) or one(c,
               "SELECT id FROM upload_session WHERE video=%s AND state IN ('creating','uploading') LIMIT 1",(vid,))

def cleanup(vid,clock=None,dry=False):
    identifier(vid);now=int(time.time()) if clock is None else clock
    with transaction() as c:
        v=one(c,'SELECT * FROM video WHERE uuid=%s AND tenant=%s FOR UPDATE',(vid,config.TENANT))
        if not v or v['refs']!='[]' or not v['refgeneration'] or v['hold_flag'] or busy(c,vid):return 'protected'
        if not (v['state'] in ('deletion-pending','cleaning') or
                (v['state']=='recycle' and v['delete_after']<=now and not v['keep_flag'])):return 'not_due'
        if dry:return 'eligible'
        # Commit irreversible boundary BEFORE any object deletion. Restore/reuse now fail closed.
        c.execute("UPDATE video SET state='cleaning',keep_flag=0 WHERE uuid=%s",(vid,))
    with transaction() as c:
        v=one(c,'SELECT * FROM video WHERE uuid=%s AND tenant=%s FOR UPDATE',(vid,config.TENANT))
        if v['state']!='cleaning' or v['refs']!='[]' or v['hold_flag'] or busy(c,vid):return 'protected'
        client=storage.client();prefix=f'{config.TENANT}/{vid}/'
        for kind,bucket in [('raw',config.RAW),('processed',config.PROCESSED)]:
            # At most500 deletes per bucket/pass. Repeat starts at remaining first page.
            page=client.list_objects_v2(Bucket=bucket,Prefix=prefix,MaxKeys=500)
            for obj in page.get('Contents',[]):
                accounting.record(c,vid,kind,obj['Key'],obj['Size'])
                delete_verified(c,kind,obj['Key'])
            if client.list_objects_v2(Bucket=bucket,Prefix=prefix,MaxKeys=1).get('Contents'):return 'pending'
        # Empty listings plus individual HEAD checks reconcile conservative records after lost replies.
        c.execute('SELECT bucket_kind,object_key FROM storage_object WHERE tenant=%s AND video=%s LIMIT 1001',(config.TENANT,vid))
        remaining=c.fetchall()
        for obj in remaining[:1000]:
            bucket=config.RAW if obj['bucket_kind']=='raw' else config.PROCESSED
            if not absent(client,bucket,obj['object_key']):return 'pending'
            accounting.forget(c,obj['bucket_kind'],obj['object_key'])
        if len(remaining)>1000:return 'pending'
        c.execute("UPDATE video SET state='deleted',raw_state='expired',storage_checked=%s WHERE uuid=%s",(now,vid))
        return 'deleted'

def reconcile(vid,apply=False):
    identifier(vid)
    with transaction() as c:
        v=one(c,'SELECT * FROM video WHERE uuid=%s AND tenant=%s FOR UPDATE',(vid,config.TENANT))
        if not v:raise Rejected('video_denied',403)
        if one(c,"SELECT id FROM job WHERE video=%s AND state IN ('queued','processing') LIMIT 1",(vid,)) or v['state']=='cleaning':
            return {'state':'busy'}
        client=storage.client();actual={};prefix=f'{config.TENANT}/{vid}/'
        for kind,bucket in [('raw',config.RAW),('processed',config.PROCESSED)]:
            # Long-form ladders exceed20000 segments; retain a bounded fail-closed inventory.
            for page in client.get_paginator('list_objects_v2').paginate(Bucket=bucket,Prefix=prefix,
                    PaginationConfig={'MaxItems':200001}):
                for obj in page.get('Contents',[]):actual[(kind,obj['Key'])]=obj['Size']
                if len(actual)>200000:raise Rejected('inventory_limit',409)
        c.execute('SELECT * FROM storage_object WHERE tenant=%s AND video=%s',(config.TENANT,vid))
        known={(r['bucket_kind'],r['object_key']):r['bytes'] for r in c.fetchall()}
        missing=set(known)-set(actual)
        for kind,key in missing:
            if not absent(client,config.RAW if kind=='raw' else config.PROCESSED,key):raise Rejected('inventory_changed',409)
        out={'state':'verified','objects':len(actual),'bytes':sum(actual.values()),'orphan_objects':len(set(actual)-set(known)),
             'missing_objects':len(missing),'size_drift':sum(known[k]!=actual[k] for k in set(known)&set(actual)), 'applied':apply}
        if apply:
            for (kind,key),size in actual.items():accounting.record(c,vid,kind,key,size)
            for kind,key in missing:accounting.forget(c,kind,key)
            c.execute("SELECT * FROM upload_session WHERE video=%s AND state IN ('creating','uploading')",(vid,))
            for upload in c.fetchall():
                parts=0
                if upload['multipart_id']:
                    try:parts=sum(p['Size'] for p in storage.list_parts(upload))
                    except ClientError as e:
                        if e.response['Error']['Code']!='NoSuchUpload':raise
                c.execute('UPDATE storage_reservation SET part_bytes=LEAST(bytes,%s) WHERE upload_id=%s',(parts,upload['id']))
            c.execute('UPDATE video SET storage_checked=%s WHERE uuid=%s',(int(time.time()),vid))
        return out

if __name__=='__main__':
    p=argparse.ArgumentParser(description='Known tenant/video storage only; no secrets or object paths in output')
    p.add_argument('action',choices=['status','reconcile','cleanup'])
    p.add_argument('--video');p.add_argument('--apply',action='store_true');p.add_argument('--limit',type=int,default=1)
    args=p.parse_args()
    if args.action=='status':print(json.dumps(accounting.status()))
    else:
        if not 1<=args.limit<=25:p.error('Limit1..25')
        with transaction() as c:
            if args.video:identifier(args.video);ids=[args.video]
            else:
                c.execute('SELECT uuid FROM video WHERE tenant=%s ORDER BY storage_checked,uuid LIMIT %s',(config.TENANT,args.limit))
                ids=[r['uuid'] for r in c.fetchall()]
        for vid in ids:
            result=reconcile(vid,args.apply) if args.action=='reconcile' else cleanup(vid,dry=not args.apply)
            print(json.dumps({'video':vid,'result':result}))
