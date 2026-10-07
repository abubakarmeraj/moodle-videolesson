# Contributing

Bug reports, installation questions, documentation and focused contributions are
welcome. Follow [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Vulnerabilities go privately
to [SECURITY.md](SECURITY.md)'s contact, never public Issues, Discussions or PRs.

## Development setup

Use disposable Linux with Python 3.12, PHP 8.3, Node 24/npm 11 and independent
Moodle 5.2 for plugin development. Never use customer/production sites, credentials
or media as fixtures. Pillow 12.3.0 is developer-only for artwork generation.

~~~sh
python3 -m venv .venv
.venv/bin/python -m pip install --require-hashes -r service/video-service/requirements.txt
npm ci --prefix scripts/amd --ignore-scripts --no-audit --no-fund
.venv/bin/python -B scripts/check_release.py
~~~

PHP and Node must be on PATH. Keep generated configuration, virtual environments,
node_modules, media, DB state and .lab out of Git. See [installation](docs/installation.md).

## Repository layout and development lanes

- plugin/mod_videolesson/: Moodle PHP, capabilities, forms, AMD and player integration.
- service/video-service/: Python API, gateway, dispatcher, worker, janitor and migrations.
- deploy/: Compose/native installation and safe example configuration.
- tests/ and scripts/: public offline checks and build tooling.
- docs/: installation, storage, security and operator/developer documentation.

For Moodle changes, use Moodle DML/XMLDB, capability and sesskey APIs. Preserve
course/user authorization, trusted references and server-authoritative completion.
Install a candidate ZIP on disposable Moodle and test the affected normal UI.
The test_moodle_setup.php fixture takes an explicit disposable Moodle config path;
it is not permission to test against production.

For service changes, use the hash-locked dependencies above. Preserve scoped
credentials, RPC authentication/replay protection, fencing, quotas, reference-safe
cleanup and private media. Document protocol/schema compatibility and migration
impact; do not synchronize versions without a reason. See [architecture](docs/architecture.md).

For Docker development, follow [docs/docker.md](docs/docker.md): use protected
operator policy, separate private test raw/processed buckets, trusted HTTPS and
exact-origin CORS. MinIO is independent test storage, not bundled. Do not print
resolved Compose configuration or disable TLS verification. The
[native installer](docs/native-linux.md) targets supported independent clean hosts.

## Tests and generated AMD

~~~sh
.venv/bin/python -B -m unittest discover -s tests -v
find plugin/mod_videolesson tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/test_compatibility.php
node tests/test_upload_urls.cjs
node scripts/build_upload_amd.cjs /tmp/video-amd-check
cmp /tmp/video-amd-check/upload.min.js plugin/mod_videolesson/amd/build/upload.min.js
cmp /tmp/video-amd-check/upload.min.js.map plugin/mod_videolesson/amd/build/upload.min.js.map
.venv/bin/python -B scripts/check_release.py
~~~

The fast checker covers offline Python tests, PHP lint, JS syntax, XML, setup
classifications, uploader URL negatives, AMD reproduction and public manifests/notices.
If changing amd/src/upload.js, update its generated minified file and source map
with the pinned builder and rerun affected checks. Do not rewrite unrelated assets.
Maintainers refresh source manifests only after reviewing intentional changes
(scripts/public_release.py snapshot); never bypass an integrity mismatch.
PHP CodeSniffer is not mandatory until a supported configuration is provided.

For behavior changes, test the affected real upload/playback or lifecycle in your
independent lab and describe what passed. Offline/readiness tests alone do not prove
browser CORS, transcoding, playback or completion. Use synthetic/public-domain media
and document untested boundaries. Documentation changes need no unrelated stress matrix.

## Issues, features and pull requests

Use the [issue templates](https://github.com/abubakarmeraj/moodle-videolesson/issues/new/choose).
Include versions, installation/storage/browser details, expected/actual behavior,
steps and sanitized logs. Never post signing/S3/DB/Redis credentials, passwords,
cookies, presigned URLs, private media or vulnerability details.

For features, explain the user problem, behavior and security/storage impact before
a large implementation. Search existing issues first.

Fork the repository, make a focused branch and open a PR against main. Describe
the change and tests; link any issue. Keep unrelated refactors, formatting,
dependency upgrades and generated changes out of the PR. Update user-facing
documentation/changelog for behavior changes. New third-party dependencies/assets
need provenance, license and notices; preserve upstream declarations.

## Licensing and lightweight DCO

Project-owned contributed code must be compatible with GPL-3.0-or-later.
Retain authorship, license headers and third-party attribution. Submit only material
you have the right to contribute. No CLA is required.

We use [Developer Certificate of Origin 1.1](https://developercertificate.org/).
Adding Signed-off-by certifies its origin/right-to-contribute terms, not a copyright
transfer or a new license for upstream material.

~~~sh
git commit -s -m "Describe the focused change"
~~~

Use your own name and reachable email (GitHub noreply is acceptable). Your sign-off
becomes public Git history. Sign off each contribution commit; never invent another
person's sign-off. Maintainers review sign-offs manually initially; no mandatory
DCO bot or CLA service is enabled.
