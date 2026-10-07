# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Worker-local staging heartbeat, early admission estimate, and immutable multipart policy."""
import json,math,shutil,tempfile
from . import config
from .hardening import redis_client
from .security import Rejected

def part_size(size):
    # <=8192 parts, integer MiB boundaries; old sessions retain their8MiB default.
    return max(config.PART_SIZE,((size+8192*1048576-1)//(8192*1048576))*1048576)

def session_part_size(upload):
    return json.loads(upload['policy']).get('upload_part_bytes',config.PART_SIZE)

def required_space(size,duration,profile,kind='hls'):
    # Maximum ladder bitrate plus AAC and mux/metadata allowance. No source=output assumption.
    bitrate={'economy':696000,'standard':2192000,'full':5088000}[profile]
    if kind=='direct':bitrate+={'economy':696000,'standard':1496000,'full':2896000}[profile]
    return size+math.ceil(duration*bitrate/8*1.35)+512*1024**2

def publish():
    redis_client().set(config.REDIS_NAMESPACE+':staging',json.dumps({
        'free':shutil.disk_usage(tempfile.gettempdir()).free,'maxbytes':config.MAX_SIZE,
        'duration':config.MAX_DURATION}),ex=30)

def check(size,duration,profile,kind='hls'):
    if type(size) is not int or not 1<=size<=config.MAX_SIZE or type(duration) not in (int,float) or not math.isfinite(duration) or not 0<duration<=config.MAX_DURATION:
        raise Rejected('upload_capacity',409)
    raw=redis_client().get(config.REDIS_NAMESPACE+':staging')
    if not raw:raise Rejected('upload_capacity',409)
    worker=json.loads(raw)
    if size>worker['maxbytes'] or duration>worker['duration'] or required_space(size,duration,profile,kind)>worker['free']:
        raise Rejected('upload_capacity',409)
    return {'capacity_checked':True}
