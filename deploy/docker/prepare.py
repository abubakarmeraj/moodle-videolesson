#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Generate protected Compose configuration from explicit operator policy."""
import argparse, importlib.util, json, os, secrets
from pathlib import Path
p=argparse.ArgumentParser()
p.add_argument('--directory',type=Path,required=True)
p.add_argument('--qualification-only',action='store_true')
p.add_argument('--config',type=Path,help='Protected operator storage/origin policy JSON')
p.add_argument('--product-manifest',type=Path,help='Explicit reviewed successor product manifest')
p.add_argument('--manifest-sha256',help='Required expected digest with --product-manifest')
args=p.parse_args()
if os.geteuid()!=0: raise SystemExit('Run as root inside disposable Linux')
dest=args.directory.resolve()
if dest.exists(): raise SystemExit('Configuration already exists: reuse it; no credentials overwritten')
spec=importlib.util.spec_from_file_location('native',Path(__file__).parents[1]/'installer/install.py')
native=importlib.util.module_from_spec(spec);spec.loader.exec_module(native)
if not args.qualification_only and not args.config: raise SystemExit('Provide --config or explicit --qualification-only')
policy=json.loads(args.config.read_text()) if args.config else None
s=dict(qualification=args.qualification_only,tenant='vl_'+secrets.token_hex(12),rpc=secrets.token_hex(32),
 grant=secrets.token_hex(32),subject=secrets.token_hex(32),
 db={r:secrets.token_hex(32) for r in native.ROLES},redis={r:secrets.token_hex(32) for r in native.ROLES},
 policy=dict(endpoint='https://'+'0'*32+'.r2.cloudflarestorage.com',
 raw_bucket='qualification-compose-raw',processed_bucket='qualification-compose-processed',
 moodle_origin='https://localhost:28445',gateway_origin='https://localhost:28444'))
if policy: s['policy']=policy
project=Path(__file__).parents[2]
if args.product_manifest and not args.manifest_sha256:
 raise SystemExit('Expected digest is required with an explicit manifest')
spec=importlib.util.spec_from_file_location('release_manifest',project/'deploy/installer/manifest.py')
guard=importlib.util.module_from_spec(spec);spec.loader.exec_module(guard)
guard.verify(project,args.product_manifest,args.manifest_sha256)
dest.mkdir(mode=0o700,parents=True)
native.write(dest/'installation.json',json.dumps(s))
native.write(dest/'dbroot',secrets.token_hex(32),0o644)
sql=''
acl='user default off\n'
for role in native.ROLES:
 env=native.role_env(s,role)
 env['DB_HOST']='db';env['TMPDIR']='/var/lib/video-lesson/worker' if role=='worker' else '/tmp'
 env['REDIS_URL']=env['REDIS_URL'].replace('@127.0.0.1:16379','@redis:6379')
 if policy:
  env['ALLOWED_HOSTS']=policy.get('allowed_hosts','localhost,127.0.0.1')
  for key in ('R2_ACCESS_KEY','R2_SECRET_KEY'):
   if key+'_FILE' in env: env[key]=Path(env.pop(key+'_FILE')).read_text().strip()
  if policy.get('storage_ca_bundle'): env['STORAGE_CA_BUNDLE']='/run/video-ca/ca.crt'
 native.write(dest/(role+'.env'),''.join(k+'='+v+'\n' for k,v in env.items()))
 grant='ALL PRIVILEGES' if role=='migrate' else 'SELECT,INSERT,UPDATE,DELETE'
 sql+=f"CREATE USER 'vl_{role}'@'%' IDENTIFIED BY '{s['db'][role]}';GRANT {grant} ON video_lesson.* TO 'vl_{role}'@'%';\n"
 acl+=f"user vl_{role} on >{s['redis'][role]} ~video-lesson:{s['tenant']}:* -@all {native.COMMANDS[role]}\n"
native.write(dest/'init.sql',sql,0o644)
# Files mounted individually into isolated containers. Directory stays root-only.
native.write(dest/'users.acl',acl,0o644)
native.write(dest/'redis.conf','bind 0.0.0.0\nprotected-mode yes\naclfile /usr/local/etc/redis/users.acl\nsave ""\nappendonly no\n',0o644)
stage=dest/'staging';stage.mkdir(mode=0o700);os.chown(stage,10001,10001)
native.write(dest/'moodle-connection.json',json.dumps(dict(endpoint=s['policy'].get('service_origin','https://localhost:28443'),gateway=s['policy']['gateway_origin'],keyid='installation-1',key=s['rpc'],subjectkey=s['subject'],tenant=s['tenant'])))
print('CONFIG_CREATED; credentials not printed; configure HTTPS/CORS and Test Connection before upload')
