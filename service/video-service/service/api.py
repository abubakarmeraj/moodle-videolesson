# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Signed server-only control plane. Never accepts media bodies or arbitrary URLs."""
import hashlib
import json
import math
import os
import re
import time
import uuid
import asyncio
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool
from botocore.exceptions import ClientError
from . import config, storage, capacity
from .db import transaction, one
from .security import Rejected, authenticate, digest, grant, identifier
from .hardening import Ingress, rate, redis_client

app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
app.add_middleware(Ingress, role='api')

def now(): return int(time.time())

def owner_arg(data):
    owner = data.get('owner', '')
    if not re.fullmatch(r'[a-f0-9]{64}', owner): raise Rejected('invalid_owner', 400)
    return owner

def operation_key(data):
    value = data.get('key', '')
    if not isinstance(value, str) or not 8 <= len(value) <= 128: raise Rejected('invalid_operation', 400)
    return digest(value)

def video_row(c, vid, owner=None, lock=False):
    row = one(c, 'SELECT * FROM video WHERE uuid=%s AND tenant=%s' + (' FOR UPDATE' if lock else ''),
        (identifier(vid), config.TENANT))
    if not row or (owner is not None and row['owner'] != owner): raise Rejected('video_denied')
    return row

def public_video(row):
    return {k:row[k] for k in ('uuid','tenant','kind','state','revision','duration','attempt','error_code')}

def policy_arg(data):
    p = data.get('policy', {})
    if set(p) not in ({'profile', 'never_upscale', 'raw_retention_days'},
            {'profile', 'never_upscale', 'raw_retention_days', 'hls_segment_seconds'}) or p['profile'] not in ('economy','standard','full'):
        raise Rejected('invalid_policy', 400)
    if p['never_upscale'] is not True or type(p['raw_retention_days']) is not int or not 1 <= p['raw_retention_days'] <= 90:
        raise Rejected('invalid_policy', 400)
    seconds=p.get('hls_segment_seconds',4)
    if type(seconds) is not int or seconds not in (2,4,6): raise Rejected('invalid_policy',400)
    return dict(p,raw_retention_days=1,hls_segment_seconds=seconds)

def upload_row(c, data, lock=False):
    v = video_row(c, data['uuid'], owner_arg(data), lock)
    u = one(c, 'SELECT * FROM upload_session WHERE id=%s AND video=%s' + (' FOR UPDATE' if lock else ''),
        (identifier(data['upload']), v['uuid']))
    if not u: raise Rejected('upload_denied')
    return v, u

def upload_public(u, parts=None):
    return {'upload':u['id'], 'state':u['state'], 'expires':u['expires'], 'size':u['size'],
        'sha256':u['sha256'], 'partsize':capacity.session_part_size(u),
        'parts': [{'number':p['PartNumber'], 'size':p['Size']} for p in (parts or [])]}

def ensure_multipart(uploadid):
    with transaction() as c:
        u = one(c, 'SELECT * FROM upload_session WHERE id=%s FOR UPDATE', (uploadid,))
        if u['multipart_id']: return u
        # Recover a create-multipart response lost after R2 committed. Keys are unique to durable upload intents.
        candidates = []
        for page in storage.client().get_paginator('list_multipart_uploads').paginate(Bucket=config.RAW, Prefix=u['object_key']):
            candidates += [x for x in page.get('Uploads',[]) if x['Key'] == u['object_key']]
        if candidates:
            candidates.sort(key=lambda x:x['Initiated'])
            mid = candidates[0]['UploadId']
            for extra in candidates[1:]:
                storage.client().abort_multipart_upload(Bucket=config.RAW,Key=u['object_key'],UploadId=extra['UploadId'])
        else:
            mid = storage.client().create_multipart_upload(Bucket=config.RAW, Key=u['object_key'],
                ContentType='application/octet-stream', Metadata={'upload':u['id'],'sha256':u['sha256']})['UploadId']
        c.execute('UPDATE upload_session SET multipart_id=%s,state=%s WHERE id=%s', (mid,'uploading',u['id']))
        u.update(multipart_id=mid, state='uploading')
        return u

