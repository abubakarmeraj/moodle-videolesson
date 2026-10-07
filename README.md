# CodeFortex Video Lesson

A Moodle video lesson/activity plugin plus a self-hosted processing service.
Teachers upload directly to private object storage; students watch authorized
adaptive video in Moodle, with course-controlled progress and completion.

**Release candidate:** plugin **3.1.0-rc1** (2026100701), Video Service
**0.5.0-rc1**. This is not a stable release. No CodeFortex-hosted account is needed.

## Quick start

1. Install `mod_videolesson-3.1.0-rc1.zip` through Moodle's plugin installer.
2. Install the separate Video Service using [Docker Compose](docs/docker.md),
   with private S3-compatible storage and trusted HTTPS endpoints.
3. Place the generated protected connection handoff on the Moodle server and
   configure its server-local path under **Site administration → Plugins →
   Activity modules → Video Service configuration**.
4. Click **Test Connection**. Require compatible service, storage, Redis and worker readiness.
5. Add a **Video Lesson**, save and display, then upload a short test video.

The Moodle ZIP **does not install Linux services**. The Video Service is required,
on the same server or another server. Compose is the recommended installation
path; [native/systemd](docs/native-linux.md) is an advanced clean-host option.
Storage accounts/buckets, DNS and HTTPS are operator prerequisites, not automatically provisioned.

See the [short quick start](docs/quick-start.md), [requirements](docs/installation.md),
[configuration](docs/configuration.md), [storage contract](docs/storage.md),
[upgrading](docs/upgrading.md), and [known qualification limits](docs/known-limitations.md).
Cloudflare R2 and generic S3-compatible storage are supported; MinIO was used for
qualification, not bundled. Local-filesystem storage is not implemented.

## Features

- Private direct multipart/resumable uploads, independent upload limits and tenant quota.
- FFmpeg HLS processing, no-upscale administrator-owned rendition policy, 4-second default segments.
- Bundled player libraries, adaptive/manual quality, speed controls and renewable authorization.
- Moodle-owned access, watch progress and completion; safe reusable references and relinking.
- Durable processing status, recycle/recovery and retention cleanup.
- Signed service setup/readiness checks, role-separated worker/dispatcher/gateway/janitor.

## Project

`plugin/mod_videolesson/` is the Moodle component; `service/video-service/` is the
separate service. `deploy/` supplies Compose/native tooling; `tests/` and `scripts/`
are developer checks. See [architecture](docs/architecture.md),
[contributing](CONTRIBUTING.md), [security](SECURITY.md) and [changelog](CHANGELOG.md).

Project-owned code is **GPL-3.0-or-later**; original CodeFortex artwork uses the
same license. Upstream copyrights and dependency licenses remain intact. See
[LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) for development,
tests, focused pull requests and DCO/Signed-off-by guidance. Follow our
[Code of Conduct](CODE_OF_CONDUCT.md).

## Issues

[Bugs, feature requests and installation questions](https://github.com/abubakarmeraj/moodle-videolesson/issues/new/choose)
are welcome. Use the templates and share only sanitized information.

## Security

Read [SECURITY.md](SECURITY.md) for the current private reporting contact.
**Do not report vulnerabilities through public Issues, Discussions or PRs.**

## License

Project-owned code and original artwork are **GPL-3.0-or-later**. See
[LICENSE](LICENSE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
Third-party code and community-policy material retain their respective terms.
