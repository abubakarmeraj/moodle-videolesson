# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Explicit local janitor. Only assigned development objects; referenced/held outputs fail closed."""
import json
import time
from botocore.exceptions import ClientError
from . import config,storage,accounting,storage_admin
from .db import transaction,one
from .security import identifier

def run(clock=None,video=None):
    if video is not None: identifier(video)
    now=int(time.time()) if clock is None else int(clock)
    result={'raw_expired':0,'deleted':0,'uploads_aborted':0}
    with transaction() as c:
        c.execute("SELECT id FROM upload_session WHERE state IN ('creating','uploading') AND expires<=%s"+
            (" AND video=%s" if video else "")+" LIMIT 50",(now,video) if video else (now,))
        ids=[r['id'] for r in c.fetchall()]
    for uid in ids:
        with transaction() as c:
            u=one(c,'SELECT * FROM upload_session WHERE id=%s FOR UPDATE',(uid,))
            if u['state'] not in ('creating','uploading'): continue
            # Also recover create-multipart committed/response-lost intents. No arbitrary prefix deletion.
            candidates=set([u['multipart_id']] if u['multipart_id'] else [])
            for page in storage.client().get_paginator('list_multipart_uploads').paginate(Bucket=config.RAW,Prefix=u['object_key']):
                candidates.update(x['UploadId'] for x in page.get('Uploads',[]) if x['Key']==u['object_key'])
            for mid in candidates:
                try: storage.client().abort_multipart_upload(Bucket=config.RAW,Key=u['object_key'],UploadId=mid)
                except ClientError as e:
                    if e.response['Error']['Code']!='NoSuchUpload': raise
            # Object may exist if complete response was lost before durable service completion.
            storage_admin.delete_verified(c,'raw',u['object_key'])
            c.execute('DELETE FROM storage_reservation WHERE upload_id=%s',(uid,))
            c.execute("UPDATE upload_session SET state='aborted' WHERE id=%s",(uid,))
            c.execute("UPDATE video SET state='upload-aborted' WHERE uuid=%s AND state='uploading'",(u['video'],))
            result['uploads_aborted']+=1
    with transaction() as c:
        c.execute("SELECT uuid FROM video WHERE hold_flag=0 AND (state IN ('recycle','deletion-pending','cleaning') OR (raw_state='retained' AND raw_expires<=%s AND state IN ('ready','failed','archived')))"+
            (" AND uuid=%s" if video else "")+" LIMIT 50",(now,video) if video else (now,))
        ids=[r['uuid'] for r in c.fetchall()]
    for vid in ids:
        with transaction() as c:
            v=one(c,'SELECT * FROM video WHERE uuid=%s FOR UPDATE',(vid,))
            if v['hold_flag']: continue
            if v['raw_key'] and v['raw_state']=='retained' and v['raw_expires']<=now and v['state']!='cleaning':
                storage_admin.delete_verified(c,'raw',v['raw_key'])
                c.execute("UPDATE video SET raw_state='expired' WHERE uuid=%s",(vid,));result['raw_expired']+=1
        try:
            if storage_admin.cleanup(vid,clock=now)=='deleted':result['deleted']+=1
        except Exception:
            # Cleaning state survives and bytes remain counted conservatively until verified on retry.
            result['cleanup_retry']=result.get('cleanup_retry',0)+1
    return result

if __name__=='__main__':
    import argparse
    parser=argparse.ArgumentParser(description='Apply retention/deletion only to service-owned development objects')
    parser.add_argument('--video',help='Restrict this pass to one service UUID')
    print(json.dumps(run(video=parser.parse_args().video)))
