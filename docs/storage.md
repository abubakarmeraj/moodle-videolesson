# Private S3-compatible storage

Supported modes are `r2` and `s3-compatible`; local filesystem is **not implemented**.
Cloudflare R2 retains its strict account-endpoint validation and SigV4 behavior.
Generic S3 requires an operator-configured HTTPS origin, region, path/virtual
addressing and separate private raw/processed buckets. MinIO was the qualification
implementation, not a promise that every S3 provider is certified, and not bundled.

The service's `STORAGE_ENDPOINT` must be reachable by both service and teacher
browser: it appears in short-lived exact-part presigned PUT URLs. A container-only
hostname or service-local localhost will not work for a remote browser. The API
never accepts a teacher-chosen endpoint. TLS verification stays enabled; an optional
CA bundle may trust a private CA. Browser uploads require HTTPS even if an explicit
development-only service HTTP setting is used for storage-layer tests.

## Access and CORS

- API: raw multipart creation/list/complete/abort, HEAD/verification and narrowly
  scoped raw deletion for verified abort cleanup; no processed deletion.
- Worker: raw read and processed write; gateway: processed read only.
- Janitor: scoped cleanup in assigned buckets; no bucket-wide arbitrary deletion.
- Buckets remain private. The MinIO admin console must not be publicly exposed.

Allow CORS for the **exact Moodle HTTPS origin**, PUT and actual headers
(`Content-Type` and any signed headers used by the provider), exposing **ETag**.
Browsers preflight with OPTIONS; allow the provider to answer it. Add GET/HEAD
only where the selected browser flow requires them. Do not use wildcard origins
as a substitute for testing. R2/bucket CORS and provider-level CORS differ; the
privately tested MinIO build used `MINIO_API_CORS_ALLOW_ORIGIN` because bucket CORS
configuration was unsupported. Consult the chosen version's operator documentation.

Signed Host, path and query must survive proxying unchanged. Do not rewrite a
presigned hostname after signing. Access-key identifiers may appear only as
required in SigV4; secret keys never leave the backend. Full presigned URLs are
temporary bearer credentials: do not record them in access logs or issue reports.

Storage must support multipart create/part/list/complete/abort, ETag, HEAD metadata
and exact sizes, ranged/normal GET and idempotent scoped delete. Adaptive sizing
plans at most 8192 parts; resume discovers committed parts, not browser-only state.
Readiness checks bucket access; a short real browser upload remains required to
verify CORS/network/provider behavior.
