# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""One non-root encoder, fenced DB lease and immutable attempt outputs. At-least-once, not exactly-once."""
import hashlib
import json
import math
import os
import re
from pathlib import Path
import resource
import shutil
import signal
import subprocess
import tempfile
import time
import uuid
import redis
from . import config, storage, capacity
from .db import transaction, one
from .security import Rejected, identifier

running=True
workerid=str(uuid.uuid4())
def stop(*_):
    global running
    running=False

def log(job,stage,**fields):
    print(json.dumps({'job':job,'stage':stage,**fields}))

def claim(jobid):
    with transaction() as c:
        j=one(c,'SELECT * FROM job WHERE id=%s FOR UPDATE',(identifier(jobid),))
        if not j or j['state']!='queued' or j['available']>time.time(): return None
        v=one(c,'SELECT * FROM video WHERE uuid=%s FOR UPDATE',(j['video'],))
        if not v or v['state'] not in ('queued','processing') or v['raw_state']!='retained': return None
        j.update(fence=j['fence']+1,attempt=j['attempt']+1,worker=workerid)
        c.execute("UPDATE job SET state='processing',progress=70,stage='verifying',qualities='[]',fence=%s,attempt=%s,worker=%s,lease_until=%s,modified=%s WHERE id=%s",
            (j['fence'],j['attempt'],workerid,int(time.time())+90,int(time.time()),j['id']))
        c.execute("UPDATE video SET state='verifying',attempt=%s WHERE uuid=%s",(j['attempt'],j['video']))
        j['upload']=one(c,'SELECT * FROM upload_session WHERE id=%s',(j['upload_id'],))
        j['revision']=v['revision']; j['kind']=v['kind']
        return j

def heartbeat(j):
    if not running: raise Rejected('worker_stopping',503)
    capacity.publish()
    with transaction() as c:
        c.execute("UPDATE job SET lease_until=%s,modified=%s WHERE id=%s AND state='processing' AND fence=%s AND worker=%s AND lease_until>=%s",
            (int(time.time())+90,int(time.time()),j['id'],j['fence'],workerid,int(time.time())))
        if c.rowcount!=1: raise Rejected('lease_lost',409)

def limits():
    resource.setrlimit(resource.RLIMIT_FSIZE,(2*1024**4,2*1024**4))
    resource.setrlimit(resource.RLIMIT_NOFILE,(128,128))

def progress(j, value, stage, qualities=None):
    """Fenced, monotonic within one processing attempt. Never accepts browser progress."""
    with transaction() as c:
        c.execute("UPDATE job SET progress=GREATEST(progress,%s),stage=%s,qualities=COALESCE(%s,qualities) "
            "WHERE id=%s AND state='processing' AND fence=%s AND worker=%s AND lease_until>=%s",
            (min(99,max(70,int(value))),stage,json.dumps(qualities) if qualities is not None else None,
             j['id'],j['fence'],workerid,int(time.time())))


def command(j,args,timeout=None,notify=None):
    if timeout is None:timeout=json.loads(j['upload']['policy']).get('encode_timeout_seconds',config.ENCODE_TIMEOUT)
    # Input filenames are service-assigned; argv is never interpreted by a shell. Output is never logged verbatim.
    if shutil.disk_usage(tempfile.gettempdir()).free < 128*1024**2:
        raise Rejected('temporary_space_low',503)
    with tempfile.TemporaryFile() as err, tempfile.TemporaryFile() as out:
        p=subprocess.Popen(args,stdout=out,stderr=err,stdin=subprocess.DEVNULL,preexec_fn=limits)
        started=time.monotonic()
        try:
            while p.poll() is None:
                heartbeat(j)
                if notify: notify()
                if time.monotonic()-started>timeout: raise Rejected('encode_timeout',422)
                if shutil.disk_usage(tempfile.gettempdir()).free < 256*1024**2:raise Rejected('temporary_space_low',503)
                time.sleep(1)
            if p.returncode: raise Rejected('media_tool_failed',422)
            out.seek(0); result=out.read(1024*1024+1)
            if len(result)>1024*1024: raise Rejected('probe_limit',422)
            return result
        finally:
            if p.poll() is None: p.kill();p.wait()

