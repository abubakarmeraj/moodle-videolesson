# Deployment configuration

See [configuration](../docs/configuration.md) for Moodle/site policy ownership and
[storage](../docs/storage.md) for object-store credentials/CORS.
`operator-policy.json.example` has only fake/example values. The completed policy,
generated role environments and Moodle handoff belong outside this repository.

Compose uses VIDEO_CONFIG; native uses /etc/video-lesson. Secrets must be protected
and independently generated for each installation. No storage provisioning token
is needed by Moodle. Preserve tenant/reference authority during matched recovery.
