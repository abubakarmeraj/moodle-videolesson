# Troubleshooting

Start with **Test Connection**, not screenshots of secrets or `/health` alone.

| Result | Check |
|---|---|
| SERVICE_UNREACHABLE | Exact HTTPS origin, DNS/TLS trust, private routing, API role |
| AUTHENTICATION_FAILED | Correct protected six-field file, matched stable tenant/keyid/key, time synchronization |
| SERVICE_TOO_OLD / SERVICE_NEWER_UNTESTED | RC1 tuple, protocol1; do not suppress the compatibility guard |
| SCHEMA_NOT_READY | Explicit migrator and001–005; retain DB, do not repair rows manually |
| STORAGE_NOT_READY | Private bucket access, endpoint/region/addressing, role keys, TLS trust |
| NO_WORKER_CAPACITY | Worker heartbeat, private staging space, configured limits and active job |

Upload failing before PUT: inspect sanitized Moodle/service authorization errors.
PUT failing: check browser-reachable HTTPS storage, exact-origin CORS and exposed
ETag. Never paste full signed URLs into reports. Processing failure: inspect
sanitized job status, actual media codec/duration, disk and lease state. A higher
upload setting cannot overcome insufficient staging. Reconciliation is idempotent;
do not manually delete a referenced video to clear an error.

If a pinned image/FFmpeg package is unavailable, review/update/requalify it rather
than removing the pin silently. Keep old application/config state for rollback.
Report versions, status categories and sanitized logs without cookies, credentials,
student data, raw RPC payloads or bearer media URLs.
