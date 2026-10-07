# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Deployment-owned configuration; values never logged."""
import os
import ipaddress
import re
from pathlib import Path
from urllib.parse import urlsplit

def required(name):
    value = setting(name)
    if not value:
        raise RuntimeError('Missing deployment setting: ' + name)
    return value

def setting(name, default=''):
    """A deployment may mount role-specific secret files instead of environment values."""
    filename = os.environ.get(name + '_FILE')
    if filename:
        if os.environ.get(name):
            raise RuntimeError('Ambiguous deployment setting: ' + name)
        return Path(filename).read_text().strip()
    return os.environ.get(name, default)

TENANT = required('SERVICE_TENANT')
ROLE = setting('SERVICE_ROLE', 'api')
ENVIRONMENT = setting('APP_ENV', 'production')
if ROLE not in ('api', 'gateway', 'worker', 'dispatcher', 'janitor', 'migrate') or ENVIRONMENT not in ('development', 'production'):
    raise RuntimeError('Invalid deployment role/environment')
KEY_ID = required('SERVICE_KEY_ID') if ROLE == 'api' else setting('SERVICE_KEY_ID')
AUTH_KEY = required('SERVICE_AUTH_KEY') if ROLE == 'api' else setting('SERVICE_AUTH_KEY')
GRANT_KEY = required('GRANT_KEY') if ROLE in ('api', 'gateway') else setting('GRANT_KEY')
AUTH_KEYS = {KEY_ID: (AUTH_KEY, None)}
if os.environ.get('SERVICE_PREVIOUS_KEY_ID'):
    previous_id=required('SERVICE_PREVIOUS_KEY_ID')
    previous_key=required('SERVICE_PREVIOUS_AUTH_KEY')
    previous_until=int(required('SERVICE_PREVIOUS_KEY_UNTIL'))
    if previous_id==KEY_ID or len(previous_key)<32 or not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}',previous_id):
        raise RuntimeError('Invalid previous key configuration')
    AUTH_KEYS[previous_id]=(previous_key,previous_until)
GATEWAY = required('GATEWAY_ORIGIN')
RAW = required('RAW_BUCKET')
PROCESSED = required('PROCESSED_BUCKET')
STORAGE_BACKEND = setting('STORAGE_BACKEND', 'r2')
if STORAGE_BACKEND not in ('r2', 's3-compatible'):
    raise RuntimeError('Invalid storage backend')
# Retain historical R2 names as aliases, never ambiguous competing configuration.
def storage_setting(name, legacy=None, default=''):
    value = setting(name)
    old = setting(legacy) if legacy else ''
    if value and old and value != old:
        raise RuntimeError('Conflicting storage configuration: ' + name)
    return value or old or default

ENDPOINT = storage_setting('STORAGE_ENDPOINT', 'R2_ENDPOINT')
REGION = setting('STORAGE_REGION', 'auto' if STORAGE_BACKEND == 'r2' else 'us-east-1')
ADDRESSING_STYLE = setting('STORAGE_ADDRESSING_STYLE', 'path')
if not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}', REGION) or ADDRESSING_STYLE not in ('path', 'virtual'):
    raise RuntimeError('Invalid storage region/addressing style')
if RAW == PROCESSED or any(not re.fullmatch(r'[a-z0-9][a-z0-9-]{1,61}[a-z0-9]', x) for x in (RAW, PROCESSED)):
    raise RuntimeError('Invalid separate bucket names')
if STORAGE_BACKEND == 'r2' and not re.fullmatch(r'https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com', ENDPOINT):
    raise RuntimeError('Invalid fixed R2 endpoint')
try:
    storage_url = urlsplit(ENDPOINT)
    storage_port = storage_url.port
except ValueError:
    raise RuntimeError('Invalid storage endpoint') from None
host = storage_url.hostname or ''
try:
    ipaddress.ip_address(host)
    valid_host = True
except ValueError:
    valid_host = bool(re.fullmatch(r'(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?', host)) and '..' not in host
insecure = setting('STORAGE_ALLOW_INSECURE_HTTP', 'false')
if insecure not in ('true', 'false'):
    raise RuntimeError('Invalid storage HTTP opt-in')
