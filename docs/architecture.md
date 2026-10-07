# Architecture

Two deployable components: one Moodle activity and one separate media service.
The Video storage administration page belongs to the same Moodle plugin.

```text
Teacher browser → Moodle control → signed Video API
Teacher browser → exact-part presigned PUT → private raw bucket
Service MariaDB → dispatcher → Redis → leased FFprobe/FFmpeg worker
Worker → validated HLS/poster/thumbnails → private processed bucket
Student browser → authorized gateway → HLS/media
Student watch events → Moodle session validation → progress/completion
```

Moodle owns enrolment, capabilities, activity references, library/relink policy
and watch credit. The service owns media UUID/revision, durable uploads/jobs,
leases/fencing, reference reconciliation, quota reservations and deletion state.
Redis supplies transport, replay/rate counters and expiring capacity heartbeat;
MariaDB, not Redis, is the authoritative lifecycle/accounting store.

Provider calls bind tenant, operation, timestamp, nonce and signature. Playback
grants are short-lived and renewable only through current Moodle access checks.
Profile identity is not required for ordinary processing; detailed learning
telemetry remains Moodle-owned. Worker publication is fail-closed after output
validation, and stale leases cannot publish mixed versions.

One video job, bounded codec threads and sequential renditions are the initial
policy. Encoding/segment limits are snapshotted per job; never upscale. Shared
references protect media when one activity is removed. Raw one-day cleanup and
processed recycle recovery are distinct; physical bytes stay charged until verified
deletion. Backups retain references, not video objects. A clone must not become
a second competing writable tenant authority.

Roles are API, gateway, dispatcher, worker, janitor and migrator with separate
secret/DB/Redis/storage permissions. Compose/native orchestration is separate from
the plugin ZIP. There is no Moodle-PHP FFmpeg backend or public player CDN requirement.
