#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Native installer. Fresh Ubuntu 24.04 only; existing DB requires manual integration.

Qualification mode has no usable storage credentials and denies non-loopback traffic.
Never log the generated state or environment dictionaries.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import subprocess
import sys
import time
import urllib.request
from urllib.parse import urlsplit

ETC = Path('/etc/video-lesson')
APP = Path('/opt/video-lesson')
DATA = Path('/var/lib/video-lesson')
ROLES = ('api', 'gateway', 'dispatcher', 'worker', 'janitor', 'migrate')
COMMANDS = {
    'api': '+ping +incr +expire +eval +get',
    'gateway': '+ping +incr +expire +eval',
    'dispatcher': '+ping +xgroup +xadd',
    'worker': '+ping +xgroup +xreadgroup +xack +set',
    'janitor': '+ping', 'migrate': '+ping',
}

def run(args, **kw):
    return subprocess.run(args, check=True, **kw)

def write(path, text, mode=0o600):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(path.name + '.new')
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, mode)
    with os.fdopen(fd, 'w') as f:
        f.write(text)
    os.chmod(tmp, mode)
    os.replace(tmp, path)

def sql(statement):
    # Secrets stay on stdin, never process arguments or evidence logs.
    run(['mariadb', '--protocol=socket', '--batch'], input=statement, text=True,
        stdout=subprocess.DEVNULL)

def role_env(state, role):
    p = state['policy']
    env = dict(APP_ENV='production', SERVICE_ROLE=role, SERVICE_TENANT=state['tenant'],
        ALLOWED_HOSTS='127.0.0.1,localhost', ALLOWED_ORIGINS=p['moodle_origin'],
        GATEWAY_ORIGIN=p['gateway_origin'], DB_HOST='127.0.0.1', DB_NAME='video_lesson',
        DB_USER='vl_' + role, DB_PASSWORD=state['db'][role],
        REDIS_URL='redis://vl_' + role + ':' + state['redis'][role] + '@127.0.0.1:16379/0',
        REDIS_NAMESPACE='video-lesson:' + state['tenant'], RAW_BUCKET=p['raw_bucket'],
        PROCESSED_BUCKET=p['processed_bucket'], R2_ENDPOINT=p['endpoint'],
        STORAGE_BACKEND=p.get('storage_backend','r2'),
        STORAGE_REGION=p.get('storage_region','auto' if p.get('storage_backend','r2')=='r2' else 'us-east-1'),
        STORAGE_ADDRESSING_STYLE=p.get('storage_addressing_style','path'),
        TMPDIR='/var/lib/video-lesson/' + role, PYTHONDONTWRITEBYTECODE='1',
        PYTHONUNBUFFERED='1', VIDEO_WORKER_CONCURRENCY='1', VIDEO_FFMPEG_THREADS='2',
        ALLOW_PRESIGNED_KEY_IDENTIFIER='explicitly-approved')
    if p.get('storage_ca_bundle'):
        env['STORAGE_CA_BUNDLE']=p['storage_ca_bundle']
    if role == 'api':
        env.update(SERVICE_KEY_ID='installation-1', SERVICE_AUTH_KEY=state['rpc'])
    if role in ('api', 'gateway'):
        env['GRANT_KEY'] = state['grant']
    if role in ('api', 'gateway', 'worker', 'janitor'):
        # Real storage keys are role-specific files, provided independently by administrator.
        if state['qualification']:
            env.update(R2_ACCESS_KEY='qualification-unusable', R2_SECRET_KEY='qualification-unusable')
        else:
            env.update(R2_ACCESS_KEY_FILE=p['storage_files'][role]['access'],
                       R2_SECRET_KEY_FILE=p['storage_files'][role]['secret'])
    return env