if (not valid_host or storage_url.username is not None or storage_url.password is not None or
        storage_url.path not in ('', '/') or storage_url.query or storage_url.fragment or
        any(c.isspace() for c in ENDPOINT) or '\\' in ENDPOINT or '%' in storage_url.netloc or
        '?' in ENDPOINT or '#' in ENDPOINT or storage_port == 0 or
        storage_url.scheme not in ('https', 'http') or
        (storage_url.scheme == 'http' and not (ENVIRONMENT == 'development' and insecure == 'true' and
                                               STORAGE_BACKEND == 's3-compatible'))):
    raise RuntimeError('Invalid storage endpoint/TLS policy')
STORAGE_CA_BUNDLE = setting('STORAGE_CA_BUNDLE')
if STORAGE_CA_BUNDLE and not Path(STORAGE_CA_BUNDLE).is_file():
    raise RuntimeError('Storage CA bundle unavailable')
# No verify=false switch: a private CA is explicit and HTTPS verification remains enabled.
STORAGE_VERIFY = STORAGE_CA_BUNDLE or True
if (ROLE == 'api' and (len(AUTH_KEY) < 32 or not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}', KEY_ID))) or (ROLE in ('api','gateway') and len(GRANT_KEY)<32) or not re.fullmatch(r'[a-zA-Z0-9_-]{8,64}', TENANT):
    raise RuntimeError('Invalid deployment identity/key configuration')
ORIGINS = required('ALLOWED_ORIGINS').split(',')
for origin in [GATEWAY, *ORIGINS]:
    parsed = urlsplit(origin)
    if parsed.scheme not in (('http','https') if ENVIRONMENT=='development' else ('https',)) or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('','/'):
        raise RuntimeError('Invalid HTTPS origin configuration')
ALLOWED_HOSTS = setting('ALLOWED_HOSTS', '127.0.0.1,localhost' if ENVIRONMENT=='development' else '').split(',')
if not all(re.fullmatch(r'[a-zA-Z0-9.-]+', x) for x in ALLOWED_HOSTS):
    raise RuntimeError('Explicit allowed hosts required')
REDIS_URL = required('REDIS_URL')
REDIS_NAMESPACE = setting('REDIS_NAMESPACE', 'codefortex:video:' + TENANT)
if not re.fullmatch(r'[a-zA-Z0-9:_-]{8,120}', REDIS_NAMESPACE):
    raise RuntimeError('Invalid Redis namespace')
STREAM = REDIS_NAMESPACE + ':jobs'
GROUP = 'encoders-v1'
VERSION = (Path(__file__).parents[1] / 'VERSION').read_text().strip()
PART_SIZE = 8 * 1024 * 1024
MAX_SIZE = int(setting('VIDEO_MAX_SOURCE_BYTES', str(256 * 1024**3)))
MAX_DURATION = int(setting('VIDEO_MAX_DURATION_SECONDS', '21600'))
ENCODE_TIMEOUT = int(setting('VIDEO_ENCODE_TIMEOUT_SECONDS', '172800'))
UPLOAD_TTL = int(setting('VIDEO_UPLOAD_SESSION_SECONDS', '259200'))
if not 1048576 <= MAX_SIZE <= 1024**4 or not 60 <= MAX_DURATION <= 21600 or not 1800 <= ENCODE_TIMEOUT <= 604800 or not 86400 <= UPLOAD_TTL <= 604800:
    raise RuntimeError('Invalid long-upload deployment limits')
FFMPEG_THREADS = int(setting('VIDEO_FFMPEG_THREADS', '2'))
if not 1 <= FFMPEG_THREADS <= 16:
    raise RuntimeError('VIDEO_FFMPEG_THREADS must be between1 and16')
# Decoder/encoder caps are not a total CPU quota. Deployment must reserve host CPU
# using a worker cgroup; filters are separately bounded in the command.
if int(setting('VIDEO_WORKER_CONCURRENCY', '1')) != 1:
    raise RuntimeError('This worker supports one video job per process')
GRANT_TTL = int(os.environ.get('GRANT_TTL', '300'))
if not 5 <= GRANT_TTL <= 300:
    raise RuntimeError('Invalid grant TTL')
