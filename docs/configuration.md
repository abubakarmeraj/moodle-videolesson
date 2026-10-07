# Configuration and ownership

Teachers configure activity fields and permitted video references, not storage,
encoding or quota. Site administrators configure Video Lesson policies. Operators
configure the separately deployed service and protect its secrets.

| Moodle setting | Default and bounds | Effect |
|---|---|---|
| Maximum video upload size (`maxuploadmib`) | 512 MiB; numeric decimals up to 1 TiB | Exact integer-byte per-upload snapshot; not tenant quota or worker disk |
| Storage quota (`storagequotamib`) | 0 = unlimited policy | Independent usage/reservation ledger; recycle stays charged until deletion |
| Recovery period (`recoverydays`) | 7 days; 0/1/3/7/14/30 | Processed recycle recovery; 0 = next eligible successful janitor run |
| Encoding profile (`encodingprofile`) | standard; economy/standard/full | 480 / 480+720 / 480+720+1080, never upscale |
| HLS segment duration (`hlssegmentseconds`) | 4 seconds; 2/4/6 | Future job snapshot, not rewriting ready media |
| Raw source retention (`rawretentiondays`) | 1 day cap | Verified completed upload cleanup, subject to safe references/holds/work |

**Video storage** is an admin page within the same `mod_videolesson` plugin, not a
second plugin. Video Service configuration accepts a protected local handoff path
and exposes Test Connection, not permanent object-storage keys to teachers.

Service defaults: source ceiling **256 GiB**, configurable to **1 TiB**; upload
session **72 hours**, range **1–7 days**; supported duration **6 hours** (21600
seconds), operator range **60–21600 seconds**; FFmpeg command timeout **48 hours**,
range **30 minutes–7 days**. These are validation limits, not endurance claims.
Long-duration and multi-GiB stress remain unqualified.

Initially keep `VIDEO_WORKER_CONCURRENCY=1`, `VIDEO_FFMPEG_THREADS=2` (1–16),
sequential renditions and deployment CPU/memory headroom. Threads are not a total
CPU quota. Use private disk staging, not a giant RAM tmpfs. Capacity heartbeat
must be fresh and sufficient before upload admission; it is not a disk reservation
against unrelated processes. Review crash leftovers using lease-aware procedures.

Operator settings include tenant, exact HTTPS origins/hosts, backend/endpoint,
region/addressing, distinct buckets, role DB/Redis connections and staging.
Secrets include RPC/grant/subject keys, role storage keys, DB and Redis passwords.
`service/video-service/deployment/environment.example` lists safe placeholders.
Only values using the service's `setting()` helper support `_FILE` indirection;
do not assume every environment variable does. Preserve the subject key when
rotating RPC keys. Never clone one writable tenant authority into competing sites.
