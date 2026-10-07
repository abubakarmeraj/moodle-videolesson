# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Private gateway: authenticate first, resolve only a published manifest path, then return bytes."""
import posixpath
import re
from urllib.parse import urlencode
from fastapi import FastAPI, Request
from fastapi.responses import Response, StreamingResponse
from fastapi.middleware.cors import CORSMiddleware
import os
from . import config, storage
from .db import transaction, one
from .security import verify_grant, Rejected
from .hardening import Ingress, rate, redis_client

app=FastAPI(docs_url=None,redoc_url=None,openapi_url=None)
app.add_middleware(CORSMiddleware,allow_origins=config.ORIGINS,
    allow_methods=['GET','HEAD'],allow_headers=['Range'],expose_headers=['Content-Range','Content-Length','Accept-Ranges'])
app.add_middleware(Ingress,role='gateway')

def resolve(c,g,path):
    if not isinstance(path,str) or len(path)>255 or not re.fullmatch(r'[a-zA-Z0-9_./-]+',path) or any(p in ('','..','.') for p in path.split('/')):
        raise Rejected('path_denied')
    v=one(c,"SELECT m.prefix FROM media_version m JOIN video v ON v.uuid=m.video WHERE m.video=%s AND m.revision=%s AND v.tenant=%s AND v.state='ready' AND v.revision=m.revision",
        (g['video'],g['revision'],g['tenant']))
    obj=one(c,'SELECT * FROM rendition WHERE video=%s AND revision=%s AND path=%s',(g['video'],g['revision'],path))
    if not v or not obj: raise Rejected('object_denied')
    return v['prefix']+path,obj

@app.get('/health')
def health():
    try:
        with transaction() as c: c.execute('SELECT version FROM schema_version')
        redis_client().ping()
        return {'ready':True,'role':'private-media-gateway','version':config.VERSION}
    except Exception: return Response(status_code=503)

@app.api_route('/media',methods=['GET','HEAD'])
def media(request:Request,grant:str='',file:str=''):
    headers={'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff','Referrer-Policy':'no-referrer'}
    try:
        g=verify_grant(grant)
        rate('media',g['sub']+g['session'],1200)
        with transaction() as c: key,obj=resolve(c,g,file)
        headers['ETag']='"'+obj['sha256']+'"' if not file.endswith('.m3u8') else '"'+__import__('hashlib').sha256((obj['sha256']+grant).encode()).hexdigest()+'"'
        if file.endswith('.m3u8'):
            raw=storage.client().get_object(Bucket=config.PROCESSED,Key=key)['Body']
            data=raw.read(4*1024*1024+1);raw.close()
            if len(data)>4*1024*1024: raise Rejected('playlist_limit')
            lines=[]
            with transaction() as c:
                for line in data.decode('utf8').splitlines():
                    if line and not line.startswith('#'):
                        if '://' in line or line.startswith('/') or '..' in line.split('/'): raise Rejected('playlist_path')
                        child=posixpath.join(posixpath.dirname(file),line)
                        resolve(c,g,child)
                        line=config.GATEWAY+'/media?'+urlencode({'grant':grant,'file':child})
                    elif 'URI=' in line: raise Rejected('unsupported_playlist_uri')
                    lines.append(line)
            body=('\n'.join(lines)+'\n').encode()
            headers['Content-Length']=str(len(body))
            return Response(b'' if request.method=='HEAD' else body,media_type=obj['mime'],headers=headers)
        start,end=0,obj['size']-1
        status=200
        if request.headers.get('range'):
            match=re.fullmatch(r'bytes=([0-9]*)-([0-9]*)',request.headers['range'])
            if not match or not any(match.groups()): raise Rejected('range_denied',416)
            first,last=match.groups()
            if first:
                start=int(first);end=min(int(last),end) if last else end
            else: start=max(0,obj['size']-int(last))
            if start>end or start>=obj['size']: raise Rejected('range_denied',416)
            status=206;headers['Content-Range']=f'bytes {start}-{end}/{obj["size"]}'
        headers.update({'Content-Length':str(end-start+1),'Accept-Ranges':'bytes'})
        if request.method=='HEAD': return Response(status_code=status,media_type=obj['mime'],headers=headers)
        raw=storage.client().get_object(Bucket=config.PROCESSED,Key=key,Range=f'bytes={start}-{end}')['Body']
        def chunks():
            try:
                while chunk:=raw.read(64*1024): yield chunk
            finally: raw.close()
        return StreamingResponse(chunks(),status_code=status,media_type=obj['mime'],headers=headers)
    except Rejected as e:
        if e.status==429: headers['Retry-After']='60'
        if e.status==416 and 'obj' in locals(): headers['Content-Range']=f'bytes */{obj["size"]}'
        return Response(status_code=e.status,headers=headers)
    except Exception: return Response(status_code=503,headers=headers)
