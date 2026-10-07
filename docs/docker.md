# Docker Compose installation

Run on Linux with Docker Engine, Compose v2 and Python 3.12. Extract
`codefortex-video-service-0.5.0-rc1.tar.gz`; commands below run from its
`video-lesson/` root. No Moodle, MinIO or TLS-certificate service is bundled.

## Prepare

Provision separate private raw and processed buckets and restricted credentials
for API/gateway/worker/janitor. Set exact Moodle, API and gateway HTTPS origins.
Copy `deploy/operator-policy.json.example` to a protected directory and replace
the **example** values with operator-owned endpoints and secret-file paths.
Never commit this completed JSON or print generated environment files.

```sh
sudo python3 deploy/docker/prepare.py --directory /etc/video-lesson-compose \
  --config /protected/operator-policy.json
```

Preparation verifies the shipped service manifest and generates independent
tenant/RPC/grant/subject/DB/Redis identities. It refuses to overwrite existing
configuration; retain and reuse it. Role environment files contain secrets in
a root-only parent. Individually mounted DB/ACL files must never be made public.

## Start

```sh
sudo VIDEO_CONFIG=/etc/video-lesson-compose docker compose \
  -f deploy/docker/compose.yaml -f deploy/docker/storage-network.yaml up -d --build
```

The storage-network overlay enables outbound access to operator-configured HTTPS
storage, as required for media. Enforce host/firewall egress policy as appropriate.
It does **not** publish MariaDB/Redis ports. Without the overlay, the base internal
network is deliberately dependency-only and cannot serve remote object storage.
Worker initially has 2 CPUs/2 GiB memory, sequential renditions and one job;
size private staging and limits for the host before accepting larger uploads.

An administrator-managed trusted TLS reverse proxy must map API and gateway to
loopback **28090** and **28091** respectively. The included fixed-upstream proxy
is HTTP on loopback, not public TLS. Preserve media paths/authorization and do not
log RPC bodies, bearer media tokens or presigned URLs. Storage TLS/CORS is separate;
see [storage](storage.md). An optional private storage CA requires an operator
read-only CA mount and matching `STORAGE_CA_BUNDLE`, not disabled verification.

## Connect Moodle

Securely install `/etc/video-lesson-compose/moodle-connection.json` on the Moodle
host outside webroot. For a PHP group named www-data:

```sh
sudo install -d -m 0750 -o root -g www-data /etc/moodle-video
sudo install -m 0640 -o root -g www-data /secure-transfer/moodle-connection.json \
  /etc/moodle-video/connection.json
```

Save `/etc/moodle-video/connection.json` on the Video Service configuration page,
then Test Connection. The file contains six fields: endpoint, gateway, keyid,
key, subjectkey, tenant. Do not copy it into browser URLs, issue reports or source.
The PHP host must resolve/trust/reach the API and gateway, and the browser must
resolve/trust/reach storage and gateway.

Rerun `up -d` with the same protected configuration for startup. Do not run `down -v`
as routine uninstall: it destroys the service database. See [upgrading](upgrading.md)
and [uninstall](uninstall.md). Failed first-time dependency setup can be retried
without regenerating tenant/credentials.
