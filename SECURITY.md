# Security

## Private reporting

**OWNER ACTION BEFORE PUBLICATION:** choose and verify a private security-reporting
email or private advisory URL, then replace this placeholder. No contact is invented.
Do not file public issues containing exploit details, credentials or customer data.

## Supported line

3.1.0-rc1/plugin plus0.5.0-rc1/service is the prepared supported candidate tuple.
There is not yet a stable release or guaranteed maintenance window.

## Model

Moodle authorizes users/activity access and completion. Signed RPC, replay bounds,
short-lived exact-object/part upload authorization and renewable media grants
bind the service to current Moodle authority. Buckets remain private. Teacher
inputs do not choose storage endpoints, permanent credentials or encoding policy.

Keep API/gateway behind trusted HTTPS, DB/Redis off public networks, roles and
secrets scoped, TLS verification enabled and worker staging private. Never publish
connection handoffs, secret keys, cookies, full presigned URLs or raw RPC bodies.
Use signed Test Connection plus a short media test before opening a deployment.
Maintain matched database/config/object backups and patch runtime dependencies.
See storage/configuration/known-limitations docs for operational boundaries.
