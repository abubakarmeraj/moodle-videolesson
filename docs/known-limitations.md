# Known qualification limits

- First public source candidate is RC1, not a stable release.
- Ubuntu24.04 under WSL2 is qualified; an ordinary Ubuntu VM/server run is pending.
- Fresh Moodle5.2.2/PHP8.3.6 and short synthetic-media lifecycle are qualified.
- Chromium completed generic-S3 multipart upload; Firefox/WebKit decoded playback
  and seeking passed, but their multipart upload was not separately repeated.
- Six-hour endurance, multi-GiB stress and full-disk endurance are not qualified.
- Generic S3 compatibility depends on the actual provider's TLS/CORS/metadata behavior.
- Local-filesystem storage is absent; private S3-compatible storage is required.
- A separate service is required; the Moodle ZIP does not provision Linux services.
- Native installer requires a clean dedicated host and refuses existing unowned DBs;
  automatic native changed-source upgrades are not implemented.
- TLS routing, object-storage provisioning, monitoring/backups and capacity sizing
  are operator responsibilities. MinIO/server images are not bundled.
- Public vulnerability-reporting contact still needs owner selection before publication.

These are honest boundaries, not newly discovered defects. Source preparation
does not replace the final short RC1 artifact verification or publication approval.