def preflight(args):
    if os.geteuid() != 0:
        raise RuntimeError('Run with sudo/root')
    release = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
    if release.get('ID', '').strip('"') != 'ubuntu' or release.get('VERSION_ID', '').strip('"') != '24.04':
        raise RuntimeError('First installer supports Ubuntu 24.04 only')
    if Path('/proc/1/comm').read_text().strip() != 'systemd':
        raise RuntimeError('systemd PID 1 required; enable systemd in the dedicated WSL distro')
    if not args.qualification_only and not args.config:
        raise RuntimeError('Provide --config for operator-owned R2, or explicit --qualification-only (no media support)')
    marker = ETC / 'installation.json'
    if not marker.exists():
        for path in (ETC, APP, DATA, Path('/var/lib/mysql/mysql')):
            if path.exists():
                raise RuntimeError('Existing installation/database detected: manual integration required; nothing overwritten')
        for role in ROLES:
            try:
                pwd.getpwnam('vl-' + role)
            except KeyError:
                continue
            raise RuntimeError('Existing role user detected; manual integration required')
    return marker

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--qualification-only', action='store_true')
    parser.add_argument('--config', type=Path)
    parser.add_argument('--check', action='store_true', help='Preflight only; no mutations')
    parser.add_argument('--reconfigure', action='store_true', help='Explicitly replace owned deployment policy, preserving generated identity')
    parser.add_argument('--qualification-fail-after-config', action='store_true', help='Disposable failure/retry test only')
    args = parser.parse_args()
    marker = preflight(args)
    if args.qualification_fail_after_config and not args.qualification_only:
        raise RuntimeError('Failure injection is restricted to qualification mode')
    source = Path(__file__).resolve().parents[2] / 'service/video-service'
    version = (source / 'VERSION').read_text().strip()
    if version != '0.5.0-rc1':
        raise RuntimeError('Service version outside reviewed installer contract')
    import importlib.util
    spec = importlib.util.spec_from_file_location('release_manifest', Path(__file__).with_name('manifest.py'))
    guard = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(guard)
    guard.verify(source.parents[1])
    policy = dict(endpoint='https://' + '0'*32 + '.r2.cloudflarestorage.com',
                  raw_bucket='qualification-private-raw', processed_bucket='qualification-private-processed',
                  moodle_origin='https://localhost:18445', gateway_origin='https://localhost:18444')
    if args.config:
        policy = json.loads(args.config.read_text())
        backend=policy.get('storage_backend','r2')
        endpoint=urlsplit(policy['endpoint'])
        if (backend not in ('r2','s3-compatible') or endpoint.scheme!='https' or not endpoint.hostname or
            endpoint.username is not None or endpoint.password is not None or endpoint.path not in ('','/') or
            endpoint.query or endpoint.fragment or any(c.isspace() for c in policy['endpoint'])):
            raise RuntimeError('Valid operator-owned HTTPS storage endpoint required')
        if backend=='r2' and not re.fullmatch(r'https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com',policy['endpoint']):
            raise RuntimeError('Invalid R2 endpoint')
        for key in ('moodle_origin', 'gateway_origin'):
            if not re.fullmatch(r'https://[A-Za-z0-9.-]+(?::[0-9]+)?', policy[key]):
                raise RuntimeError('HTTPS origins required')
        for role in ('api', 'gateway', 'worker', 'janitor'):
            for key in ('access', 'secret'):
                path = Path(policy['storage_files'][role][key])
                if not path.is_absolute() or not path.is_file():
                    raise RuntimeError('Provide role-scoped secret files before installation')
    if args.check:
        print('PREFLIGHT=PASS; no changes')
        return
    if marker.exists():
        state = json.loads(marker.read_text())
        if state['qualification'] != args.qualification_only or state['policy'] != policy:
            if not args.reconfigure:
                raise RuntimeError('Existing policy differs; explicit --reconfigure required')
            run(['systemctl','stop',*[('video-lesson-'+r) for r in ('api','gateway','dispatcher','worker','janitor.timer')]])
            state.update(qualification=args.qualification_only,policy=policy)
            write(marker,json.dumps(state))
    else:
        ETC.mkdir(mode=0o700)
        state = dict(qualification=args.qualification_only, policy=policy,
                     tenant='vl_' + secrets.token_hex(12), rpc=secrets.token_hex(32),
                     grant=secrets.token_hex(32), subject=secrets.token_hex(32),
                     db={r: secrets.token_hex(32) for r in ROLES},
                     redis={r: secrets.token_hex(32) for r in ROLES})
        write(marker, json.dumps(state))
    # Packages may start new services. Never use this path over an unowned existing DB.
    env = dict(os.environ, DEBIAN_FRONTEND='noninteractive')
    run(['apt-get', 'update', '-qq'], env=env)
    run(['apt-get', 'install', '-y', '-qq', 'python3-venv', 'ffmpeg', 'redis-server',
         'mariadb-server', 'ca-certificates', 'curl'], env=env)
    run(['systemctl', 'enable', '--now', 'mariadb'])
    sql('CREATE DATABASE IF NOT EXISTS video_lesson CHARACTER SET utf8mb4;')
    for role in ROLES:
        username = 'vl-' + role
        try:
            pwd.getpwnam(username)
        except KeyError:
            run(['useradd', '--system', '--user-group', '--no-create-home', '--shell', '/usr/sbin/nologin', username])
        info = pwd.getpwnam(username)
        path = DATA / role
        path.mkdir(parents=True, exist_ok=True, mode=0o700)
        os.chown(path, info.pw_uid, info.pw_gid)
        os.chmod(path, 0o700)
        grants = 'ALL PRIVILEGES' if role == 'migrate' else 'SELECT,INSERT,UPDATE,DELETE'
        sql(f"CREATE USER IF NOT EXISTS 'vl_{role}'@'127.0.0.1' IDENTIFIED BY '{state['db'][role]}';"
            f"GRANT {grants} ON video_lesson.* TO 'vl_{role}'@'127.0.0.1';")
        values = role_env(state, role)
        if not state['qualification'] and role in ('api','gateway','worker','janitor'):
            values['R2_ACCESS_KEY_FILE']=f'/run/credentials/video-lesson-{role}.service/storage-access'
            values['R2_SECRET_KEY_FILE']=f'/run/credentials/video-lesson-{role}.service/storage-secret'
        write(ETC / (role + '.env'), ''.join(k + '=' + v + '\n' for k, v in values.items()))
    if args.qualification_fail_after_config:
        raise RuntimeError('Injected qualification failure after configuration; rerun retains generated state')
    # Root reads EnvironmentFile; role users cannot read other roles' files.
    redisdir = Path('/etc/video-lesson-redis')
    redisdir.mkdir(mode=0o750, exist_ok=True)
    redisuser = pwd.getpwnam('redis')
    os.chown(redisdir, 0, redisuser.pw_gid)
    acl = 'user default off\n'
    for role in ROLES:
        acl += f"user vl_{role} on >{state['redis'][role]} ~video-lesson:{state['tenant']}:* -@all {COMMANDS[role]}\n"
    write(redisdir / 'users.acl', acl, 0o640)
    os.chown(redisdir / 'users.acl', 0, redisuser.pw_gid)
    write(redisdir / 'redis.conf', 'bind 127.0.0.1\nport 16379\nprotected-mode yes\naclfile /etc/video-lesson-redis/users.acl\nsave ""\nappendonly no\n', 0o640)
    os.chown(redisdir / 'redis.conf', 0, redisuser.pw_gid)
    write('/etc/systemd/system/video-lesson-redis.service', '[Unit]\nDescription=Video Lesson isolated Redis\nAfter=network.target\n[Service]\nUser=redis\nGroup=redis\nExecStart=/usr/bin/redis-server /etc/video-lesson-redis/redis.conf\nRestart=on-failure\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\nPrivateTmp=yes\n[Install]\nWantedBy=multi-user.target\n', 0o644)
    payload = sorted(p for p in source.rglob('*') if p.is_file() and '__pycache__' not in p.parts)
    digest = hashlib.sha256(''.join(str(p.relative_to(source)) + hashlib.sha256(p.read_bytes()).hexdigest() for p in payload).encode()).hexdigest()
    release = APP / (version + '-' + digest[:16])
    if not release.exists():
        release.mkdir(parents=True, mode=0o755)
        for p in payload:
            dest = release / p.relative_to(source)
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(p, dest)
            os.chmod(dest, 0o644)
    venv = release / '.venv'
    if not venv.exists():
        run(['python3', '-m', 'venv', str(venv)])
    run([str(venv / 'bin/pip'), 'install', '--disable-pip-version-check', '-r', str(release / 'requirements.txt')], cwd=release)
    for directory, subdirs, files in os.walk(APP):
        os.chmod(directory, 0o755)
    current = APP / 'current'
    if current.exists() and current.resolve() != release:
        raise RuntimeError('Changed source requires explicit upgrade preparation; current release retained')
    if not current.exists():
        current.symlink_to(release, target_is_directory=True)
    for role in ROLES:
        command = '/opt/video-lesson/current/.venv/bin/'
        if role in ('api', 'gateway'):
            port = 18090 if role == 'api' else 18091
            command += f'uvicorn service.{role}:app --host 127.0.0.1 --port {port} --no-access-log --no-proxy-headers'
        else:
            command += 'python -m service.' + ('migrate' if role == 'migrate' else role)
        oneshot = role in ('migrate', 'janitor')
        unit = f'''[Unit]
Description=Video Lesson {role}
After=network.target mariadb.service video-lesson-redis.service
Requires=mariadb.service video-lesson-redis.service
[Service]
Type={'oneshot' if oneshot else 'simple'}
User=vl-{role}
Group=vl-{role}
WorkingDirectory=/opt/video-lesson/current
EnvironmentFile=/etc/video-lesson/{role}.env
ExecStart={command}
UMask=0077
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
ReadWritePaths=/var/lib/video-lesson/{role}
Restart={'no' if oneshot else 'on-failure'}
TimeoutStopSec=60
'''
        if role == 'worker':
            unit += 'CPUQuota=200%\nMemoryMax=2G\n'
        if not state['qualification'] and role in ('api','gateway','worker','janitor'):
            unit += 'LoadCredential=storage-access:'+policy['storage_files'][role]['access']+'\n'
            unit += 'LoadCredential=storage-secret:'+policy['storage_files'][role]['secret']+'\n'
        if args.qualification_only:
            unit += 'IPAddressDeny=any\nIPAddressAllow=localhost\n'
        if not oneshot:
            unit += '[Install]\nWantedBy=multi-user.target\n'
        write('/etc/systemd/system/video-lesson-' + role + '.service', unit, 0o644)
    write('/etc/systemd/system/video-lesson-janitor.timer', '[Unit]\nDescription=Video Lesson retention timer\n[Timer]\nOnBootSec=15min\nOnUnitActiveSec=15min\nUnit=video-lesson-janitor.service\n[Install]\nWantedBy=timers.target\n', 0o644)
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'video-lesson-redis'])
    run(['systemctl', 'start', 'video-lesson-migrate'])
    for role in ('api', 'gateway', 'dispatcher', 'worker'):
        run(['systemctl', 'enable', '--now', 'video-lesson-' + role])
    run(['systemctl', 'enable', '--now', 'video-lesson-janitor.timer'])
    # Handoff is root-only, not printed to terminal or copied into public evidence.
    connection = dict(endpoint=policy.get('service_origin','https://localhost:18443'), gateway=policy['gateway_origin'],
                      keyid='installation-1', key=state['rpc'], subjectkey=state['subject'], tenant=state['tenant'])
    write(ETC / 'moodle-connection.json', json.dumps(connection, indent=2))
    for port in (18090, 18091):
        for attempt in range(20):
            try:
                result = json.load(urllib.request.urlopen(f'http://127.0.0.1:{port}/health', timeout=3))
                if result.get('ready') and result.get('version') == version:
                    break
            except Exception:
                time.sleep(1)
        else:
            raise RuntimeError('Dependency health failed; inspect sanitized journal, rerun after correction')
    print('SERVICE_VERSION='+version+'\nDEPENDENCY_HEALTH=PASS\nSTORAGE_QUALIFICATION=NOT_TESTED')
    print('MOODLE_CONNECTION_FILE=/etc/video-lesson/moodle-connection.json (root-only; configure trusted TLS before use)')

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        # No subprocess command/environment/SQL traceback, which could contain secrets.
        print('INSTALL_FAILED=' + (str(exc) if isinstance(exc, RuntimeError) else type(exc).__name__), file=sys.stderr)
        sys.exit(1)
