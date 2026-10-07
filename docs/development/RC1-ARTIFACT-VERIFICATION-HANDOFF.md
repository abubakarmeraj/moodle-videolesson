# RC1 final artifact verification handoff

This is **one tiny verification**, not another broad qualification phase and not
permission to publish. Verify the prepared local source commit and output checksums
reported by Local. Do not add features or connect to an existing hosted LMS.

## Candidate identity

Plugin2026100701 / CodeFortex3.1.0-rc1; service0.5.0-rc1
(PEP440 package spelling0.5.0rc1), protocol1, migrations001–005.
Project-owned code/original artwork GPL-3.0-or-later. Existing dependency licenses
remain unchanged. No migration006 or player/storage/worker redesign.

Authoritative `docs/release/IDENTITY.json`, PRODUCT-MANIFEST.json,
SERVICE-MANIFEST.json and PUBLIC-SOURCE-MANIFEST.json supply exact source hashes.
Use `git rev-parse HEAD` and ensure a clean checkout; there is one initial local
public-source commit, no remote/tag/publication. The output `.lab/work/public-rc1/`
RELEASE-MANIFEST.json records the full commit and artifact hashes without a
self-referential committed hash. SHA256SUMS covers both archives.

## Independent rebuild

From the exact clean commit using Python3.12.3/zlib1.3 and Node24.17.0/npm11.13.0:

```sh
npm ci --prefix scripts/amd --ignore-scripts --no-audit --no-fund
node scripts/build_upload_amd.cjs /private/verify/amd
python3 -B scripts/public_release.py check
python3 -B scripts/public_release.py build --output /private/verify/build-a
python3 -B scripts/public_release.py build --output /private/verify/build-b
```

Compare archives and output manifests/checksums byte-for-byte. ZIP root videolesson/;
service tar root video-lesson/, including the deployment/Compose files, public
installation docs and complete license notices. No extra deployment bundle is needed.
Both embedded release manifests list every other archive member/hash/size. Verify
safe relative paths, normalized ownership/modes and absence of special nodes.

## Bounded smoke

1. Verify exact public commit/tree and rebuild/hash equality.
2. Fresh install the plugin ZIP on a disposable Moodle5.2.2; require2026100701.
3. Extract/start the service archive with the documented protected policy and
   generic HTTPS S3/MinIO, separate private buckets and exact-origin CORS.
4. Test Connection: require0.5.0-rc1, protocol1, schema001–005, storage/Redis/worker ready.
5. One short synthetic upload → processing → private HLS → decoded playback → completion.
6. Verify the new icon/placeholder artwork renders from packaged files.
7. Verify root/component GPL text and upstream notice pack are present.
8. Scan artifacts/sanitized evidence for private configuration, credentials,
   cookies, presigned URLs and original provenance-blocked artwork.
9. Approve/reject the exact artifacts; no silent source patch during verification.

The release-only installer guards now use the shipped SERVICE-MANIFEST/IDENTITY
rather than historical private phase manifests. Compose uses VIDEO_CONFIG and
the documented storage-network overlay; its DB/Redis still have no published ports.
Native versions/guards changed only for RC1; same clean-host refusal and protected
identity preservation remain. Test the advertised installation path, not old lab
harness defaults. From prior development tuple the Moodle savepoint is metadata-only;
service schema remains001–005. Keep matched recovery if testing an upgrade.

Normal short-media qualification already passed Chromium/Firefox/WebKit. WSL2
Ubuntu qualification is accepted; ordinary real-server, six-hour, multi-GiB and
full-disk endurance remain limitations, not requirements to rerun now. Do not repeat
unaffected quota/replacement/resume/backup/failure matrices absent a concrete defect.

Both human licensing/artwork decisions have been applied. Owner still must choose
the private security contact in SECURITY.md before actual publication. Return the
artifact decision to the owner/orchestrator; create no remote, public tag/release,
published image or Moodle-directory submission.