def rpc(data):
    if data.get('tenant') != config.TENANT: raise Rejected('tenant_denied')
    from . import accounting,recycle
    op = data.get('op')
    if op == 'capabilities':
        from .capabilities import describe
        return describe()
    if op=='storage_policy':return accounting.configure(data.get('quota_bytes'),data.get('recovery_days'))
    if op=='storage_status':return accounting.status(data.get('after',''),data.get('kept_only',False))
    if op in ('recycle_restore','recycle_delete'):
        return recycle.admin(op,data.get('uuid'),data.get('generation'))
    if op == 'create_video':
        owner, key, kind = owner_arg(data), operation_key(data), data.get('kind')
        if kind not in ('hls','direct'): raise Rejected('invalid_kind',400)
        with transaction() as c:
            c.execute('INSERT IGNORE INTO video(uuid,tenant,owner,create_key,kind,state,refs,created,modified) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s)',
                (str(uuid.uuid4()),config.TENANT,owner,key,kind,'draft','[]',now(),now()))
            v = one(c,'SELECT * FROM video WHERE tenant=%s AND create_key=%s',(config.TENANT,key))
            if v['owner'] != owner or v['kind'] != kind: raise Rejected('idempotency_conflict',409)
            if v['state']=='draft':c.execute('UPDATE video SET storage_checked=%s WHERE uuid=%s',(now(),v['uuid']))
            return public_video(v)
    if op == 'get_video':
        with transaction() as c:
            row = video_row(c,data['uuid'])
            result = public_video(row)
            result['kept']=bool(row['keep_flag'])
            if row['state'] == 'ready':
                media = one(c,'SELECT metadata FROM media_version WHERE video=%s AND revision=%s', (row['uuid'],row['revision']))
                if media:
                    renditions = json.loads(media['metadata']).get('renditions', [])
                    if renditions:
                        rendition = renditions[-1]
                        result.update(width=rendition['width'],height=rendition['height'])
            return result
    if op == 'video_status':
        # Only the trusted Moodle backend may call this. Ownership gates resumable upload details.
        with transaction() as c:
            v = video_row(c, data['uuid'])
            owns = v['owner'] == owner_arg(data)
            u = one(c, 'SELECT * FROM upload_session WHERE video=%s ORDER BY created DESC,id DESC LIMIT 1', (v['uuid'],))
            j = one(c, 'SELECT progress,stage,qualities FROM job WHERE video=%s ORDER BY created DESC,id DESC LIMIT 1', (v['uuid'],))
            state = v['state']
            progress = 100 if state == 'ready' else (int(j['progress']) if j else 0)
            stage = 'finalizing' if state == 'processing' and j and j['stage'] == 'finalizing' else state
            qualities = json.loads(j['qualities']) if j else []
            uploaded = int(u['uploaded_bytes']) if u else 0
            resume = None
            if owns and u and state == 'uploading' and u['state'] == 'uploading' and u['expires'] > now():
                parts = storage.list_parts(u)
                uploaded = sum(p['Size'] for p in parts)
                # R2-confirmed parts, not browser claims, survive a closed/reopened browser.
                if uploaded != u['uploaded_bytes']:
                    c.execute('UPDATE upload_session SET uploaded_bytes=%s WHERE id=%s', (uploaded,u['id']))
                c.execute('UPDATE storage_reservation SET part_bytes=LEAST(bytes,%s) WHERE upload_id=%s',(uploaded,u['id']))
                resume = upload_public(u, parts)
            if state == 'uploading' and u:
                progress = int(70 * uploaded / u['size'])
            if state in ('draft','upload-aborted'): progress = 0
            return {'state':state,'stage':stage,'progress':min(100,max(0,progress)),
                'qualities':qualities,'resume':resume,'can_upload':owns,'maxbytes':config.MAX_SIZE}
    if op in ('start_upload','check_upload'):
        owner, key, policy = owner_arg(data), operation_key(data), policy_arg(data)
        size, sha = data.get('size'), data.get('sha256','')
        maximum = data.get('maxbytes', 512 * 1024 * 1024)
        if type(maximum) is not int or not 1 <= maximum <= 1024**4:
            raise Rejected('invalid_limit',400)
        maximum=min(maximum,config.MAX_SIZE)
        if type(size) is not int or not 1 <= size <= 1024**4 or (op=='start_upload' and not re.fullmatch('[a-f0-9]{64}',sha)):
            raise Rejected('invalid_upload',400)
        if op=='check_upload':
            if size>maximum:raise Rejected('upload_capacity',409)
            with transaction() as c:v=video_row(c,data['uuid'],owner)
            return capacity.check(size,data.get('duration'),policy['profile'],v['kind'])
        with transaction() as c:
            accounting.policy(c,True)
            v = video_row(c,data['uuid'],owner,True)
            u = one(c,'SELECT * FROM upload_session WHERE video=%s AND operation_key=%s',(v['uuid'],key))
            if u:
                if u['size'] != size or u['sha256'] != sha: raise Rejected('idempotency_conflict',409)
                if u['state'] not in ('creating','uploading'): return upload_public(u)
                if u['expires'] <= now(): raise Rejected('upload_expired',409)
            else:
                if size>maximum:raise Rejected('upload_capacity',409)
                duration=data.get('duration')
                capacity.check(size,duration,policy['profile'],v['kind'])
                policy.update(upload_max_bytes=maximum,upload_part_bytes=capacity.part_size(size),
                              source_duration_hint=duration,max_duration_seconds=config.MAX_DURATION,
                              encode_timeout_seconds=config.ENCODE_TIMEOUT)
                if v['state'] not in ('draft','failed','upload-aborted'): raise Rejected('upload_state',409)
                active = one(c,"SELECT id FROM upload_session WHERE video=%s AND state IN ('creating','uploading')",(v['uuid'],))
                if active: raise Rejected('upload_already_active',409)
                uid = str(uuid.uuid4())
                accounting.reserve(c,uid,size)
                c.execute('INSERT INTO upload_session(id,video,operation_key,size,sha256,object_key,state,expires,policy,created) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
                    (uid,v['uuid'],key,size,sha,storage.raw_key(v['uuid'],uid),'creating',now()+config.UPLOAD_TTL,json.dumps(policy),now()))
                c.execute("UPDATE video SET state='uploading',modified=%s WHERE uuid=%s",(now(),v['uuid']))
                u = {'id':uid}
        u = ensure_multipart(u['id'])
        return upload_public(u,storage.list_parts(u))
    if op in ('upload_status','sign_part','complete_upload','abort_upload'):
        with transaction() as c:
            v,u = upload_row(c,data,True)
            if u['state'] in ('complete','aborted'): return upload_public(u)
            if op != 'abort_upload' and u['expires'] <= now(): raise Rejected('upload_expired',409)
            if not u['multipart_id']: raise Rejected('upload_preparing',409)
            partsize=capacity.session_part_size(u)
            if op == 'upload_status': return upload_public(u,storage.list_parts(u))
            if op == 'sign_part':
                # Standard SigV4 presigned URLs expose the signing key identifier (never its secret).
                # Explicitly approved: identifier only inside short-lived exact-part presigned URLs.
                if os.environ.get('ALLOW_PRESIGNED_KEY_IDENTIFIER') != 'explicitly-approved':
                    raise Rejected('presigned_identifier_policy_unresolved',409)
                part = data.get('part')
                if type(part) is not int or not 1 <= part <= math.ceil(u['size']/partsize):
                    raise Rejected('invalid_part',400)
                length = min(partsize,u['size']-(part-1)*partsize)
                url = storage.client().generate_presigned_url('upload_part', Params={'Bucket':config.RAW,
                    'Key':u['object_key'],'UploadId':u['multipart_id'],'PartNumber':part,'ContentLength':length},
                    ExpiresIn=300, HttpMethod='PUT')
                return {'url':url,'part':part,'expires':now()+300,'size':length}
            if op == 'abort_upload':
                try: storage.client().abort_multipart_upload(Bucket=config.RAW,Key=u['object_key'],UploadId=u['multipart_id'])
                except ClientError as e:
                    if e.response['Error']['Code'] != 'NoSuchUpload': raise
                # Complete may have succeeded before a lost reply. Do not free the
                # reservation while that exact raw object still exists or is uncertain.
                from service.storage_admin import delete_verified
                delete_verified(c,'raw',u['object_key'])
                c.execute("UPDATE upload_session SET state='aborted' WHERE id=%s",(u['id'],))
                c.execute('DELETE FROM storage_reservation WHERE upload_id=%s',(u['id'],))
                c.execute("UPDATE video SET state='upload-aborted',modified=%s WHERE uuid=%s",(now(),v['uuid']))
                return {'upload':u['id'],'state':'aborted'}
            # An R2 complete may have committed even if its reply was lost. HEAD reconciles the same exact key.
            try: head = storage.client().head_object(Bucket=config.RAW,Key=u['object_key'])
            except ClientError as e:
                if e.response['ResponseMetadata']['HTTPStatusCode'] != 404: raise
                parts = storage.list_parts(u)
                if len(parts) != math.ceil(u['size']/partsize) or sum(p['Size'] for p in parts) != u['size']:
                    raise Rejected('incomplete_parts',409)
                for i,p in enumerate(parts):
                    if p['PartNumber'] != i+1 or p['Size'] != min(partsize,u['size']-i*partsize):
                        raise Rejected('invalid_parts',409)
                storage.client().complete_multipart_upload(Bucket=config.RAW,Key=u['object_key'],UploadId=u['multipart_id'],
                    MultipartUpload={'Parts':[{k:p[k] for k in ('PartNumber','ETag')} for p in parts]})
                head = storage.client().head_object(Bucket=config.RAW,Key=u['object_key'])
            if head['ContentLength'] != u['size'] or head.get('Metadata',{}).get('upload') != u['id'] or head.get('Metadata',{}).get('sha256') != u['sha256']:
                raise Rejected('raw_verification_failed',409)
            jobid = str(uuid.uuid4())
            accounting.record(c,v['uuid'],'raw',u['object_key'],head['ContentLength'])
            c.execute('DELETE FROM storage_reservation WHERE upload_id=%s',(u['id'],))
            c.execute("UPDATE upload_session SET state='complete' WHERE id=%s",(u['id'],))
            c.execute("UPDATE video SET state='queued',raw_state='retained',raw_key=%s,raw_expires=%s,modified=%s WHERE uuid=%s",
                # Retention cap also covers an RC2 upload completed after migration004.
                (u['object_key'], now()+86400,now(),v['uuid']))
            c.execute("INSERT INTO job(id,video,upload_id,state,available,created,modified) VALUES(%s,%s,%s,'queued',%s,%s,%s)",
                (jobid,v['uuid'],u['id'],now(),now(),now()))
            c.execute('INSERT INTO outbox(job) VALUES(%s)',(jobid,))
            return {'upload':u['id'],'state':'complete'}
    if op == 'grant':
        with transaction() as c:
            v = video_row(c,data['uuid'])
            if v['state'] != 'ready' or data.get('revision') != v['revision']: raise Rejected('media_unavailable',409)
            subject, session = data.get('subject',''), data.get('session','')
            if not re.fullmatch('[a-f0-9]{64}',subject) or not re.fullmatch('[a-f0-9]{64}',session): raise Rejected('grant_scope',400)
            token, expires = grant(v,subject,session)
            return {'token':token,'expires':expires,'file':'master.m3u8' if v['kind']=='hls' else 'video.mp4','kind':v['kind']}
    if op == 'sync_references':
        refs, generation = data.get('references'),data.get('generation')
        if not isinstance(refs,list) or len(refs)>10000 or any(not isinstance(x,str) or not re.fullmatch('activity-[0-9]+',x) for x in refs): raise Rejected('reference_shape',400)
        refs = sorted(set(refs))
        if type(generation) is not int or generation<1: raise Rejected('reference_generation',400)
        with transaction() as c:
            v=video_row(c,data['uuid'],lock=True)
            if generation < v['refgeneration'] or (generation==v['refgeneration'] and refs!=json.loads(v['refs'])): raise Rejected('stale_reference',409)
            recycle.sync(c,v,refs,generation,data.get('recovery_days',7),data.get('title',''))
            return {'state':'synchronized'}
    if op in ('archive','request_deletion'):
        with transaction() as c:
            v=video_row(c,data['uuid'],owner_arg(data),True)
            if json.loads(v['refs']) or v['hold_flag']: raise Rejected('media_referenced_or_held',409)
            if v['state'] not in ('draft','archived','deleted') and v['refgeneration']<1: raise Rejected('references_unverified',409)
            state='archived' if op=='archive' else 'deletion-pending'
            if v['state']=='deleted': return {'state':'deleted'}
            key=operation_key(data) if op=='request_deletion' else ''
            if v['deletion_key'] and v['deletion_key']!=key: raise Rejected('deletion_conflict',409)
            c.execute('UPDATE video SET state=%s,deletion_key=%s,modified=%s WHERE uuid=%s',(state,key,now(),v['uuid']))
            return {'state':state}
    if op in ('export_owner','erase_owner'):
        owner=owner_arg(data)
        with transaction() as c:
            if op=='export_owner':
                c.execute('SELECT uuid,state,created,raw_expires,raw_state FROM video WHERE tenant=%s AND owner=%s',(config.TENANT,owner))
                return {'videos':c.fetchall()}
            key=operation_key(data)
            old=one(c,'SELECT * FROM privacy_request WHERE tenant=%s AND operation_key=%s',(config.TENANT,key))
            if old and old['owner_hash']!=digest(owner): raise Rejected('privacy_conflict',409)
            if not old:
                c.execute('UPDATE video SET owner=%s WHERE tenant=%s AND owner=%s',('erased-'+digest(owner),config.TENANT,owner))
                c.execute("INSERT INTO privacy_request VALUES(%s,%s,%s,'erase',%s)",(config.TENANT,key,digest(owner),now()))
            return {'state':'erased','shared_media':'retained'}
    if op == 'retry_processing':
        key=operation_key(data)
        with transaction() as c:
            v=video_row(c,data['uuid'],owner_arg(data),True)
            previous=one(c,'SELECT result FROM operation_result WHERE tenant=%s AND video=%s AND operation=%s AND operation_key=%s',
                (config.TENANT,v['uuid'],op,key))
            if previous: return json.loads(previous['result'])
            if v['state']!='failed' or v['raw_state']!='retained' or v['raw_expires']<=now(): raise Rejected('reupload_required',409)
            j=one(c,'SELECT * FROM job WHERE video=%s ORDER BY created DESC LIMIT 1 FOR UPDATE',(v['uuid'],))
            if not j or j['attempt']>=3: raise Rejected('retry_exhausted',409)
            c.execute("UPDATE job SET state='queued',available=%s,error_code='' WHERE id=%s",(now(),j['id']))
            c.execute('UPDATE outbox SET published=0 WHERE job=%s',(j['id'],))
            c.execute("UPDATE video SET state='queued',error_code='' WHERE uuid=%s",(v['uuid'],))
            c.execute('INSERT INTO operation_result VALUES(%s,%s,%s,%s,%s,%s)',
                (config.TENANT,v['uuid'],op,key,json.dumps({'state':'queued'}),now()))
            return {'state':'queued'}
    raise Rejected('unknown_operation',400)

