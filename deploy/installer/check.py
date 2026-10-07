#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Protected local readiness contract; never prints credentials or raw DB exceptions."""
import importlib.util,json,os,sys,urllib.request,hashlib,hmac,secrets,time
from pathlib import Path
def main():
    state=json.loads(Path('/etc/video-lesson/installation.json').read_text())
    spec=importlib.util.spec_from_file_location('installer',Path(__file__).with_name('install.py'))
    installer=importlib.util.module_from_spec(spec);spec.loader.exec_module(installer)
    os.environ.update(installer.role_env(state,'api'))
    sys.path.insert(0,'/opt/video-lesson/current')
    from service import config
    from service.db import transaction
    from service.hardening import redis_client
    with transaction() as c:
        c.execute('SELECT version FROM schema_version ORDER BY version')
        versions=[r['version'] for r in c.fetchall()]
    version=config.VERSION
    compatibility='compatible' if version=='0.5.0-rc1' else 'too_old_or_new_untested'
    if versions!=[1,2,3,4,5]:compatibility='migration_incomplete_or_untested'
    health={str(p):json.load(urllib.request.urlopen(f'http://127.0.0.1:{p}/health',timeout=5)).get('ready',False) for p in (18090,18091)}
    redis=redis_client()
    heartbeat=bool(redis.get(config.REDIS_NAMESPACE+':staging'))
    body=json.dumps({'tenant':state['tenant'],'op':'capabilities'},separators=(',',':')).encode()
    timestamp=str(int(time.time()));nonce=secrets.token_hex(32)
    canonical='\n'.join(['POST','/v1/rpc','installation-1',timestamp,nonce,hashlib.sha256(body).hexdigest()])
    signature=hmac.new(state['rpc'].encode(),canonical.encode(),hashlib.sha256).hexdigest()
    request=urllib.request.Request('http://127.0.0.1:18090/v1/rpc',data=body,headers={'Content-Type':'application/json','X-CF-Key':'installation-1','X-CF-Time':timestamp,'X-CF-Nonce':nonce,'X-CF-Signature':signature})
    capabilities=json.load(urllib.request.urlopen(request,timeout=20))['data']
    good=compatibility=='compatible' and all(health.values()) and redis.ping() and heartbeat
    ready=good and capabilities.get('protocol_version')==1 and all(capabilities.get(k) is True for k in ('schema_ready','raw_reachable','processed_reachable','redis_ready','worker_capacity_ready'))
    print(json.dumps({'service_version':version,'schema_versions':versions,'compatibility':compatibility,
       'dependency_health':health,'capacity_present':heartbeat,'control_plane_ready':bool(good),
       'authenticated_capabilities':capabilities,'setup_ready':bool(ready),
       'media_ready':'not_qualified','credential_handoff':'/etc/video-lesson/moodle-connection.json'},indent=2))
    return 0 if good else 1
if __name__=='__main__':
    try:sys.exit(main())
    except Exception:
        print('{"control_plane_ready":false,"error":"local_dependency_or_configuration_failed"}')
        sys.exit(1)
