# Operational contract

Video Service0.5.0-rc1; protocol1, migrations001–005. Roles remain separate:
api, gateway, dispatcher, worker, janitor and migrate. Compose/native setup is
documented in the distribution's docs/docker.md and docs/native-linux.md.

Keep role DB/Redis/storage permissions scoped. API owns signed control/authorization;
gateway reads processed media; worker reads raw/writes processed; janitor performs
bounded cleanup; migrator alone performs DDL. Never log secrets, cookies, grants,
raw RPC bodies or full presigned URLs. Preserve the stable Moodle subject key during
RPC rotation. Use authenticated capabilities, not health alone, for setup readiness.

Uploads go directly from browser to private storage, with adaptive multipart sizing
and at most8192 planned parts. Quota reservations and individual-file bounds are
independent. Recycle bytes remain charged until verified deletion; references,
holds and in-flight work protect shared media. One-day raw eligibility starts
after verified completion, not before safety conditions. Zero recovery days means
next eligible successful janitor cleanup, not immediate synchronous deletion.
The supplied Compose janitor and native timer run every15minutes.

`python -m service.storage_admin status` reads accounting. Review dry-run
`reconcile --limit 25` before using `--apply`. Cleanup is bounded to a known UUID
and defaults dry-run. Do not use bucket-wide deletes or direct DB repair.

Start with one video job/worker, two codec threads, sequential renditions and
host CPU/memory limits leaving Moodle headroom. Use private disk-backed staging.
Capacity heartbeat is short-lived and not a reservation against other processes.
Review crash leftovers with lease-aware procedures. Limits: source256GiB default,
1TiB maximum; duration6h maximum; upload72h default,1–7d; command timeout48h default,
7d maximum. Limits do not imply long-media endurance qualification. Future-job
segment/rendition policy is snapshotted; existing ready versions are not rewritten.