@app.get('/health')
def health():
    try:
        with transaction() as c: c.execute('SELECT version FROM schema_version')
        redis_client().ping()
        return {'ready':True,'version':config.VERSION}
    except Exception: return JSONResponse({'ready':False},status_code=503)

@app.get('/live')
def live(): return {'alive':True}

@app.post('/v1/rpc')
async def endpoint(request: Request):
    requestid = str(uuid.uuid4())
    started = time.monotonic()
    try:
        if int(request.headers.get('content-length','0')) > 65536: raise Rejected('body_limit',413)
        if request.headers.get('content-type','').split(';')[0].strip().lower() != 'application/json' or request.headers.get('content-encoding','identity')!='identity':
            raise Rejected('content_type',415)
        body = b''
        async with asyncio.timeout(15):
            async for chunk in request.stream():
                body += chunk
                if len(body)>65536: raise Rejected('body_limit',413)
        await run_in_threadpool(authenticate,request.headers,body)
        data=json.loads(body)
        if not isinstance(data,dict): raise Rejected('invalid_json',400)
        await run_in_threadpool(rate,'rpc',config.TENANT,6000)
        op=data.get('op')
        if op=='grant':
            await run_in_threadpool(rate,'grant',str(data.get('subject'))+str(data.get('session')),120)
        elif op=='video_status':
            await run_in_threadpool(rate,'status',str(data.get('owner'))+str(data.get('uuid')),120)
        elif op in ('check_upload','start_upload','sign_part','complete_upload'):
            await run_in_threadpool(rate,'upload',data.get('owner',''),240)
        elif op in ('archive','request_deletion','erase_owner','export_owner','sync_references',
                    'storage_policy','storage_status','recycle_restore','recycle_delete','capabilities'):
            await run_in_threadpool(rate,'admin',config.TENANT,300)
        result=await run_in_threadpool(rpc,data)
        print(json.dumps({'request':requestid,'operation':data.get('op'),'result':'ok','ms':round((time.monotonic()-started)*1000)}))
        return JSONResponse({'ok':True,'data':result},headers={'Cache-Control':'no-store','X-Request-ID':requestid})
    except TimeoutError:
        return JSONResponse({'ok':False,'error':'body_timeout'},status_code=408)
    except Rejected as e:
        print(json.dumps({'request':requestid,'error':e.code}))
        return JSONResponse({'ok':False,'error':e.code},status_code=e.status,headers={'Retry-After':'60'} if e.status==429 else {})
    except (KeyError,ValueError,TypeError):
        return JSONResponse({'ok':False,'error':'invalid_request'},status_code=400)
    except Exception:
        # Exception repr can contain R2 request URLs/headers. Do not emit it or return provider bodies.
        print(json.dumps({'request':requestid,'error':'service_temporarily_unavailable'}))
        return JSONResponse({'ok':False,'error':'service_temporarily_unavailable'},status_code=503)