def inspect(j,source):
    probe=json.loads(command(j,['ffprobe','-v','error','-protocol_whitelist','file,pipe',
        '-format_whitelist','mov,matroska,webm,avi,mpegts,ogg,flv','-probesize','16777216','-analyzeduration','30000000',
        '-show_streams','-show_format','-of','json',str(source)],120))
    videos=[s for s in probe.get('streams',[]) if s.get('codec_type')=='video' and not s.get('disposition',{}).get('attached_pic')]
    if len(videos)!=1: raise Rejected('video_stream_required',422)
    v=videos[0];w,h=v.get('width',0),v.get('height',0)
    duration=float(probe.get('format',{}).get('duration',0))
    if not math.isfinite(duration) or not 0<duration<=json.loads(j['upload']['policy']).get('max_duration_seconds',config.MAX_DURATION) or not 16<=w<=4096 or not 16<=h<=4096 or w*h>4096*2160:
        raise Rejected('media_limits',422)
    rotation=next((float(x['rotation']) for x in v.get('side_data_list',[]) if 'rotation' in x),0)
    if round(rotation)%180: w,h=h,w
    policy=json.loads(j['upload']['policy'])
    targets={'economy':[480],'standard':[480,720],'full':[480,720,1080]}[policy['profile']]
    heights=[x for x in targets if x<=h] or [h-h%2]
    ladder=[(max(2,int(w*y/h)//2*2),y) for y in heights]
    return duration,ladder,probe

def process(j):
    policy=json.loads(j['upload']['policy'])
    required=capacity.required_space(j['upload']['size'],policy['source_duration_hint'],policy['profile'],j['kind']) if 'source_duration_hint' in policy else j['upload']['size']*2+256*1024**2
    if shutil.disk_usage(tempfile.gettempdir()).free < required:
        raise Rejected('temporary_space_low',503)
    prefix=storage.processed_prefix(j['video'],j['revision'],j['id'],j['fence'])
    with tempfile.TemporaryDirectory(prefix='cfvideo-') as directory:
        root=Path(directory);source=root/'source';output=root/'output';output.mkdir()
        raw=storage.client().get_object(Bucket=config.RAW,Key=j['upload']['object_key'])
        if raw['ContentLength']!=j['upload']['size'] or raw['ContentLength']>config.MAX_SIZE: raise Rejected('source_size',422)
        hasher=hashlib.sha256();size=0
        try:
            with source.open('wb') as f:
                while chunk:=raw['Body'].read(1024*1024):
                    heartbeat(j); size+=len(chunk)
                    if size>config.MAX_SIZE: raise Rejected('source_size',422)
                    hasher.update(chunk);f.write(chunk)
        finally:
            raw['Body'].close()
        if size!=j['upload']['size'] or hasher.hexdigest()!=j['upload']['sha256']: raise Rejected('source_checksum',422)
        duration,ladder,probe=inspect(j,source)
        policy=json.loads(j['upload']['policy'])
        # Browser metadata is advisory. Actual probe is authoritative before encoding.
        if abs(duration-policy.get('source_duration_hint',duration))>2 or shutil.disk_usage(root).free < capacity.required_space(0,duration,policy['profile'],j['kind']):
            raise Rejected('upload_capacity',409)
        # Legacy queued jobs retain their original two-second policy on retry.
        segment_seconds=policy.get('hls_segment_seconds',2)
        if type(segment_seconds) is not int or segment_seconds not in (2,4,6):
            raise Rejected('invalid_segment_policy',422)
        progress(j,75,'processing',[height for _,height in ladder])
        log(j['id'],'source_validated',ladder=ladder,duration=duration,attempt=j['attempt'])
        with transaction() as c:
            c.execute("UPDATE video SET state='processing' WHERE uuid=%s AND state='verifying'",(j['video'],))
        inp=['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-threads',str(config.FFMPEG_THREADS),
            '-filter_threads','1',
            '-protocol_whitelist','file,pipe','-format_whitelist','mov,matroska,webm,avi,mpegts,ogg,flv','-i',str(source)]
        variants=[];boundaries=None
        for index,(width,height) in enumerate(ladder):
            log(j['id'],'encode_rendition',height=height,attempt=j['attempt'])
            path=output/f'{height}p';path.mkdir()
            rate=600 if height<=480 else 1400 if height<=720 else 2800
            progressfile=root/'encode-progress'
            progressfile.write_text('')
            def report():
                # FFmpeg timestamps are actual encoded work, not a timer-based approximation.
                with progressfile.open('rb') as stream:
                    stream.seek(max(0,progressfile.stat().st_size-8192))
                    text=stream.read(8192).decode('ascii',errors='ignore')
                values=re.findall(r'out_time_us=(\d+)',text)
                fraction=min(1,int(values[-1])/1_000_000/duration) if values else 0
                progress(j,75+20*(index+fraction)/len(ladder),'processing')
            command(j,inp+['-progress',str(progressfile),'-nostats','-map','0:v:0','-map','0:a:0?','-vf',f'scale={width}:{height},setsar=1',
                '-r','24','-c:v','libx264','-threads',str(config.FFMPEG_THREADS),'-preset','veryfast','-profile:v','main','-level','4.1',
                '-pix_fmt','yuv420p','-b:v',f'{rate}k','-maxrate',f'{rate}k','-bufsize',f'{rate*2}k',
                '-g',str(24*segment_seconds),'-keyint_min',str(24*segment_seconds),'-sc_threshold','0',
                '-force_key_frames',f'expr:gte(t,n_forced*{segment_seconds})',
                '-c:a','aac','-b:a','96k','-ac','2','-hls_time',str(segment_seconds),'-hls_playlist_type','vod',
                '-hls_segment_filename',str(path/'segment-%04d.ts'),str(path/'index.m3u8')],notify=report)
            progress(j,75+20*(index+1)/len(ladder),'processing')
            playlist=(path/'index.m3u8').read_text()
            if '#EXT-X-ENDLIST' not in playlist: raise Rejected('invalid_output',422)
            segment_bytes=[];lengths=[]
            for line in playlist.splitlines():
                if line.startswith('#EXTINF:'): lengths.append(float(line[8:].split(',')[0]))
                elif line and not line.startswith('#'):
                    if not re_segment(line) or not (path/line).is_file(): raise Rejected('invalid_output',422)
                    segment_bytes.append((path/line).stat().st_size)
            if len(lengths)!=len(segment_bytes) or not lengths: raise Rejected('invalid_output',422)
            target=re.search(r'#EXT-X-TARGETDURATION:(\d+)',playlist)
            if not target or int(target[1])<math.ceil(max(lengths)-1e-6) or any(
                    not math.isfinite(t) or t<=0 or t>segment_seconds+1/24 for t in lengths):
                raise Rejected('segment_contract',422)
            if any(abs(t-segment_seconds)>1/24 for t in lengths[:-1]) or abs(sum(lengths)-duration)>.15:
                raise Rejected('segment_timeline',422)
            if boundaries is not None and (len(boundaries)!=len(lengths) or
                    any(abs(a-b)>1/24 for a,b in zip(boundaries,lengths))):
                raise Rejected('rendition_alignment',422)
            boundaries=lengths
            bandwidth=math.ceil(max(8*b/t for b,t in zip(segment_bytes,lengths))*1.1)
            # Derive advertised codec/dimensions from actual encoded bytes, not source assumptions.
            encoded=json.loads(command(j,['ffprobe','-v','error','-show_streams','-show_data','-of','json',
                str(path/'segment-0000.ts')],30))['streams']
            stream=next(s for s in encoded if s.get('codec_type')=='video')
            if stream.get('codec_name')!='h264' or (stream['width'],stream['height'])!=(width,height):
                raise Rejected('output_contract',422)
            extra=b''.join(bytes.fromhex(line.split(':',1)[1].strip().split('  ',1)[0].replace(' ',''))
                for line in stream.get('extradata','').splitlines() if ':' in line)
            sps=re.search(b'\x00\x00\x01\x67(.{3})',extra)
            if not sps: raise Rejected('output_codec',422)
            codecs='avc1.'+sps[1].hex()
            audio=[s for s in encoded if s.get('codec_type')=='audio']
            if audio:
                if any(s.get('codec_name')!='aac' or s.get('profile')!='LC' for s in audio):
                    raise Rejected('output_audio_codec',422)
                codecs+=',mp4a.40.2'
            variants.append({'width':width,'height':height,'bandwidth':bandwidth,'codecs':codecs})
        master=['#EXTM3U','#EXT-X-VERSION:3']
        for v in variants:
            master += [f'#EXT-X-STREAM-INF:BANDWIDTH={v["bandwidth"]},RESOLUTION={v["width"]}x{v["height"]},CODECS="{v["codecs"]}"',f'{v["height"]}p/index.m3u8']
        (output/'master.m3u8').write_text('\n'.join(master)+'\n')
        command(j,inp+['-vf','scale=320:-2','-frames:v','1',str(output/'poster.jpg')])
        (output/'thumbnails').mkdir()
        command(j,inp+['-vf',f'fps=1/{max(1,duration/10)},scale=160:-2','-frames:v','10',str(output/'thumbnails/frame-%03d.jpg')])
        if j['kind']=='direct':
            command(j,['ffmpeg','-nostdin','-v','error','-y','-protocol_whitelist','file,crypto,data',
                '-i',str(output/f'{ladder[-1][1]}p/index.m3u8'),'-c','copy','-movflags','+faststart',str(output/'video.mp4')])
        if len(variants)!=len(ladder) or not (output/'poster.jpg').is_file() or not list((output/'thumbnails').glob('frame-*.jpg')):
            raise Rejected('publication_contract',422)
        metadata={'duration':duration,'renditions':variants,'policy':json.loads(j['upload']['policy']),
            'execution':{'ffmpeg_threads':config.FFMPEG_THREADS,'filter_threads':1,'rendition_parallelism':1}}
        (output/'metadata.json').write_text(json.dumps(metadata,separators=(',',':')))
        files=[]
        progress(j,95,'finalizing')
        log(j['id'],'upload_outputs',attempt=j['attempt'])
        for path in sorted(output.rglob('*')):
            if not path.is_file(): continue
            heartbeat(j)
            relative=path.relative_to(output).as_posix()
            mime={'.m3u8':'application/vnd.apple.mpegurl','.ts':'video/mp2t','.jpg':'image/jpeg','.json':'application/json','.mp4':'video/mp4'}[path.suffix]
            with path.open('rb') as content:
                sha=hashlib.file_digest(content,'sha256').hexdigest()
                content.seek(0)
                # Account conservatively before an uncertain remote write, including failed attempts.
                from . import accounting
                with transaction() as c:
                    accounting.record(c,j['video'],'processed',prefix+relative,path.stat().st_size,0)
                storage.client().put_object(Bucket=config.PROCESSED,Key=prefix+relative,Body=content,ContentType=mime,
                    Metadata={'sha256':sha})
                with transaction() as c:
                    accounting.record(c,j['video'],'processed',prefix+relative,path.stat().st_size)
            files.append((relative,path.stat().st_size,sha,mime))
        heartbeat(j)
        with transaction() as c:
            current=one(c,'SELECT * FROM job WHERE id=%s FOR UPDATE',(j['id'],))
            v=one(c,'SELECT state FROM video WHERE uuid=%s FOR UPDATE',(j['video'],))
            if current['state']!='processing' or current['fence']!=j['fence'] or current['worker']!=workerid or current['lease_until']<time.time() or v['state']!='processing':
                raise Rejected('stale_publish',409)
            c.execute('INSERT INTO media_version VALUES(%s,%s,%s,%s,%s,%s,%s)',
                (j['video'],j['revision'],prefix,duration,j['upload']['policy'],json.dumps(metadata),int(time.time())))
            c.executemany('INSERT INTO rendition VALUES(%s,%s,%s,%s,%s,%s)',[(j['video'],j['revision'],*f) for f in files])
            c.execute("UPDATE video SET state='ready',duration=%s,error_code='',modified=%s WHERE uuid=%s",(duration,int(time.time()),j['video']))
            c.execute("UPDATE job SET state='complete',progress=100,stage='ready',lease_until=0,modified=%s WHERE id=%s",(int(time.time()),j['id']))
        log(j['id'],'ready',files=len(files))

def re_segment(value):
    import re
    return re.fullmatch(r'segment-[0-9]{4,6}\.ts',value)

def fail(j,code):
    with transaction() as c:
        c.execute("UPDATE job SET state='failed',error_code=%s,modified=%s WHERE id=%s AND fence=%s AND state='processing'",
            (code,int(time.time()),j['id'],j['fence']))
        if c.rowcount:
            c.execute("UPDATE video SET state='failed',error_code=%s WHERE uuid=%s AND state IN ('processing','verifying')",(code,j['video']))
    log(j['id'],'failed',error=code)

if __name__=='__main__':
    signal.signal(signal.SIGTERM,stop);signal.signal(signal.SIGINT,stop)
    client=redis.Redis.from_url(config.REDIS_URL,decode_responses=True,socket_timeout=10,socket_connect_timeout=5)
    while running:
        try:
            capacity.publish()
            try: client.xgroup_create(config.STREAM,config.GROUP,id='0',mkstream=True)
            except redis.ResponseError as e:
                if 'BUSYGROUP' not in str(e): raise
            batch=client.xreadgroup(config.GROUP,workerid,{config.STREAM:'>'},count=1,block=2000)
            for _,messages in batch:
                for message,fields in messages:
                    j=claim(fields['job'])
                    if j:
                        try: process(j)
                        except Rejected as e:
                            if e.code not in ('worker_stopping','lease_lost','stale_publish'): fail(j,e.code)
                        except Exception: fail(j,'processing_io_failure')
                    client.xack(config.STREAM,config.GROUP,message)
        except Exception:
            log(None,'transport_retry');time.sleep(2)
