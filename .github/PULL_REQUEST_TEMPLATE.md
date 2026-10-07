## Change

Explain the user problem, focused change and linked issue (if any).
Do not disclose vulnerabilities here; use SECURITY.md's private channel.

## Tests and boundaries

List checks run, results and anything not tested. Distinguish offline checks from
real upload/playback qualification. Remove credentials, signing keys, S3 secrets,
DB/Redis passwords, cookies, presigned URLs and private media from evidence.

## Checklist

- [ ] This is a focused change without unrelated refactors or dependency upgrades.
- [ ] Tests are added/updated where relevant; affected checks pass.
- [ ] User-facing documentation/changelog is updated where relevant.
- [ ] No secrets, private infrastructure or customer media are included.
- [ ] Private storage, authorization and reference-safe cleanup are preserved.
- [ ] Third-party additions include license/provenance information and notices.
- [ ] Generated AMD output/source maps are updated if AMD source changed.
- [ ] I have the right to submit this contribution under compatible licensing.
- [ ] Each contribution commit has my Signed-off-by under the DCO described in CONTRIBUTING.md.
