# Contributing

Use independent synthetic fixtures and no production credentials/customer media.
Code changes are GPL-3.0-or-later; retain upstream licenses and attribution.
Do not add a contributor agreement or relicensing promise without owner approval.

Layout: plugin/mod_videolesson, service/video-service, deploy, tests, scripts and docs.
Use Python3.12 with `service/video-service/requirements.txt` (hash-locked dependencies),
Node24/npm11 for the pinned uploader build, PHP8.3 and a development Moodle5.2 install.
Pillow12.3.0 is a developer-only requirement when regenerating original artwork.

```sh
python3 -m venv .venv
.venv/bin/pip install -r service/video-service/requirements.txt
npm ci --prefix scripts/amd --ignore-scripts --no-audit --no-fund
node scripts/build_upload_amd.cjs /tmp/video-amd-check
.venv/bin/python -B scripts/check_release.py
```

Fast checks run Python logic/config/readiness tests, PHP lint, JS/XML checks,
compiled upload URL negatives, artwork and license/manifest validation. PHP must
be on PATH; PHP CodeSniffer is not bundled. Moodle-native setup tests may use
`tests/test_moodle_setup.php <path-to-moodle-config>` against a disposable site.
Never aim these tests at a hosted/customer Moodle.

For Compose development use the documented protected policy, distinct private
test buckets, trusted HTTPS and exact-origin CORS. Keep `.lab/`, `.venv/`, node_modules,
secrets/media/DB state ignored. Do not print resolved Compose secrets.

Follow Moodle DML/XMLDB/capability/sesskey conventions, scoped AMD source/build/map
sequence and Python explicit configuration. Submit focused patches with tests,
sanitized evidence and behavior/migration notes. Do not modify upstream player
assets without verifying their version/source/notices. Public contribution/issue
endpoints will be chosen when the repository is actually published.
